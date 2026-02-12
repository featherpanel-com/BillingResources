<?php

/*
 * This file is part of FeatherPanel.
 *
 * Copyright (C) 2025 MythicalSystems Studios
 * Copyright (C) 2025 FeatherPanel Contributors
 * Copyright (C) 2025 Cassian Gherman (aka NaysKutzu)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published
 * by the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * See the LICENSE file or <https://www.gnu.org/licenses/>.
 */

namespace App\Addons\billingresources\Helpers;

use App\Addons\billingresources\Chat\UserResources;

/**
 * Helper for working with user resources inside the billingresources addon.
 *
 * This wraps the UserResources chat model with a small, expressive API.
 */
class ResourcesHelper
{
    /**
     * Get the current resource value for a user.
     *
     * @param int $userId User ID
     * @param string $resourceType Resource type (memory_limit, cpu_limit, etc.)
     *
     * @return int Resource value (0 if not found)
     */
    public static function getUserResource(int $userId, string $resourceType): int
    {
        return UserResources::getResource($userId, $resourceType);
    }

    /**
     * Get all resources for a user.
     *
     * @param int $userId User ID
     *
     * @return array|null User resources row or null if not found
     */
    public static function getUserResources(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        return UserResources::getByUserId($userId);
    }

    /**
     * Get user resources, always returning a complete structure.
     *
     * If the user has no resources yet, this returns default resources
     * from settings. It does NOT create a row in the database; it is purely a read helper.
     *
     * @param int $userId User ID
     *
     * @return array<string,int> User resources structure
     */
    public static function getUserResourcesOrDefault(int $userId): array
    {
        if ($userId <= 0) {
            return self::defaultResourcesStructure();
        }

        $resources = UserResources::getByUserId($userId);

        if ($resources === null) {
            // Use default resources from settings
            $defaults = SettingsHelper::getDefaultResources();
            $resources = self::defaultResourcesStructure();
            // Merge defaults into structure
            foreach ($defaults as $key => $value) {
                if (isset($resources[$key])) {
                    $resources[$key] = $value;
                }
            }
        }

        return $resources;
    }

    /**
     * Ensure a user has a resources record by creating one if missing.
     *
     * This will create a row with default resources from settings, merged with
     * any provided $defaults. If creation fails, it logs an error and returns null.
     *
     * @param int $userId User ID
     * @param array<string,int> $defaults Default resource values (optional override)
     *
     * @return array<string,int>|null The existing or newly created resources
     */
    public static function ensureUserResources(int $userId, array $defaults = []): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $existing = UserResources::getByUserId($userId);
        if ($existing !== null) {
            return $existing;
        }

        // Get default resources from settings
        $settingsDefaults = SettingsHelper::getDefaultResources();
        $base = self::defaultResourcesStructure();
        // Merge settings defaults, then user-provided defaults
        $payload = array_merge($base, $settingsDefaults, $defaults);
        $payload['user_id'] = $userId;

        if (!UserResources::create($payload)) {
            return null;
        }

        return UserResources::getByUserId($userId);
    }

    /**
     * Add resources to a user's balance.
     *
     * Checks max limits before adding. Will refuse if adding would exceed max limit.
     *
     * @param int $userId User ID
     * @param string $resourceType Resource type (memory_limit, cpu_limit, etc.)
     * @param int $amount Amount to add
     *
     * @return bool true on success, false on failure or if would exceed max limit
     */
    public static function addUserResource(int $userId, string $resourceType, int $amount): bool
    {
        if ($amount <= 0) {
            return false;
        }

        // Check if adding would exceed max limit
        $current = UserResources::getResource($userId, $resourceType);
        $newValue = $current + $amount;

        if (SettingsHelper::exceedsMaxLimit($resourceType, $newValue)) {
            return false;
        }

        return UserResources::adjustResource($userId, $resourceType, $amount);
    }

    /**
     * Remove resources from a user's balance.
     *
     * This will never allow the balance to go below zero.
     *
     * @param int $userId User ID
     * @param string $resourceType Resource type (memory_limit, cpu_limit, etc.)
     * @param int $amount Amount to remove
     *
     * @return bool true on success, false if insufficient resources or on error
     */
    public static function removeUserResource(int $userId, string $resourceType, int $amount): bool
    {
        if ($amount <= 0) {
            return false;
        }

        return UserResources::adjustResource($userId, $resourceType, -$amount);
    }

    /**
     * Set the exact resource value for a user.
     *
     * Checks max limits before setting. Will refuse if value exceeds max limit.
     *
     * @param int $userId User ID
     * @param string $resourceType Resource type (memory_limit, cpu_limit, etc.)
     * @param int $value Value to set
     *
     * @return bool true on success, false on failure or if exceeds max limit
     */
    public static function setUserResource(int $userId, string $resourceType, int $value): bool
    {
        if ($value < 0) {
            return false;
        }

        // Check if value exceeds max limit
        if (SettingsHelper::exceedsMaxLimit($resourceType, $value)) {
            return false;
        }

        $current = UserResources::getResource($userId, $resourceType);
        $delta = $value - $current;

        if ($delta === 0) {
            return true; // No change needed
        }

        return UserResources::adjustResource($userId, $resourceType, $delta);
    }

    /**
     * Update multiple resources at once.
     *
     * Checks max limits before updating. Will refuse if any value exceeds max limit.
     *
     * @param int $userId User ID
     * @param array<string,int> $resources Associative array of resource types and values
     *
     * @return bool true on success, false on failure or if any exceeds max limit
     */
    public static function updateUserResources(int $userId, array $resources): bool
    {
        if ($userId <= 0) {
            return false;
        }

        // Check all values against max limits
        foreach ($resources as $resourceType => $value) {
            if (SettingsHelper::exceedsMaxLimit($resourceType, $value)) {
                return false;
            }
        }

        return UserResources::updateByUserId($userId, $resources);
    }

    /**
     * Check if user has sufficient resources.
     *
     * @param int $userId User ID
     * @param string $resourceType Resource type (memory_limit, cpu_limit, etc.)
     * @param int $requiredAmount Required amount
     *
     * @return bool true if user has sufficient resources, false otherwise
     */
    public static function hasSufficientResource(int $userId, string $resourceType, int $requiredAmount): bool
    {
        $current = UserResources::getResource($userId, $resourceType);

        return $current >= $requiredAmount;
    }

    /**
     * Check if a resource value would exceed the max limit.
     *
     * @param string $resourceType Resource type
     * @param int $value Value to check
     *
     * @return bool True if would exceed max limit, false otherwise
     */
    public static function wouldExceedMaxLimit(string $resourceType, int $value): bool
    {
        return SettingsHelper::exceedsMaxLimit($resourceType, $value);
    }

    /**
     * Check if user has reached the max limit for a resource.
     *
     * @param int $userId User ID
     * @param string $resourceType Resource type
     *
     * @return bool True if user has reached max limit, false otherwise
     */
    public static function hasReachedMaxLimit(int $userId, string $resourceType): bool
    {
        $current = UserResources::getResource($userId, $resourceType);
        $maxLimit = SettingsHelper::getMaxLimit($resourceType);

        // 0 means unlimited
        if ($maxLimit === 0) {
            return false;
        }

        return $current >= $maxLimit;
    }

    /**
     * Get the max limit for a resource type.
     *
     * @param string $resourceType Resource type
     *
     * @return int Max limit (0 = unlimited)
     */
    public static function getMaxLimit(string $resourceType): int
    {
        return SettingsHelper::getMaxLimit($resourceType);
    }

    /**
     * Calculate used resources from server limits.
     *
     * This sums up the resource LIMITS set on servers (memory, cpu, disk, database_limit, backup_limit, allocation_limit),
     * NOT the actual usage. This is the standard way to calculate resource consumption.
     *
     * @param int $userId User ID
     * @param array|null $excludeServerIds Array of server IDs to exclude from calculation (optional)
     *
     * @return array<string,int> Used resources structure
     */
    public static function calculateUsedResourcesFromServerLimits(int $userId, ?array $excludeServerIds = null): array
    {
        if ($userId <= 0) {
            return self::defaultUsedResourcesStructure();
        }

        $servers = \App\Chat\Server::getServersByOwnerId($userId);
        $excludeIds = $excludeServerIds ?? [];

        $used = [
            'memory_limit' => 0,
            'cpu_limit' => 0,
            'disk_limit' => 0,
            'server_limit' => 0,
            'database_limit' => 0,
            'backup_limit' => 0,
            'allocation_limit' => 0,
        ];

        foreach ($servers as $server) {
            $serverId = (int) ($server['id'] ?? 0);
            // Skip excluded servers (e.g., current server being edited)
            if (!empty($excludeIds) && in_array($serverId, $excludeIds, true)) {
                continue;
            }

            // Sum up the LIMITS set on servers (not actual usage)
            $used['memory_limit'] += (int) ($server['memory'] ?? 0);
            $used['cpu_limit'] += (int) ($server['cpu'] ?? 0);
            $used['disk_limit'] += (int) ($server['disk'] ?? 0);
            $used['database_limit'] += (int) ($server['database_limit'] ?? 0);
            $used['backup_limit'] += (int) ($server['backup_limit'] ?? 0);
            $used['allocation_limit'] += (int) ($server['allocation_limit'] ?? 0);
        }

        // Server limit is just the count
        $used['server_limit'] = count($servers) - count($excludeIds);

        return $used;
    }

    /**
     * Calculate available resources for a user.
     *
     * @param int $userId User ID
     * @param array|null $excludeServerIds Array of server IDs to exclude from calculation (optional)
     *
     * @return array<string,int> Available resources structure
     */
    public static function calculateAvailableResources(int $userId, ?array $excludeServerIds = null): array
    {
        $limits = self::getUserResourcesOrDefault($userId);
        $used = self::calculateUsedResourcesFromServerLimits($userId, $excludeServerIds);

        return [
            'memory_limit' => max(0, $limits['memory_limit'] - $used['memory_limit']),
            'cpu_limit' => max(0, $limits['cpu_limit'] - $used['cpu_limit']),
            'disk_limit' => max(0, $limits['disk_limit'] - $used['disk_limit']),
            'server_limit' => max(0, $limits['server_limit'] - $used['server_limit']),
            'database_limit' => max(0, $limits['database_limit'] - $used['database_limit']),
            'backup_limit' => max(0, $limits['backup_limit'] - $used['backup_limit']),
            'allocation_limit' => max(0, $limits['allocation_limit'] - $used['allocation_limit']),
        ];
    }

    /**
     * Check if user has any resource overflow.
     *
     * @param int $userId User ID
     *
     * @return array{has_overflow: bool, overflow_details: array<string, array{used: int, limit: int}>}
     */
    public static function checkResourceOverflow(int $userId): array
    {
        $limits = self::getUserResourcesOrDefault($userId);
        $used = self::calculateUsedResourcesFromServerLimits($userId);

        $hasOverflow = false;
        $overflowDetails = [];

        foreach ($used as $resourceType => $usedValue) {
            $limit = $limits[$resourceType] ?? 0;
            if ($limit > 0 && $usedValue > $limit) {
                $hasOverflow = true;
                $overflowDetails[$resourceType] = [
                    'used' => $usedValue,
                    'limit' => $limit,
                ];
            }
        }

        return [
            'has_overflow' => $hasOverflow,
            'overflow_details' => $overflowDetails,
        ];
    }

    /**
     * Check if a specific server's resources exceed user limits.
     *
     * @param int $userId User ID
     * @param array $server Server array with resource fields (memory, cpu, disk, database_limit, backup_limit, allocation_limit)
     *
     * @return array{has_overflow: bool, overflow_details: array<string, array{server_value: int, limit: int}>}
     */
    public static function checkServerResourceOverflow(int $userId, array $server): array
    {
        $limits = self::getUserResourcesOrDefault($userId);

        $hasOverflow = false;
        $overflowDetails = [];

        $serverResources = [
            'memory_limit' => (int) ($server['memory'] ?? 0),
            'cpu_limit' => (int) ($server['cpu'] ?? 0),
            'disk_limit' => (int) ($server['disk'] ?? 0),
            'database_limit' => (int) ($server['database_limit'] ?? 0),
            'backup_limit' => (int) ($server['backup_limit'] ?? 0),
            'allocation_limit' => (int) ($server['allocation_limit'] ?? 0),
        ];

        foreach ($serverResources as $resourceType => $serverValue) {
            $limit = $limits[$resourceType] ?? 0;
            if ($limit > 0 && $serverValue > $limit) {
                $hasOverflow = true;
                $overflowDetails[$resourceType] = [
                    'server_value' => $serverValue,
                    'limit' => $limit,
                ];
            }
        }

        return [
            'has_overflow' => $hasOverflow,
            'overflow_details' => $overflowDetails,
        ];
    }

    /**
     * Build a default resources structure.
     *
     * @return array<string,int|null> Default resources structure
     */
    private static function defaultResourcesStructure(): array
    {
        return [
            'id' => null,
            'user_id' => null,
            'memory_limit' => 0,
            'cpu_limit' => 0,
            'disk_limit' => 0,
            'server_limit' => 0,
            'database_limit' => 0,
            'backup_limit' => 0,
            'allocation_limit' => 0,
        ];
    }

    /**
     * Build a default used resources structure.
     *
     * @return array<string,int> Default used resources structure
     */
    private static function defaultUsedResourcesStructure(): array
    {
        return [
            'memory_limit' => 0,
            'cpu_limit' => 0,
            'disk_limit' => 0,
            'server_limit' => 0,
            'database_limit' => 0,
            'backup_limit' => 0,
            'allocation_limit' => 0,
        ];
    }
}

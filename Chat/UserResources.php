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

namespace App\Addons\billingresources\Chat;

use App\App;
use App\Chat\User;
use App\Chat\Database;
use App\Addons\billingresources\Helpers\SettingsHelper;

/**
 * UserResources chat model for CRUD operations on the
 * featherpanel_billingresources_user_resources table, scoped to the billingresources addon.
 */
class UserResources
{
    /**
     * @var string the user resources table name
     */
    private static string $table = 'featherpanel_billingresources_user_resources';

    /**
     * Get user resources by user ID.
     *
     * @param int $userId User ID
     *
     * @return array|null User resources row or null if not found
     */
    public static function getByUserId(int $userId): ?array
    {
        if (!self::assertUserExists($userId)) {
            return null;
        }

        $pdo = Database::getPdoConnection();
        $stmt = $pdo->prepare('SELECT * FROM ' . self::$table . ' WHERE user_id = :user_id LIMIT 1');
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Ensure a user resources row exists for the given user.
     *
     * Creates with default resources from settings if missing.
     *
     * @param int $userId User ID
     *
     * @return array|null the user resources row or null on failure
     */
    public static function getOrCreateByUserId(int $userId): ?array
    {
        if (!self::assertUserExists($userId)) {
            return null;
        }

        $existing = self::getByUserId($userId);
        if ($existing !== null) {
            return $existing;
        }

        // Get default resources from settings
        $defaults = SettingsHelper::getDefaultResources();

        $pdo = Database::getPdoConnection();

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO ' . self::$table . ' (user_id, memory_limit, cpu_limit, disk_limit, server_limit, database_limit, backup_limit, allocation_limit) VALUES (:user_id, :memory_limit, :cpu_limit, :disk_limit, :server_limit, :database_limit, :backup_limit, :allocation_limit)'
            );
            $stmt->execute([
                'user_id' => $userId,
                'memory_limit' => $defaults['memory_limit'] ?? 0,
                'cpu_limit' => $defaults['cpu_limit'] ?? 0,
                'disk_limit' => $defaults['disk_limit'] ?? 0,
                'server_limit' => $defaults['server_limit'] ?? 0,
                'database_limit' => $defaults['database_limit'] ?? 0,
                'backup_limit' => $defaults['backup_limit'] ?? 0,
                'allocation_limit' => $defaults['allocation_limit'] ?? 0,
            ]);
        } catch (\PDOException $e) {
            // Duplicate key means another request created the row – fetch it.
            if ($e->getCode() !== '23000') {
                App::getInstance(true)->getLogger()->error('Failed to create user resources row: ' . $e->getMessage());

                return null;
            }
        }

        return self::getByUserId($userId);
    }

    /**
     * Get user resources by ID.
     *
     * @param int $id Resource ID
     *
     * @return array|null User resources row or null if not found
     */
    public static function getById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $pdo = Database::getPdoConnection();
        $stmt = $pdo->prepare('SELECT * FROM ' . self::$table . ' WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Create user resources.
     *
     * @param array $data Resource data
     *
     * @return int|false Resource ID or false on failure
     */
    public static function create(array $data): int | false
    {
        $required = ['user_id'];
        foreach ($required as $field) {
            if (!isset($data[$field])) {
                App::getInstance(true)->getLogger()->error("Missing required field: $field");

                return false;
            }
        }

        if (!self::assertUserExists((int) $data['user_id'])) {
            return false;
        }

        // Check if user already has resources
        $existing = self::getByUserId((int) $data['user_id']);
        if ($existing !== null) {
            App::getInstance(true)->getLogger()->error('User resources already exist for user_id: ' . $data['user_id']);

            return false;
        }

        $pdo = Database::getPdoConnection();
        $fields = array_keys($data);
        $placeholders = array_map(fn ($f) => ':' . $f, $fields);
        $sql = 'INSERT INTO ' . self::$table . ' (' . implode(',', $fields) . ') VALUES (' . implode(',', $placeholders) . ')';
        $stmt = $pdo->prepare($sql);

        if ($stmt->execute($data)) {
            return (int) $pdo->lastInsertId();
        }

        return false;
    }

    /**
     * Update user resources by user ID.
     *
     * Uses a transaction and row locking to be safe under concurrent requests.
     *
     * @param int $userId User ID
     * @param array $data Fields to update
     *
     * @return bool True on success, false on failure
     */
    public static function updateByUserId(int $userId, array $data): bool
    {
        if (!self::assertUserExists($userId)) {
            return false;
        }

        if (empty($data)) {
            App::getInstance(true)->getLogger()->error('No data to update');

            return false;
        }

        // Prevent updating primary keys
        unset($data['id'], $data['user_id']);

        // Whitelist allowed fields
        $allowedFields = [
            'memory_limit',
            'cpu_limit',
            'disk_limit',
            'server_limit',
            'database_limit',
            'backup_limit',
            'allocation_limit',
        ];

        $payload = [];
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $value = $data[$field];
                if (is_numeric($value)) {
                    $payload[$field] = (int) $value;
                } else {
                    $payload[$field] = 0;
                }
            }
        }

        if ($payload === []) {
            return false;
        }

        // Check all values against max limits before updating
        foreach ($payload as $resourceType => $value) {
            if (SettingsHelper::exceedsMaxLimit($resourceType, $value)) {
                App::getInstance(true)->getLogger()->error("Resource value exceeds max limit: $resourceType = $value");

                return false;
            }
        }

        $pdo = Database::getPdoConnection();

        try {
            $pdo->beginTransaction();

            $row = self::lockRowForUser($pdo, $userId);
            if ($row === null) {
                // Get default resources from settings and merge with payload
                $defaults = SettingsHelper::getDefaultResources();
                $payload = array_merge($defaults, $payload);
                $payload['user_id'] = $userId;
                $fields = array_keys($payload);
                $placeholders = array_map(fn ($f) => ':' . $f, $fields);
                $sql = 'INSERT INTO ' . self::$table . ' (' . implode(',', $fields) . ') VALUES (' . implode(',', $placeholders) . ')';
                $stmt = $pdo->prepare($sql);
                $stmt->execute($payload);
            } else {
                // Update existing row
                $fields = array_keys($payload);
                $setClause = implode(', ', array_map(fn ($f) => $f . ' = :' . $f, $fields));
                $sql = 'UPDATE ' . self::$table . ' SET ' . $setClause . ' WHERE id = :id';
                $payload['id'] = (int) $row['id'];
                $stmt = $pdo->prepare($sql);
                $stmt->execute($payload);
            }

            $pdo->commit();

            return true;
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            App::getInstance(true)->getLogger()->error('Failed to update user resources: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Update user resources by ID.
     *
     * @param int $id Resource ID
     * @param array $data Fields to update
     *
     * @return bool True on success, false on failure
     */
    public static function updateById(int $id, array $data): bool
    {
        if ($id <= 0) {
            return false;
        }

        if (empty($data)) {
            App::getInstance(true)->getLogger()->error('No data to update');

            return false;
        }

        // Prevent updating primary keys
        unset($data['id'], $data['user_id']);

        // Whitelist allowed fields
        $allowedFields = [
            'memory_limit',
            'cpu_limit',
            'disk_limit',
            'server_limit',
            'database_limit',
            'backup_limit',
            'allocation_limit',
        ];

        $payload = [];
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $value = $data[$field];
                if (is_numeric($value)) {
                    $payload[$field] = (int) $value;
                } else {
                    $payload[$field] = 0;
                }
            }
        }

        if ($payload === []) {
            return false;
        }

        $pdo = Database::getPdoConnection();

        try {
            $fields = array_keys($payload);
            $setClause = implode(', ', array_map(fn ($f) => $f . ' = :' . $f, $fields));
            $sql = 'UPDATE ' . self::$table . ' SET ' . $setClause . ' WHERE id = :id';
            $payload['id'] = $id;
            $stmt = $pdo->prepare($sql);

            return $stmt->execute($payload);
        } catch (\PDOException $e) {
            App::getInstance(true)->getLogger()->error('Failed to update user resources: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Atomically adjust a resource limit by a signed delta.
     *
     * Uses a transaction and SELECT ... FOR UPDATE to be race-safe.
     * Checks max limits before adjusting. Will refuse if would exceed max limit.
     *
     * @param int $userId User ID
     * @param string $resourceType Resource type (memory_limit, cpu_limit, etc.)
     * @param int $delta Amount to adjust (can be negative)
     *
     * @return bool True on success, false on failure, insufficient resources, or if would exceed max limit
     */
    public static function adjustResource(int $userId, string $resourceType, int $delta): bool
    {
        if (!self::assertUserExists($userId)) {
            return false;
        }

        // Validate resource type
        $allowedFields = [
            'memory_limit',
            'cpu_limit',
            'disk_limit',
            'server_limit',
            'database_limit',
            'backup_limit',
            'allocation_limit',
        ];

        if (!in_array($resourceType, $allowedFields, true)) {
            App::getInstance(true)->getLogger()->error("Invalid resource type: $resourceType");

            return false;
        }

        $pdo = Database::getPdoConnection();

        try {
            $pdo->beginTransaction();

            $row = self::lockRowForUser($pdo, $userId);
            if ($row === null) {
                // Get default resources from settings
                $defaults = SettingsHelper::getDefaultResources();
                $currentValue = $defaults[$resourceType] ?? 0;
            } else {
                $currentValue = (int) ($row[$resourceType] ?? 0);
            }

            $newValue = $currentValue + $delta;
            if ($newValue < 0) {
                // Not enough resources – rollback and fail.
                $pdo->rollBack();

                return false;
            }

            // Check if new value would exceed max limit
            if (SettingsHelper::exceedsMaxLimit($resourceType, $newValue)) {
                $pdo->rollBack();

                return false;
            }

            if ($row === null) {
                // Get default resources from settings
                $defaults = SettingsHelper::getDefaultResources();
                // Create new row with default resources, then update the adjusted resource
                $stmtInsert = $pdo->prepare(
                    'INSERT INTO ' . self::$table . ' (user_id, memory_limit, cpu_limit, disk_limit, server_limit, database_limit, backup_limit, allocation_limit) VALUES (:user_id, :memory_limit, :cpu_limit, :disk_limit, :server_limit, :database_limit, :backup_limit, :allocation_limit)'
                );
                $defaults['user_id'] = $userId;
                $defaults[$resourceType] = $newValue; // Override with new value
                $stmtInsert->execute($defaults);
            } else {
                $stmtUpdate = $pdo->prepare(
                    'UPDATE ' . self::$table . ' SET ' . $resourceType . ' = :value WHERE id = :id'
                );
                $stmtUpdate->execute([
                    'value' => $newValue,
                    'id' => (int) $row['id'],
                ]);
            }

            $pdo->commit();

            return true;
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            App::getInstance(true)->getLogger()->error('Failed to adjust resource: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Get a specific resource value for a user.
     *
     * If user doesn't have resources, returns default from settings (doesn't create DB row).
     *
     * @param int $userId User ID
     * @param string $resourceType Resource type (memory_limit, cpu_limit, etc.)
     *
     * @return int Resource value (default from settings if not found)
     */
    public static function getResource(int $userId, string $resourceType): int
    {
        // Validate resource type
        $allowedFields = [
            'memory_limit',
            'cpu_limit',
            'disk_limit',
            'server_limit',
            'database_limit',
            'backup_limit',
            'allocation_limit',
        ];

        if (!in_array($resourceType, $allowedFields, true)) {
            return 0;
        }

        $row = self::getByUserId($userId);

        if ($row === null) {
            // Return default from settings (don't create DB row just for reading)
            $defaults = SettingsHelper::getDefaultResources();

            return $defaults[$resourceType] ?? 0;
        }

        return (int) ($row[$resourceType] ?? 0);
    }

    /**
     * Delete user resources by user ID.
     *
     * @param int $userId User ID
     *
     * @return bool True on success, false on failure
     */
    public static function deleteByUserId(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $pdo = Database::getPdoConnection();
        $stmt = $pdo->prepare('DELETE FROM ' . self::$table . ' WHERE user_id = :user_id');

        return $stmt->execute(['user_id' => $userId]);
    }

    /**
     * Delete user resources by ID.
     *
     * @param int $id Resource ID
     *
     * @return bool True on success, false on failure
     */
    public static function deleteById(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        $pdo = Database::getPdoConnection();
        $stmt = $pdo->prepare('DELETE FROM ' . self::$table . ' WHERE id = :id');

        return $stmt->execute(['id' => $id]);
    }

    /**
     * Get all user resources.
     *
     * @param int $page Page number (1-based)
     * @param int $limit Results per page
     *
     * @return array Array of user resources
     */
    public static function getAll(int $page = 1, int $limit = 50): array
    {
        $pdo = Database::getPdoConnection();
        $offset = ($page - 1) * $limit;

        $stmt = $pdo->prepare('SELECT * FROM ' . self::$table . ' ORDER BY id ASC LIMIT :limit OFFSET :offset');
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get count of user resources.
     *
     * @return int Count of user resources
     */
    public static function count(): int
    {
        $pdo = Database::getPdoConnection();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM ' . self::$table);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Ensure the user exists in featherpanel_users.
     *
     * @param int $userId User ID
     *
     * @return bool True if user exists, false otherwise
     */
    private static function assertUserExists(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $user = User::getUserById($userId);

        return $user !== null;
    }

    /**
     * Lock a user resources row for a given user using SELECT ... FOR UPDATE.
     *
     * @param \PDO $pdo active PDO connection with an open transaction
     * @param int $userId User ID
     *
     * @return array|null the locked row or null if none exists
     */
    private static function lockRowForUser(\PDO $pdo, int $userId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM ' . self::$table . ' WHERE user_id = :user_id LIMIT 1 FOR UPDATE'
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }
}

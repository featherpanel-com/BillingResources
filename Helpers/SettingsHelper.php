<?php

/*
 * This file is part of FeatherPanel.
 *
 * MIT License
 *
 * Copyright (c) 2025 MythicalSystems
 * Copyright (c) 2025 Cassian Gherman (NaysKutzu)
 * Copyright (c) 2018 - 2021 Dane Everitt <dane@daneeveritt.com> and Contributors
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

namespace App\Addons\billingresources\Helpers;

use App\Plugins\PluginSettings;

/**
 * Helper for managing resource settings (defaults and max limits) using PluginSettings.
 */
class SettingsHelper
{
    /**
     * Get default resources structure.
     *
     * @return array<string,int> Default resources
     */
    public static function getDefaultResources(): array
    {
        $defaultsJson = PluginSettings::getSetting('billingresources', 'default_resources');
        if ($defaultsJson === null || $defaultsJson === '') {
            return self::getDefaultResourcesStructure();
        }

        // Decode HTML entities in case the value was HTML-encoded
        $decodedJson = html_entity_decode($defaultsJson, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = json_decode($decodedJson, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return self::getDefaultResourcesStructure();
        }

        // Start with decoded values (prioritize DB), then fill missing keys with defaults
        // This ensures saved values (including 0) are preserved
        $defaults = self::getDefaultResourcesStructure();
        $result = [];
        foreach ($defaults as $key => $defaultValue) {
            // Use decoded value if it exists (even if 0), otherwise use default
            // Explicitly check for key existence to preserve 0 values
            if (array_key_exists($key, $decoded)) {
                $result[$key] = (int) $decoded[$key];
            } else {
                $result[$key] = (int) $defaultValue;
            }
        }

        return $result;
    }

    /**
     * Set default resources.
     *
     * @param array<string,int> $defaults Default resources
     */
    public static function setDefaultResources(array $defaults): void
    {
        // Ensure all required keys exist, fill missing ones with defaults
        $structure = self::getDefaultResourcesStructure();
        $completeDefaults = [];
        foreach ($structure as $key => $defaultValue) {
            $completeDefaults[$key] = array_key_exists($key, $defaults) ? (int) $defaults[$key] : (int) $defaultValue;
        }
        PluginSettings::setSetting('billingresources', 'default_resources', json_encode($completeDefaults, JSON_NUMERIC_CHECK));
    }

    /**
     * Get max resources structure.
     *
     * @return array<string,int> Max resources (0 = unlimited)
     */
    public static function getMaxResources(): array
    {
        $maxJson = PluginSettings::getSetting('billingresources', 'max_resources');
        if ($maxJson === null || $maxJson === '') {
            return self::getMaxResourcesStructure();
        }

        // Decode HTML entities in case the value was HTML-encoded
        $decodedJson = html_entity_decode($maxJson, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = json_decode($decodedJson, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return self::getMaxResourcesStructure();
        }

        // Start with decoded values (prioritize DB), then fill missing keys with defaults
        // This ensures saved values (including 0) are preserved
        $maxDefaults = self::getMaxResourcesStructure();
        $result = [];
        foreach ($maxDefaults as $key => $defaultValue) {
            // Use decoded value if it exists (even if 0), otherwise use default
            // Explicitly check for key existence to preserve 0 values
            if (array_key_exists($key, $decoded)) {
                $result[$key] = (int) $decoded[$key];
            } else {
                $result[$key] = (int) $defaultValue;
            }
        }

        return $result;
    }

    /**
     * Set max resources.
     *
     * @param array<string,int> $max Max resources (0 = unlimited)
     */
    public static function setMaxResources(array $max): void
    {
        // Ensure all required keys exist, fill missing ones with defaults
        $structure = self::getMaxResourcesStructure();
        $completeMax = [];
        foreach ($structure as $key => $defaultValue) {
            $completeMax[$key] = array_key_exists($key, $max) ? (int) $max[$key] : (int) $defaultValue;
        }
        PluginSettings::setSetting('billingresources', 'max_resources', json_encode($completeMax, JSON_NUMERIC_CHECK));
    }

    /**
     * Get all settings (defaults and max).
     *
     * @return array<string,array<string,int>> Settings structure
     */
    public static function getAllSettings(): array
    {
        return [
            'default_resources' => self::getDefaultResources(),
            'max_resources' => self::getMaxResources(),
        ];
    }

    /**
     * Check if a resource value exceeds the max limit.
     *
     * @param string $resourceType Resource type
     * @param int $value Value to check
     *
     * @return bool True if exceeds max (and max is not 0/unlimited), false otherwise
     */
    public static function exceedsMaxLimit(string $resourceType, int $value): bool
    {
        $maxResources = self::getMaxResources();
        $maxValue = $maxResources[$resourceType] ?? 0;

        // 0 means unlimited
        if ($maxValue === 0) {
            return false;
        }

        return $value > $maxValue;
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
        $maxResources = self::getMaxResources();

        return $maxResources[$resourceType] ?? 0;
    }

    /**
     * Build default resources structure.
     *
     * @return array<string,int> Default resources structure
     */
    private static function getDefaultResourcesStructure(): array
    {
        return [
            'memory_limit' => 2048,      // 2GB RAM
            'cpu_limit' => 100,          // 100% CPU
            'disk_limit' => 4096,        // 4GB storage
            'server_limit' => 1,         // 1 server
            'database_limit' => 3,        // 3 databases
            'backup_limit' => 5,          // 5 backups
            'allocation_limit' => 5,       // 5 allocations
        ];
    }

    /**
     * Build max resources structure (0 = unlimited).
     *
     * @return array<string,int> Max resources structure
     */
    private static function getMaxResourcesStructure(): array
    {
        return [
            'memory_limit' => 65536,      // 64GB RAM
            'cpu_limit' => 1000,           // 1000% CPU
            'disk_limit' => 131072,       // 128GB storage
            'server_limit' => 50,         // 50 servers
            'database_limit' => 100,       // 100 databases
            'backup_limit' => 200,         // 200 backups
            'allocation_limit' => 200,     // 200 allocations
        ];
    }
}

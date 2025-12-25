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

namespace App\Addons\billingresources\Controllers\Admin;

use App\Chat\User;
use App\Chat\Activity;
use App\Chat\Database;
use App\Helpers\ApiResponse;
use OpenApi\Attributes as OA;
use App\CloudFlare\CloudFlareRealIP;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Addons\billingresources\Helpers\SettingsHelper;
use App\Addons\billingresources\Helpers\ResourcesHelper;

#[OA\Tag(name: 'Admin - Billing Resources', description: 'Resource limits management for administrators')]
class BillingResourcesController
{
    #[OA\Get(
        path: '/api/admin/billingresources/users',
        summary: 'Get all users with resources',
        description: 'Get paginated list of all users with their resource limits',
        tags: ['Admin - Billing Resources'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 20)),
            new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string'), description: 'Search by username, email, or UUID'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Users retrieved successfully'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function getUsers(Request $request): Response
    {
        $page = max((int) $request->query->get('page', 1), 1);
        $limit = min(max((int) $request->query->get('limit', 20), 1), 100);
        $offset = ($page - 1) * $limit;
        $search = $request->query->get('search', '');

        try {
            $pdo = Database::getPdoConnection();
            $where = [];
            $params = [];

            if (!empty($search)) {
                $where[] = '(u.username LIKE :search OR u.email LIKE :search OR u.uuid LIKE :search)';
                $params['search'] = '%' . $search . '%';
            }

            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

            // Get total count
            $countSql = 'SELECT COUNT(*) as count FROM featherpanel_users u ' . $whereClause;
            $countStmt = $pdo->prepare($countSql);
            $countStmt->execute($params);
            $total = (int) $countStmt->fetch(\PDO::FETCH_ASSOC)['count'];

            // Get users
            $sql = 'SELECT u.id, u.username, u.email, u.uuid, u.first_seen
                    FROM featherpanel_users u
                    ' . $whereClause . '
                    ORDER BY u.username ASC
                    LIMIT :limit OFFSET :offset';
            $stmt = $pdo->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue(':' . $key, $value);
            }
            $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
            $stmt->execute();
            $users = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Get total resources for each user (including defaults if no DB entry)
            foreach ($users as &$user) {
                $resources = ResourcesHelper::getUserResourcesOrDefault((int) $user['id']);
                // Extract resource values - these are the TOTAL LIMITS the user has
                $user['memory_limit'] = (int) ($resources['memory_limit'] ?? 0);
                $user['cpu_limit'] = (int) ($resources['cpu_limit'] ?? 0);
                $user['disk_limit'] = (int) ($resources['disk_limit'] ?? 0);
                $user['server_limit'] = (int) ($resources['server_limit'] ?? 0);
                $user['database_limit'] = (int) ($resources['database_limit'] ?? 0);
                $user['backup_limit'] = (int) ($resources['backup_limit'] ?? 0);
                $user['allocation_limit'] = (int) ($resources['allocation_limit'] ?? 0);
            }
            unset($user);

            return ApiResponse::success([
                'data' => $users,
                'meta' => [
                    'pagination' => [
                        'total' => $total,
                        'count' => count($users),
                        'per_page' => $limit,
                        'current_page' => $page,
                        'total_pages' => (int) ceil($total / $limit),
                    ],
                ],
            ], 'Users retrieved successfully', 200);
        } catch (\Exception $e) {
            return ApiResponse::error('Failed to retrieve users: ' . $e->getMessage(), 'GET_USERS_FAILED', 500);
        }
    }

    #[OA\Get(
        path: '/api/admin/billingresources/users/{userId}/resources',
        summary: 'Get user resources',
        description: 'Get resource limits for a specific user',
        tags: ['Admin - Billing Resources'],
        parameters: [
            new OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'User resources retrieved successfully'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'User not found'),
        ]
    )]
    public function getUserResources(Request $request, int $userId): Response
    {
        $user = User::getUserById($userId);
        if (!$user) {
            return ApiResponse::error('User not found', 'USER_NOT_FOUND', 404);
        }

        $resources = ResourcesHelper::getUserResourcesOrDefault($userId);

        return ApiResponse::success([
            'user_id' => $userId,
            'username' => $user['username'],
            'email' => $user['email'],
            'uuid' => $user['uuid'],
            'resources' => $resources,
        ], 'User resources retrieved successfully', 200);
    }

    #[OA\Patch(
        path: '/api/admin/billingresources/users/{userId}/resources',
        summary: 'Update user resources',
        description: 'Update resource limits for a specific user',
        tags: ['Admin - Billing Resources'],
        parameters: [
            new OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'memory_limit', type: 'integer', description: 'Memory limit in MB', minimum: 0),
                    new OA\Property(property: 'cpu_limit', type: 'integer', description: 'CPU limit in percentage', minimum: 0),
                    new OA\Property(property: 'disk_limit', type: 'integer', description: 'Disk limit in MB', minimum: 0),
                    new OA\Property(property: 'server_limit', type: 'integer', description: 'Server limit', minimum: 0),
                    new OA\Property(property: 'database_limit', type: 'integer', description: 'Database limit', minimum: 0),
                    new OA\Property(property: 'backup_limit', type: 'integer', description: 'Backup limit', minimum: 0),
                    new OA\Property(property: 'allocation_limit', type: 'integer', description: 'Allocation limit', minimum: 0),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Resources updated successfully'),
            new OA\Response(response: 400, description: 'Invalid input'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'User not found'),
        ]
    )]
    public function updateUserResources(Request $request, int $userId): Response
    {
        $admin = $request->get('user');
        $data = json_decode($request->getContent(), true);

        if ($data === null) {
            return ApiResponse::error('Invalid JSON', 'INVALID_JSON', 400);
        }

        $user = User::getUserById($userId);
        if (!$user) {
            return ApiResponse::error('User not found', 'USER_NOT_FOUND', 404);
        }

        // Validate all resource values are non-negative integers
        $allowedFields = [
            'memory_limit',
            'cpu_limit',
            'disk_limit',
            'server_limit',
            'database_limit',
            'backup_limit',
            'allocation_limit',
        ];

        $resources = [];
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $value = (int) $data[$field];
                if ($value < 0) {
                    return ApiResponse::error("Invalid value for {$field}. Must be non-negative", 'INVALID_VALUE', 400);
                }
                $resources[$field] = $value;
            }
        }

        if (empty($resources)) {
            return ApiResponse::error('No resources to update', 'NO_RESOURCES', 400);
        }

        if (!ResourcesHelper::updateUserResources($userId, $resources)) {
            return ApiResponse::error('Failed to update resources', 'UPDATE_RESOURCES_FAILED', 500);
        }

        $updatedResources = ResourcesHelper::getUserResourcesOrDefault($userId);

        // Log activity
        $resourceChanges = [];
        foreach ($resources as $field => $value) {
            $resourceChanges[] = "{$field}: {$value}";
        }
        $changesText = implode(', ', $resourceChanges);

        Activity::createActivity([
            'user_uuid' => $admin['uuid'] ?? null,
            'name' => 'billingresources_update_resources',
            'context' => "Updated resources for user: {$user['username']} (ID: {$userId}). Changes: {$changesText}",
            'ip_address' => CloudFlareRealIP::getRealIP(),
        ]);

        return ApiResponse::success([
            'user_id' => $userId,
            'resources' => $updatedResources,
        ], 'Resources updated successfully', 200);
    }

    #[OA\Get(
        path: '/api/admin/billingresources/users/search',
        summary: 'Search users',
        description: 'Search users by username, email, or UUID',
        tags: ['Admin - Billing Resources'],
        parameters: [
            new OA\Parameter(name: 'query', in: 'query', required: true, schema: new OA\Schema(type: 'string'), description: 'Search query'),
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', default: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Users retrieved successfully'),
            new OA\Response(response: 400, description: 'Invalid query'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function searchUsers(Request $request): Response
    {
        $query = $request->query->get('query', '');
        $limit = min(max((int) $request->query->get('limit', 20), 1), 100);

        if (empty($query) || strlen($query) < 2) {
            return ApiResponse::error('Query must be at least 2 characters', 'INVALID_QUERY', 400);
        }

        try {
            $pdo = Database::getPdoConnection();
            $searchPattern = '%' . $query . '%';
            $sql = 'SELECT u.id, u.username, u.email, u.uuid
                    FROM featherpanel_users u
                    WHERE u.username LIKE :query
                       OR u.email LIKE :query
                       OR u.uuid LIKE :query
                    ORDER BY u.username ASC
                    LIMIT ' . $limit;

            $stmt = $pdo->prepare($sql);
            $stmt->execute(['query' => $searchPattern]);
            $users = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Get total resources for each user (including defaults if no DB entry)
            foreach ($users as &$user) {
                $resources = ResourcesHelper::getUserResourcesOrDefault((int) $user['id']);
                // Extract resource values - these are the TOTAL LIMITS the user has
                $user['memory_limit'] = (int) ($resources['memory_limit'] ?? 0);
                $user['cpu_limit'] = (int) ($resources['cpu_limit'] ?? 0);
                $user['disk_limit'] = (int) ($resources['disk_limit'] ?? 0);
                $user['server_limit'] = (int) ($resources['server_limit'] ?? 0);
                $user['database_limit'] = (int) ($resources['database_limit'] ?? 0);
                $user['backup_limit'] = (int) ($resources['backup_limit'] ?? 0);
                $user['allocation_limit'] = (int) ($resources['allocation_limit'] ?? 0);
            }
            unset($user);

            return ApiResponse::success([
                'data' => $users,
                'count' => count($users),
            ], 'Users retrieved successfully', 200);
        } catch (\Exception $e) {
            return ApiResponse::error('Failed to search users: ' . $e->getMessage(), 'SEARCH_FAILED', 500);
        }
    }

    #[OA\Get(
        path: '/api/admin/billingresources/statistics',
        summary: 'Get resource statistics',
        description: 'Get overall statistics about user resources',
        tags: ['Admin - Billing Resources'],
        responses: [
            new OA\Response(response: 200, description: 'Statistics retrieved successfully'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function getStatistics(Request $request): Response
    {
        try {
            $pdo = Database::getPdoConnection();

            // Get total users with resources
            $totalUsersStmt = $pdo->prepare('SELECT COUNT(DISTINCT user_id) as count FROM featherpanel_billingresources_user_resources');
            $totalUsersStmt->execute();
            $totalUsersWithResources = (int) $totalUsersStmt->fetch(\PDO::FETCH_ASSOC)['count'];

            // Get total users
            $allUsersStmt = $pdo->prepare('SELECT COUNT(*) as count FROM featherpanel_users');
            $allUsersStmt->execute();
            $totalUsers = (int) $allUsersStmt->fetch(\PDO::FETCH_ASSOC)['count'];

            // Get resource totals
            $totalsStmt = $pdo->prepare('SELECT 
                SUM(memory_limit) as total_memory,
                SUM(cpu_limit) as total_cpu,
                SUM(disk_limit) as total_disk,
                SUM(server_limit) as total_servers,
                SUM(database_limit) as total_databases,
                SUM(backup_limit) as total_backups,
                SUM(allocation_limit) as total_allocations
                FROM featherpanel_billingresources_user_resources');
            $totalsStmt->execute();
            $totals = $totalsStmt->fetch(\PDO::FETCH_ASSOC);

            // Get averages
            $averagesStmt = $pdo->prepare('SELECT 
                AVG(memory_limit) as avg_memory,
                AVG(cpu_limit) as avg_cpu,
                AVG(disk_limit) as avg_disk,
                AVG(server_limit) as avg_servers,
                AVG(database_limit) as avg_databases,
                AVG(backup_limit) as avg_backups,
                AVG(allocation_limit) as avg_allocations
                FROM featherpanel_billingresources_user_resources');
            $averagesStmt->execute();
            $averages = $averagesStmt->fetch(\PDO::FETCH_ASSOC);

            return ApiResponse::success([
                'users' => [
                    'total' => $totalUsers,
                    'with_resources' => $totalUsersWithResources,
                    'without_resources' => $totalUsers - $totalUsersWithResources,
                ],
                'totals' => [
                    'memory_limit' => (int) ($totals['total_memory'] ?? 0),
                    'cpu_limit' => (int) ($totals['total_cpu'] ?? 0),
                    'disk_limit' => (int) ($totals['total_disk'] ?? 0),
                    'server_limit' => (int) ($totals['total_servers'] ?? 0),
                    'database_limit' => (int) ($totals['total_databases'] ?? 0),
                    'backup_limit' => (int) ($totals['total_backups'] ?? 0),
                    'allocation_limit' => (int) ($totals['total_allocations'] ?? 0),
                ],
                'averages' => [
                    'memory_limit' => (float) round($averages['avg_memory'] ?? 0, 2),
                    'cpu_limit' => (float) round($averages['avg_cpu'] ?? 0, 2),
                    'disk_limit' => (float) round($averages['avg_disk'] ?? 0, 2),
                    'server_limit' => (float) round($averages['avg_servers'] ?? 0, 2),
                    'database_limit' => (float) round($averages['avg_databases'] ?? 0, 2),
                    'backup_limit' => (float) round($averages['avg_backups'] ?? 0, 2),
                    'allocation_limit' => (float) round($averages['avg_allocations'] ?? 0, 2),
                ],
            ], 'Statistics retrieved successfully', 200);
        } catch (\Exception $e) {
            return ApiResponse::error('Failed to retrieve statistics: ' . $e->getMessage(), 'GET_STATISTICS_FAILED', 500);
        }
    }

    #[OA\Get(
        path: '/api/admin/billingresources/settings',
        summary: 'Get resource settings',
        description: 'Get default and max resource limits configuration',
        tags: ['Admin - Billing Resources'],
        responses: [
            new OA\Response(response: 200, description: 'Settings retrieved successfully'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function getSettings(Request $request): Response
    {
        $settings = SettingsHelper::getAllSettings();

        return ApiResponse::success($settings, 'Settings retrieved successfully', 200);
    }

    #[OA\Patch(
        path: '/api/admin/billingresources/settings',
        summary: 'Update resource settings',
        description: 'Update default and/or max resource limits',
        tags: ['Admin - Billing Resources'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(
                        property: 'default_resources',
                        type: 'object',
                        description: 'Default resources for new users',
                        properties: [
                            new OA\Property(property: 'memory_limit', type: 'integer', minimum: 0),
                            new OA\Property(property: 'cpu_limit', type: 'integer', minimum: 0),
                            new OA\Property(property: 'disk_limit', type: 'integer', minimum: 0),
                            new OA\Property(property: 'server_limit', type: 'integer', minimum: 0),
                            new OA\Property(property: 'database_limit', type: 'integer', minimum: 0),
                            new OA\Property(property: 'backup_limit', type: 'integer', minimum: 0),
                            new OA\Property(property: 'allocation_limit', type: 'integer', minimum: 0),
                        ]
                    ),
                    new OA\Property(
                        property: 'max_resources',
                        type: 'object',
                        description: 'Maximum resources users can have (0 = unlimited)',
                        properties: [
                            new OA\Property(property: 'memory_limit', type: 'integer', minimum: 0),
                            new OA\Property(property: 'cpu_limit', type: 'integer', minimum: 0),
                            new OA\Property(property: 'disk_limit', type: 'integer', minimum: 0),
                            new OA\Property(property: 'server_limit', type: 'integer', minimum: 0),
                            new OA\Property(property: 'database_limit', type: 'integer', minimum: 0),
                            new OA\Property(property: 'backup_limit', type: 'integer', minimum: 0),
                            new OA\Property(property: 'allocation_limit', type: 'integer', minimum: 0),
                        ]
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Settings updated successfully'),
            new OA\Response(response: 400, description: 'Invalid input'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function updateSettings(Request $request): Response
    {
        $admin = $request->get('user');
        $data = json_decode($request->getContent(), true);

        if ($data === null) {
            return ApiResponse::error('Invalid JSON', 'INVALID_JSON', 400);
        }

        $allowedFields = [
            'memory_limit',
            'cpu_limit',
            'disk_limit',
            'server_limit',
            'database_limit',
            'backup_limit',
            'allocation_limit',
        ];

        $savedDefaults = null;
        $savedMax = null;

        // Update default resources if provided
        if (isset($data['default_resources']) && is_array($data['default_resources'])) {
            // Get existing defaults to preserve fields not being updated
            $existingDefaults = SettingsHelper::getDefaultResources();
            $defaults = $existingDefaults;
            
            // Update only the fields provided in the request
            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $data['default_resources'])) {
                    $value = (int) $data['default_resources'][$field];
                    if ($value < 0) {
                        return ApiResponse::error("Invalid value for default_resources.{$field}. Must be non-negative", 'INVALID_VALUE', 400);
                    }
                    $defaults[$field] = $value;
                }
            }
            
            // Always save the complete structure
            SettingsHelper::setDefaultResources($defaults);
            $savedDefaults = $defaults;
        }

        // Update max resources if provided
        if (isset($data['max_resources']) && is_array($data['max_resources'])) {
            // Get existing max to preserve fields not being updated
            $existingMax = SettingsHelper::getMaxResources();
            $max = $existingMax;
            
            // Update only the fields provided in the request
            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $data['max_resources'])) {
                    $value = (int) $data['max_resources'][$field];
                    if ($value < 0) {
                        return ApiResponse::error("Invalid value for max_resources.{$field}. Must be non-negative", 'INVALID_VALUE', 400);
                    }
                    $max[$field] = $value;
                }
            }
            
            // Always save the complete structure
            SettingsHelper::setMaxResources($max);
            $savedMax = $max;
        }

        // Log activity
        Activity::createActivity([
            'user_uuid' => $admin['uuid'] ?? null,
            'name' => 'billingresources_update_settings',
            'context' => 'Updated resource settings (defaults and/or max limits)',
            'ip_address' => CloudFlareRealIP::getRealIP(),
        ]);

        // Build response from saved data or read from DB if nothing was updated
        $settings = [
            'default_resources' => $savedDefaults !== null ? $savedDefaults : SettingsHelper::getDefaultResources(),
            'max_resources' => $savedMax !== null ? $savedMax : SettingsHelper::getMaxResources(),
        ];

        return ApiResponse::success($settings, 'Settings updated successfully', 200);
    }
}

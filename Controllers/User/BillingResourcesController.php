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

namespace App\Addons\billingresources\Controllers\User;

use App\Chat\Backup;
use App\Chat\Server;
use App\Chat\Allocation;
use App\Chat\ServerDatabase;
use App\Helpers\ApiResponse;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Addons\billingresources\Helpers\ResourcesHelper;

#[OA\Tag(name: 'User - Billing Resources', description: 'Resource limits management for users')]
class BillingResourcesController
{
    #[OA\Get(
        path: '/api/user/billingresources/resources',
        summary: 'Get user resources',
        description: 'Get the current user\'s resource limits (memory, CPU, disk, servers, databases, backups, allocations)',
        tags: ['User - Billing Resources'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Resources retrieved successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'id', type: 'integer', nullable: true),
                        new OA\Property(property: 'user_id', type: 'integer'),
                        new OA\Property(property: 'memory_limit', type: 'integer', description: 'Memory limit in MB'),
                        new OA\Property(property: 'cpu_limit', type: 'integer', description: 'CPU limit in percentage'),
                        new OA\Property(property: 'disk_limit', type: 'integer', description: 'Disk limit in MB'),
                        new OA\Property(property: 'server_limit', type: 'integer', description: 'Server limit'),
                        new OA\Property(property: 'database_limit', type: 'integer', description: 'Database limit'),
                        new OA\Property(property: 'backup_limit', type: 'integer', description: 'Backup limit'),
                        new OA\Property(property: 'allocation_limit', type: 'integer', description: 'Allocation limit'),
                        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
                        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
        ]
    )]
    public function getResources(Request $request): Response
    {
        $user = $request->attributes->get('user') ?? $request->get('user');
        if (!$user || !isset($user['id'])) {
            return ApiResponse::error('User not authenticated', 'UNAUTHORIZED', 401);
        }

        $userId = (int) $user['id'];

        // Ensure user has resources in DB (creates with defaults if missing)
        $resources = ResourcesHelper::ensureUserResources($userId);
        if ($resources === null) {
            return ApiResponse::error('Failed to load user resources', 'RESOURCE_LOAD_ERROR', 500);
        }

        // Get resource limits (now guaranteed to exist in DB)
        $limits = ResourcesHelper::getUserResourcesOrDefault($userId);

        // Calculate used resources from server LIMITS (not actual usage)
        $used = ResourcesHelper::calculateUsedResourcesFromServerLimits($userId);

        // Get max limits so frontend can show them
        $maxLimits = [];
        $resourceTypes = ['memory_limit', 'cpu_limit', 'disk_limit', 'server_limit', 'database_limit', 'backup_limit', 'allocation_limit'];
        foreach ($resourceTypes as $type) {
            $maxLimits[$type] = ResourcesHelper::getMaxLimit($type);
        }

        return ApiResponse::success([
            'limits' => $limits,
            'used' => $used,
            'max_limits' => $maxLimits,
        ], 'Resources retrieved successfully', 200);
    }

    #[OA\Get(
        path: '/api/user/billingresources/resources/{resourceType}',
        summary: 'Get specific user resource',
        description: 'Get a specific resource limit for the current user (memory_limit, cpu_limit, disk_limit, server_limit, database_limit, backup_limit, allocation_limit)',
        tags: ['User - Billing Resources'],
        parameters: [
            new OA\Parameter(
                name: 'resourceType',
                in: 'path',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                    enum: ['memory_limit', 'cpu_limit', 'disk_limit', 'server_limit', 'database_limit', 'backup_limit', 'allocation_limit']
                ),
                description: 'Type of resource to retrieve'
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Resource retrieved successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'resource_type', type: 'string'),
                        new OA\Property(property: 'value', type: 'integer'),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Invalid resource type'),
            new OA\Response(response: 401, description: 'Unauthorized'),
        ]
    )]
    public function getResource(Request $request, string $resourceType): Response
    {
        $user = $request->attributes->get('user') ?? $request->get('user');
        if (!$user || !isset($user['id'])) {
            return ApiResponse::error('User not authenticated', 'UNAUTHORIZED', 401);
        }

        // Validate resource type
        $allowedTypes = [
            'memory_limit',
            'cpu_limit',
            'disk_limit',
            'server_limit',
            'database_limit',
            'backup_limit',
            'allocation_limit',
        ];

        if (!in_array($resourceType, $allowedTypes, true)) {
            return ApiResponse::error('Invalid resource type', 'INVALID_RESOURCE_TYPE', 400, [
                'allowed_types' => $allowedTypes,
            ]);
        }

        $value = ResourcesHelper::getUserResource($user['id'], $resourceType);

        return ApiResponse::success([
            'resource_type' => $resourceType,
            'value' => $value,
        ], 'Resource retrieved successfully', 200);
    }

    #[OA\Get(
        path: '/api/user/billingresources/servers',
        summary: 'Get user servers with resources',
        description: 'Get all servers owned by the user with their current resources and available limits',
        tags: ['User - Billing Resources'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Servers retrieved successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'servers',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer'),
                                    new OA\Property(property: 'name', type: 'string'),
                                    new OA\Property(property: 'uuid', type: 'string'),
                                    new OA\Property(property: 'memory', type: 'integer'),
                                    new OA\Property(property: 'cpu', type: 'integer'),
                                    new OA\Property(property: 'disk', type: 'integer'),
                                    new OA\Property(property: 'database_limit', type: 'integer', nullable: true),
                                    new OA\Property(property: 'backup_limit', type: 'integer', nullable: true),
                                    new OA\Property(property: 'allocation_limit', type: 'integer', nullable: true),
                                ]
                            )
                        ),
                        new OA\Property(
                            property: 'available',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'memory_limit', type: 'integer'),
                                new OA\Property(property: 'cpu_limit', type: 'integer'),
                                new OA\Property(property: 'disk_limit', type: 'integer'),
                                new OA\Property(property: 'database_limit', type: 'integer'),
                                new OA\Property(property: 'backup_limit', type: 'integer'),
                                new OA\Property(property: 'allocation_limit', type: 'integer'),
                            ]
                        ),
                        new OA\Property(
                            property: 'limits',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'memory_limit', type: 'integer'),
                                new OA\Property(property: 'cpu_limit', type: 'integer'),
                                new OA\Property(property: 'disk_limit', type: 'integer'),
                                new OA\Property(property: 'database_limit', type: 'integer'),
                                new OA\Property(property: 'backup_limit', type: 'integer'),
                                new OA\Property(property: 'allocation_limit', type: 'integer'),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthorized'),
        ]
    )]
    public function getServers(Request $request): Response
    {
        $user = $request->attributes->get('user') ?? $request->get('user');
        if (!$user || !isset($user['id'])) {
            return ApiResponse::error('User not authenticated', 'UNAUTHORIZED', 401);
        }

        $userId = (int) $user['id'];

        // Get user resource limits
        $limits = ResourcesHelper::getUserResourcesOrDefault($userId);

        // Get all servers
        $servers = Server::getServersByOwnerId($userId);

        // Calculate used resources
        $used = [
            'memory_limit' => 0,
            'cpu_limit' => 0,
            'disk_limit' => 0,
            'database_limit' => 0,
            'backup_limit' => 0,
            'allocation_limit' => 0,
        ];

        $serverList = [];
        foreach ($servers as $server) {
            $used['memory_limit'] += (int) ($server['memory'] ?? 0);
            $used['cpu_limit'] += (int) ($server['cpu'] ?? 0);
            $used['disk_limit'] += (int) ($server['disk'] ?? 0);

            // Count databases, backups, allocations for this server
            $databases = ServerDatabase::getDatabasesByServerId((int) $server['id']);
            $backups = Backup::getBackupsByServerId((int) $server['id']);
            $allocations = Allocation::getByServerId((int) $server['id']);

            $used['database_limit'] += count($databases);
            $used['backup_limit'] += count($backups);
            $used['allocation_limit'] += count($allocations);

            $serverList[] = [
                'id' => (int) $server['id'],
                'name' => $server['name'] ?? '',
                'uuid' => $server['uuid'] ?? '',
                'memory' => (int) ($server['memory'] ?? 0),
                'cpu' => (int) ($server['cpu'] ?? 0),
                'disk' => (int) ($server['disk'] ?? 0),
                'database_limit' => (int) ($server['database_limit'] ?? 0),
                'backup_limit' => (int) ($server['backup_limit'] ?? 0),
                'allocation_limit' => (int) ($server['allocation_limit'] ?? 0),
            ];
        }

        // Calculate available resources
        $available = [
            'memory_limit' => max(0, $limits['memory_limit'] - $used['memory_limit']),
            'cpu_limit' => max(0, $limits['cpu_limit'] - $used['cpu_limit']),
            'disk_limit' => max(0, $limits['disk_limit'] - $used['disk_limit']),
            'database_limit' => max(0, $limits['database_limit'] - $used['database_limit']),
            'backup_limit' => max(0, $limits['backup_limit'] - $used['backup_limit']),
            'allocation_limit' => max(0, $limits['allocation_limit'] - $used['allocation_limit']),
        ];

        return ApiResponse::success([
            'servers' => $serverList,
            'available' => $available,
            'limits' => $limits,
            'used' => $used,
        ], 'Servers retrieved successfully', 200);
    }

    #[OA\Patch(
        path: '/api/user/billingresources/servers/{serverId}/resources',
        summary: 'Update server resources',
        description: 'Update resources for a server owned by the user, ensuring they do not exceed available limits',
        tags: ['User - Billing Resources'],
        parameters: [
            new OA\Parameter(
                name: 'serverId',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer'),
                description: 'Server ID'
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'memory', type: 'integer', nullable: true, description: 'Memory in MB'),
                    new OA\Property(property: 'cpu', type: 'integer', nullable: true, description: 'CPU in percentage'),
                    new OA\Property(property: 'disk', type: 'integer', nullable: true, description: 'Disk in MB'),
                    new OA\Property(property: 'database_limit', type: 'integer', nullable: true),
                    new OA\Property(property: 'backup_limit', type: 'integer', nullable: true),
                    new OA\Property(property: 'allocation_limit', type: 'integer', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Server resources updated successfully'),
            new OA\Response(response: 400, description: 'Invalid request or exceeds limits'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden - Server not owned by user'),
            new OA\Response(response: 404, description: 'Server not found'),
        ]
    )]
    public function updateServerResources(Request $request, int $serverId): Response
    {
        $user = $request->attributes->get('user') ?? $request->get('user');
        if (!$user || !isset($user['id'])) {
            return ApiResponse::error('User not authenticated', 'UNAUTHORIZED', 401);
        }

        $userId = (int) $user['id'];

        // Verify server exists and is owned by user
        $server = Server::getServerById($serverId);
        if (!$server) {
            return ApiResponse::error('Server not found', 'SERVER_NOT_FOUND', 404);
        }

        if ((int) $server['owner_id'] !== $userId) {
            return ApiResponse::error('You do not own this server', 'FORBIDDEN', 403);
        }

        // Get request data
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return ApiResponse::error('Invalid request body', 'INVALID_BODY', 400);
        }

        // Get user limits and current usage
        $limits = ResourcesHelper::getUserResourcesOrDefault($userId);
        $servers = Server::getServersByOwnerId($userId);

        // Calculate current usage (excluding the server being updated)
        $used = [
            'memory_limit' => 0,
            'cpu_limit' => 0,
            'disk_limit' => 0,
            'database_limit' => 0,
            'backup_limit' => 0,
            'allocation_limit' => 0,
        ];

        foreach ($servers as $s) {
            if ((int) $s['id'] === $serverId) {
                continue; // Skip the server being updated
            }
            $used['memory_limit'] += (int) ($s['memory'] ?? 0);
            $used['cpu_limit'] += (int) ($s['cpu'] ?? 0);
            $used['disk_limit'] += (int) ($s['disk'] ?? 0);

            $databases = ServerDatabase::getDatabasesByServerId((int) $s['id']);
            $backups = Backup::getBackupsByServerId((int) $s['id']);
            $allocations = Allocation::getByServerId((int) $s['id']);

            $used['database_limit'] += count($databases);
            $used['backup_limit'] += count($backups);
            $used['allocation_limit'] += count($allocations);
        }

        // Calculate available resources
        $available = [
            'memory_limit' => max(0, $limits['memory_limit'] - $used['memory_limit']),
            'cpu_limit' => max(0, $limits['cpu_limit'] - $used['cpu_limit']),
            'disk_limit' => max(0, $limits['disk_limit'] - $used['disk_limit']),
            'database_limit' => max(0, $limits['database_limit'] - $used['database_limit']),
            'backup_limit' => max(0, $limits['backup_limit'] - $used['backup_limit']),
            'allocation_limit' => max(0, $limits['allocation_limit'] - $used['allocation_limit']),
        ];

        // Prepare update data
        $updateData = [];
        $errors = [];

        // Validate and prepare memory
        if (isset($data['memory'])) {
            $newMemory = (int) $data['memory'];
            if ($newMemory < 0) {
                $errors[] = 'Memory cannot be negative';
            } elseif ($newMemory > $available['memory_limit'] + (int) ($server['memory'] ?? 0)) {
                $errors[] = 'Memory exceeds available limit. Available: ' . ($available['memory_limit'] + (int) ($server['memory'] ?? 0)) . ' MB';
            } else {
                $updateData['memory'] = $newMemory;
            }
        }

        // Validate and prepare CPU
        if (isset($data['cpu'])) {
            $newCpu = (int) $data['cpu'];
            if ($newCpu < 0) {
                $errors[] = 'CPU cannot be negative';
            } elseif ($newCpu > $available['cpu_limit'] + (int) ($server['cpu'] ?? 0)) {
                $errors[] = 'CPU exceeds available limit. Available: ' . ($available['cpu_limit'] + (int) ($server['cpu'] ?? 0)) . '%';
            } else {
                $updateData['cpu'] = $newCpu;
            }
        }

        // Validate and prepare disk
        if (isset($data['disk'])) {
            $newDisk = (int) $data['disk'];
            if ($newDisk < 0) {
                $errors[] = 'Disk cannot be negative';
            } elseif ($newDisk > $available['disk_limit'] + (int) ($server['disk'] ?? 0)) {
                $errors[] = 'Disk exceeds available limit. Available: ' . ($available['disk_limit'] + (int) ($server['disk'] ?? 0)) . ' MB';
            } else {
                $updateData['disk'] = $newDisk;
            }
        }

        // Validate and prepare database_limit
        if (isset($data['database_limit'])) {
            $newDbLimit = (int) $data['database_limit'];
            if ($newDbLimit < 0) {
                $errors[] = 'Database limit cannot be negative';
            } else {
                // Check current databases count
                $currentDatabases = ServerDatabase::getDatabasesByServerId($serverId);
                $currentDbCount = count($currentDatabases);
                if ($newDbLimit < $currentDbCount) {
                    $errors[] = "Database limit cannot be less than current databases ({$currentDbCount})";
                } elseif ($newDbLimit > $available['database_limit'] + (int) ($server['database_limit'] ?? 0)) {
                    $errors[] = 'Database limit exceeds available limit. Available: ' . ($available['database_limit'] + (int) ($server['database_limit'] ?? 0));
                } else {
                    $updateData['database_limit'] = $newDbLimit;
                }
            }
        }

        // Validate and prepare backup_limit
        if (isset($data['backup_limit'])) {
            $newBackupLimit = (int) $data['backup_limit'];
            if ($newBackupLimit < 0) {
                $errors[] = 'Backup limit cannot be negative';
            } else {
                // Check current backups count
                $currentBackups = Backup::getBackupsByServerId($serverId);
                $currentBackupCount = count($currentBackups);
                if ($newBackupLimit < $currentBackupCount) {
                    $errors[] = "Backup limit cannot be less than current backups ({$currentBackupCount})";
                } elseif ($newBackupLimit > $available['backup_limit'] + (int) ($server['backup_limit'] ?? 0)) {
                    $errors[] = 'Backup limit exceeds available limit. Available: ' . ($available['backup_limit'] + (int) ($server['backup_limit'] ?? 0));
                } else {
                    $updateData['backup_limit'] = $newBackupLimit;
                }
            }
        }

        // Validate and prepare allocation_limit
        if (isset($data['allocation_limit'])) {
            $newAllocLimit = (int) $data['allocation_limit'];
            if ($newAllocLimit < 0) {
                $errors[] = 'Allocation limit cannot be negative';
            } else {
                // Check current allocations count
                $currentAllocations = Allocation::getByServerId($serverId);
                $currentAllocCount = count($currentAllocations);
                if ($newAllocLimit < $currentAllocCount) {
                    $errors[] = "Allocation limit cannot be less than current allocations ({$currentAllocCount})";
                } elseif ($newAllocLimit > $available['allocation_limit'] + (int) ($server['allocation_limit'] ?? 0)) {
                    $errors[] = 'Allocation limit exceeds available limit. Available: ' . ($available['allocation_limit'] + (int) ($server['allocation_limit'] ?? 0));
                } else {
                    $updateData['allocation_limit'] = $newAllocLimit;
                }
            }
        }

        if (!empty($errors)) {
            return ApiResponse::error('Validation failed', 'VALIDATION_ERROR', 400, ['errors' => $errors]);
        }

        if (empty($updateData)) {
            return ApiResponse::error('No fields to update', 'NO_UPDATES', 400);
        }

        // Update server
        if (!Server::updateServerById($serverId, $updateData)) {
            return ApiResponse::error('Failed to update server resources', 'UPDATE_FAILED', 500);
        }

        return ApiResponse::success([
            'server_id' => $serverId,
            'updated' => $updateData,
        ], 'Server resources updated successfully', 200);
    }
}

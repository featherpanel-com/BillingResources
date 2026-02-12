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

use App\App;
use App\Chat\Backup;
use App\Chat\Server;
use App\Chat\Allocation;
use App\SubuserPermissions;
use App\Chat\ServerDatabase;
use App\Helpers\ApiResponse;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Addons\billingresources\Helpers\ResourcesHelper;
use App\Controllers\User\Server\CheckSubuserPermissionsTrait;

#[OA\Tag(name: 'User - Server Resources', description: 'Server resource management within user limits')]
class ServerResourcesController
{
    use CheckSubuserPermissionsTrait;

    #[OA\Get(
        path: '/api/user/servers/{uuidShort}/billingresources',
        summary: 'Get server resources with available limits',
        description: 'Get server resources and available user limits for resource allocation',
        tags: ['User - Server Resources'],
        parameters: [
            new OA\Parameter(
                name: 'uuidShort',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string'),
                description: 'Server short UUID'
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Server resources retrieved successfully'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Server not found'),
        ]
    )]
    public function getServerResources(Request $request, string $serverUuid): Response
    {
        try {
            $user = $this->validateUser($request);
            $server = $this->validateServer($serverUuid);

            // Check permission - allow owners and subusers with settings permission
            if ((int) $server['owner_id'] !== (int) $user['id']) {
                $permissionCheck = $this->checkPermission($request, $server, SubuserPermissions::SETTINGS_RENAME);
                if ($permissionCheck !== null) {
                    return $permissionCheck;
                }
            }

            $userId = (int) $user['id'];

            // Ensure user has resources in DB (creates with defaults if missing)
            $resources = ResourcesHelper::ensureUserResources($userId);
            if ($resources === null) {
                return ApiResponse::error('Failed to load user resources', 'RESOURCE_LOAD_ERROR', 500);
            }

            // Get user resource limits (now guaranteed to exist in DB)
            $limits = ResourcesHelper::getUserResourcesOrDefault($userId);

            // Calculate used resources from server LIMITS (excluding current server)
            $used = ResourcesHelper::calculateUsedResourcesFromServerLimits($userId, [(int) $server['id']]);

            // Calculate total used (including current server) for display
            $totalUsed = ResourcesHelper::calculateUsedResourcesFromServerLimits($userId);

            // Calculate available resources for THIS server (what can be allocated to it)
            // Available = limit - used_by_other_servers (for validation/editing)
            $available = ResourcesHelper::calculateAvailableResources($userId, [(int) $server['id']]);

            // Calculate available for display (what's actually left: limit - total_used including this server)
            $availableForDisplay = [
                'memory_limit' => max(0, $limits['memory_limit'] - $totalUsed['memory_limit']),
                'cpu_limit' => max(0, $limits['cpu_limit'] - $totalUsed['cpu_limit']),
                'disk_limit' => max(0, $limits['disk_limit'] - $totalUsed['disk_limit']),
                'database_limit' => max(0, $limits['database_limit'] - $totalUsed['database_limit']),
                'backup_limit' => max(0, $limits['backup_limit'] - $totalUsed['backup_limit']),
                'allocation_limit' => max(0, $limits['allocation_limit'] - $totalUsed['allocation_limit']),
            ];

            // Get current server resources
            $serverResources = [
                'memory' => (int) ($server['memory'] ?? 0),
                'cpu' => (int) ($server['cpu'] ?? 0),
                'disk' => (int) ($server['disk'] ?? 0),
                'database_limit' => (int) ($server['database_limit'] ?? 0),
                'backup_limit' => (int) ($server['backup_limit'] ?? 0),
                'allocation_limit' => (int) ($server['allocation_limit'] ?? 0),
            ];

            // Check if this server's resources exceed user limits
            $serverOverflow = ResourcesHelper::checkServerResourceOverflow($userId, $server);

            $totalOverflow = ResourcesHelper::checkResourceOverflow($userId);

            return ApiResponse::success([
                'server' => [
                    'id' => (int) $server['id'],
                    'name' => $server['name'] ?? '',
                    'uuid' => $server['uuid'] ?? '',
                    'resources' => $serverResources,
                ],
                'available' => $availableForDisplay, // For display: what's actually left
                'available_for_edit' => $available, // For editing: what can be allocated (excluding current server)
                'limits' => $limits,
                'used' => $used,
                'total_used' => $totalUsed,
                'server_overflow' => $serverOverflow,
                'total_overflow' => $totalOverflow,
            ], 'Server resources retrieved successfully', 200);
        } catch (\Exception $e) {
            App::getInstance(true)->getLogger()->error('Error getting server resources: ' . $e->getMessage());

            return ApiResponse::error('Failed to get server resources: ' . $e->getMessage(), 'ERROR', 500);
        }
    }

    #[OA\Patch(
        path: '/api/user/servers/{uuidShort}/billingresources',
        summary: 'Update server resources',
        description: 'Update resources for a server, ensuring they do not exceed available user limits',
        tags: ['User - Server Resources'],
        parameters: [
            new OA\Parameter(
                name: 'uuidShort',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string'),
                description: 'Server short UUID'
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
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Server not found'),
        ]
    )]
    public function updateServerResources(Request $request, string $serverUuid): Response
    {
        try {
            $user = $this->validateUser($request);
            $server = $this->validateServer($serverUuid);

            // Check permission - allow owners and subusers with settings permission
            if ((int) $server['owner_id'] !== (int) $user['id']) {
                $permissionCheck = $this->checkPermission($request, $server, SubuserPermissions::SETTINGS_RENAME);
                if ($permissionCheck !== null) {
                    return $permissionCheck;
                }
            }

            // Get request data
            $data = json_decode($request->getContent(), true);
            if (!is_array($data)) {
                return ApiResponse::error('Invalid request body', 'INVALID_BODY', 400);
            }

            $userId = (int) $user['id'];

            // Ensure user has resources in DB (creates with defaults if missing)
            $resources = ResourcesHelper::ensureUserResources($userId);
            if ($resources === null) {
                return ApiResponse::error('Failed to load user resources', 'RESOURCE_LOAD_ERROR', 500);
            }

            // Get user limits (now guaranteed to exist in DB)
            $limits = ResourcesHelper::getUserResourcesOrDefault($userId);

            // Check if user already has overflow - if so, block all updates
            $overflowCheck = ResourcesHelper::checkResourceOverflow($userId);
            if ($overflowCheck['has_overflow']) {
                $overflowResources = [];
                foreach ($overflowCheck['overflow_details'] as $resourceType => $details) {
                    $overflowResources[] = $resourceType . ' (' . $details['used'] . ' / ' . $details['limit'] . ')';
                }

                return ApiResponse::error(
                    'Resource limits exceeded. Please reduce resource usage before making changes. Overflow: ' . implode(', ', $overflowResources),
                    'RESOURCE_OVERFLOW',
                    403
                );
            }

            // Calculate current usage from server LIMITS (excluding the server being updated)
            $used = ResourcesHelper::calculateUsedResourcesFromServerLimits($userId, [(int) $server['id']]);

            // Calculate available resources for THIS server (what can be allocated to it)
            // Available = limit - used_by_other_servers
            $available = ResourcesHelper::calculateAvailableResources($userId, [(int) $server['id']]);

            // Prepare update data
            $updateData = [];
            $errors = [];

            // Validate and prepare memory
            if (isset($data['memory'])) {
                $newMemory = (int) $data['memory'];
                if ($newMemory < 1) {
                    $errors[] = 'Memory must be at least 1 MB';
                } elseif ($newMemory > $limits['memory_limit'] && $limits['memory_limit'] > 0) {
                    $errors[] = 'Memory exceeds your total limit. Limit: ' . $limits['memory_limit'] . ' MB';
                } elseif ($limits['memory_limit'] < $used['memory_limit'] + $newMemory && $limits['memory_limit'] > 0) {
                    $errors[] = 'Memory would exceed your total limit. Available: ' . $available['memory_limit'] . ' MB (other servers use ' . $used['memory_limit'] . ' MB)';
                } else {
                    $updateData['memory'] = $newMemory;
                }
            }

            // Validate and prepare CPU
            if (isset($data['cpu'])) {
                $newCpu = (int) $data['cpu'];
                if ($newCpu < 1) {
                    $errors[] = 'CPU must be at least 1%';
                } elseif ($newCpu > $limits['cpu_limit'] && $limits['cpu_limit'] > 0) {
                    $errors[] = 'CPU exceeds your total limit. Limit: ' . $limits['cpu_limit'] . '%';
                } elseif ($limits['cpu_limit'] < $used['cpu_limit'] + $newCpu && $limits['cpu_limit'] > 0) {
                    $errors[] = 'CPU would exceed your total limit. Available: ' . $available['cpu_limit'] . '% (other servers use ' . $used['cpu_limit'] . '%)';
                } else {
                    $updateData['cpu'] = $newCpu;
                }
            }

            // Validate and prepare disk
            if (isset($data['disk'])) {
                $newDisk = (int) $data['disk'];
                if ($newDisk < 1) {
                    $errors[] = 'Disk must be at least 1 MB';
                } elseif ($newDisk > $limits['disk_limit'] && $limits['disk_limit'] > 0) {
                    $errors[] = 'Disk exceeds your total limit. Limit: ' . $limits['disk_limit'] . ' MB';
                } elseif ($limits['disk_limit'] < $used['disk_limit'] + $newDisk && $limits['disk_limit'] > 0) {
                    $errors[] = 'Disk would exceed your total limit. Available: ' . $available['disk_limit'] . ' MB (other servers use ' . $used['disk_limit'] . ' MB)';
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
                    $currentDatabases = ServerDatabase::getDatabasesByServerId((int) $server['id']);
                    $currentDbCount = count($currentDatabases);
                    if ($newDbLimit < $currentDbCount) {
                        $errors[] = "Database limit cannot be less than current databases ({$currentDbCount})";
                    } elseif ($newDbLimit > $limits['database_limit'] && $limits['database_limit'] > 0) {
                        $errors[] = 'Database limit exceeds your total limit. Limit: ' . $limits['database_limit'];
                    } elseif ($limits['database_limit'] < $used['database_limit'] + $newDbLimit && $limits['database_limit'] > 0) {
                        $errors[] = 'Database limit would exceed your total limit. Available: ' . $available['database_limit'] . ' (other servers use ' . $used['database_limit'] . ')';
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
                    $currentBackups = Backup::getBackupsByServerId((int) $server['id']);
                    $currentBackupCount = count($currentBackups);
                    if ($newBackupLimit < $currentBackupCount) {
                        $errors[] = "Backup limit cannot be less than current backups ({$currentBackupCount})";
                    } elseif ($newBackupLimit > $limits['backup_limit'] && $limits['backup_limit'] > 0) {
                        $errors[] = 'Backup limit exceeds your total limit. Limit: ' . $limits['backup_limit'];
                    } elseif ($limits['backup_limit'] < $used['backup_limit'] + $newBackupLimit && $limits['backup_limit'] > 0) {
                        $errors[] = 'Backup limit would exceed your total limit. Available: ' . $available['backup_limit'] . ' (other servers use ' . $used['backup_limit'] . ')';
                    } else {
                        $updateData['backup_limit'] = $newBackupLimit;
                    }
                }
            }

            // Validate and prepare allocation_limit
            if (isset($data['allocation_limit'])) {
                $newAllocLimit = (int) $data['allocation_limit'];
                if ($newAllocLimit < 1) {
                    $errors[] = 'Allocation limit must be at least 1';
                } else {
                    $currentAllocations = Allocation::getByServerId((int) $server['id']);
                    $currentAllocCount = count($currentAllocations);
                    if ($newAllocLimit < $currentAllocCount) {
                        $errors[] = "Allocation limit cannot be less than current allocations ({$currentAllocCount})";
                    } elseif ($newAllocLimit > $limits['allocation_limit'] && $limits['allocation_limit'] > 0) {
                        $errors[] = 'Allocation limit exceeds your total limit. Limit: ' . $limits['allocation_limit'];
                    } elseif ($limits['allocation_limit'] < $used['allocation_limit'] + $newAllocLimit && $limits['allocation_limit'] > 0) {
                        $errors[] = 'Allocation limit would exceed your total limit. Available: ' . $available['allocation_limit'] . ' (other servers use ' . $used['allocation_limit'] . ')';
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
            if (!Server::updateServerById((int) $server['id'], $updateData)) {
                return ApiResponse::error('Failed to update server resources', 'UPDATE_FAILED', 500);
            }

            return ApiResponse::success([
                'server_id' => (int) $server['id'],
                'updated' => $updateData,
            ], 'Server resources updated successfully', 200);
        } catch (\Exception $e) {
            App::getInstance(true)->getLogger()->error('Error updating server resources: ' . $e->getMessage());

            return ApiResponse::error('Failed to update server resources: ' . $e->getMessage(), 'ERROR', 500);
        }
    }

    /**
     * Helper method to validate user authentication.
     */
    private function validateUser(Request $request): array
    {
        $user = $request->attributes->get('user');
        if (!$user) {
            throw new \Exception('User not authenticated', 401);
        }

        return $user;
    }

    /**
     * Helper method to get and validate server.
     */
    private function validateServer(string $serverUuid): array
    {
        $server = Server::getServerByUuidShort($serverUuid);
        if (!$server) {
            throw new \Exception('Server not found', 404);
        }

        return $server;
    }
}

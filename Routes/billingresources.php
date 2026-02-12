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

use App\App;
use App\Permissions;
use App\Helpers\ApiResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouteCollection;
use App\Addons\billingresources\Controllers\User\ServerResourcesController;
use App\Addons\billingresources\Controllers\User\BillingResourcesController as UserController;
use App\Addons\billingresources\Controllers\Admin\BillingResourcesController as AdminController;

return function (RouteCollection $routes): void {
    // User Routes (require authentication)
    // Get user's resources
    App::getInstance(true)->registerAuthRoute(
        $routes,
        'billingresources-user-resources',
        '/api/user/billingresources/resources',
        function (Request $request) {
            return (new UserController())->getResources($request);
        },
        ['GET']
    );

    // Get specific user resource
    App::getInstance(true)->registerAuthRoute(
        $routes,
        'billingresources-user-resource',
        '/api/user/billingresources/resources/{resourceType}',
        function (Request $request, array $args) {
            $resourceType = $args['resourceType'] ?? '';
            if (empty($resourceType)) {
                return ApiResponse::error('Resource type is required', 'RESOURCE_TYPE_REQUIRED', 400);
            }

            return (new UserController())->getResource($request, $resourceType);
        },
        ['GET']
    );

    // Get user servers with resources
    App::getInstance(true)->registerAuthRoute(
        $routes,
        'billingresources-user-servers',
        '/api/user/billingresources/servers',
        function (Request $request) {
            return (new UserController())->getServers($request);
        },
        ['GET']
    );

    // Server Routes (with server middleware for security)
    // Get server resources with available limits
    App::getInstance(true)->registerServerRoute(
        $routes,
        'billingresources-server-resources',
        '/api/user/servers/{uuidShort}/billingresources',
        function (Request $request, array $args) {
            $uuidShort = $args['uuidShort'] ?? null;
            if (!$uuidShort) {
                return ApiResponse::error('Missing or invalid UUID short', 'INVALID_UUID_SHORT', 400);
            }

            return (new ServerResourcesController())->getServerResources($request, $uuidShort);
        },
        'uuidShort',
        ['GET']
    );

    // Update server resources
    App::getInstance(true)->registerServerRoute(
        $routes,
        'billingresources-server-resources-update',
        '/api/user/servers/{uuidShort}/billingresources',
        function (Request $request, array $args) {
            $uuidShort = $args['uuidShort'] ?? null;
            if (!$uuidShort) {
                return ApiResponse::error('Missing or invalid UUID short', 'INVALID_UUID_SHORT', 400);
            }

            return (new ServerResourcesController())->updateServerResources($request, $uuidShort);
        },
        'uuidShort',
        ['PATCH', 'PUT']
    );

    // Admin Routes
    // Get all users with resources
    App::getInstance(true)->registerAdminRoute(
        $routes,
        'billingresources-admin-users',
        '/api/admin/billingresources/users',
        function (Request $request) {
            return (new AdminController())->getUsers($request);
        },
        Permissions::ADMIN_USERS_VIEW,
        ['GET']
    );

    // Get single user resources
    App::getInstance(true)->registerAdminRoute(
        $routes,
        'billingresources-admin-user-resources',
        '/api/admin/billingresources/users/{userId}/resources',
        function (Request $request, array $args) {
            $userId = (int) ($args['userId'] ?? 0);
            if (!$userId) {
                return ApiResponse::error('Invalid user ID', 'INVALID_ID', 400);
            }

            return (new AdminController())->getUserResources($request, $userId);
        },
        Permissions::ADMIN_USERS_VIEW,
        ['GET']
    );

    // Update user resources
    App::getInstance(true)->registerAdminRoute(
        $routes,
        'billingresources-admin-user-resources-update',
        '/api/admin/billingresources/users/{userId}/resources',
        function (Request $request, array $args) {
            $userId = (int) ($args['userId'] ?? 0);
            if (!$userId) {
                return ApiResponse::error('Invalid user ID', 'INVALID_ID', 400);
            }

            return (new AdminController())->updateUserResources($request, $userId);
        },
        Permissions::ADMIN_USERS_EDIT,
        ['PATCH', 'PUT']
    );

    // Search users
    App::getInstance(true)->registerAdminRoute(
        $routes,
        'billingresources-admin-search-users',
        '/api/admin/billingresources/users/search',
        function (Request $request) {
            return (new AdminController())->searchUsers($request);
        },
        Permissions::ADMIN_USERS_VIEW,
        ['GET']
    );

    // Get statistics
    App::getInstance(true)->registerAdminRoute(
        $routes,
        'billingresources-admin-statistics',
        '/api/admin/billingresources/statistics',
        function (Request $request) {
            return (new AdminController())->getStatistics($request);
        },
        Permissions::ADMIN_USERS_VIEW,
        ['GET']
    );

    // Get settings
    App::getInstance(true)->registerAdminRoute(
        $routes,
        'billingresources-admin-settings',
        '/api/admin/billingresources/settings',
        function (Request $request) {
            return (new AdminController())->getSettings($request);
        },
        Permissions::ADMIN_USERS_VIEW,
        ['GET']
    );

    // Update settings
    App::getInstance(true)->registerAdminRoute(
        $routes,
        'billingresources-admin-settings-update',
        '/api/admin/billingresources/settings',
        function (Request $request) {
            return (new AdminController())->updateSettings($request);
        },
        Permissions::ADMIN_USERS_EDIT,
        ['PATCH', 'PUT']
    );
};

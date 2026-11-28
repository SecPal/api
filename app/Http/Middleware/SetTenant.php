<?php

/*
 * SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * SetTenant middleware extracts tenant ID from request path or header,
 * validates tenant membership, and sets the permissions team ID for RBAC.
 */
class SetTenant
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $selection = $request->route('tenant') ?? $request->header('X-Tenant');

        if ($selection === null) {
            return response()->json([
                'message' => __('Tenant ID is required. Please provide tenant ID in path (/tenants/{tenant}) or X-Tenant header.'),
            ], 400);
        }

        $tenantId = $selection instanceof Tenant ? $selection->id : Tenant::parseId($selection);
        if ($tenantId === null || ! Tenant::whereKey($tenantId)->exists()) {
            return response()->json([
                'message' => __('Tenant not found. The specified tenant does not exist.'),
            ], 404);
        }

        if (($request->user()?->tenant_id) !== null && (int) $request->user()->tenant_id !== $tenantId) {
            return response()->json([
                'message' => __('Forbidden. You do not belong to the specified tenant.'),
            ], 403);
        }

        // Set tenant for Spatie Permission (team-based permissions)
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

        // Store tenant ID in request for use in controllers
        $request->merge(['tenant_id' => $tenantId]);

        return $next($request);
    }
}

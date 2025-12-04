<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Http\Middleware;

use App\Models\TenantKey;
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
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = $this->extractTenantId($request);

        if ($tenantId === null) {
            return response()->json([
                'message' => 'Tenant ID is required. Please provide tenant ID in path (/tenants/{tenant}) or X-Tenant header.',
            ], 400);
        }

        // Verify tenant exists
        if (! TenantKey::where('id', $tenantId)->exists()) {
            return response()->json([
                'message' => 'Tenant not found. The specified tenant does not exist.',
            ], 404);
        }

        // Set tenant for Spatie Permission (team-based permissions)
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

        // Store tenant ID in request for use in controllers
        $request->merge(['tenant_id' => $tenantId]);

        return $next($request);
    }

    /**
     * Extract tenant ID from request path or header.
     *
     * Priority: path parameter > X-Tenant header
     */
    private function extractTenantId(Request $request): ?int
    {
        // Try to get from route parameter first
        $tenantId = $request->route('tenant');

        if ($tenantId !== null) {
            return (int) $tenantId;
        }

        // Fallback to X-Tenant header
        $headerTenant = $request->header('X-Tenant');

        if ($headerTenant !== null) {
            return (int) $headerTenant;
        }

        return null;
    }
}

<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Middleware;

use App\Models\TenantKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * InjectTenantId middleware injects tenant_id into the request.
 *
 * SINGLE-TENANT DEVELOPMENT MODE:
 * Currently uses the first available TenantKey. This is NOT production-ready
 * for multi-tenant deployments.
 *
 * FUTURE PRODUCTION IMPLEMENTATION:
 * - Extract tenant_id from authenticated user's tenant relationship
 * - Support subdomain-based tenant resolution
 * - Support JWT claim-based tenant resolution
 *
 * @see https://github.com/SecPal/api/issues/190
 */
class InjectTenantId
{
    /**
     * Handle an incoming request.
     *
     * SECURITY: Always overrides client-provided tenant_id to prevent cross-tenant attacks.
     * In single-tenant mode, uses first available TenantKey.
     * In multi-tenant production, should resolve from authenticated user.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // SECURITY FIX: Remove any client-provided tenant_id to prevent spoofing
        // Only SetTenant middleware (which validates tenant from route/header) should set tenant_id
        $request->request->remove('tenant_id');
        $request->query->remove('tenant_id');

        // SINGLE-TENANT MODE: Use first available tenant
        // TODO: Replace with user-based tenant resolution for multi-tenant production
        $tenantId = TenantKey::oldest('id')->value('id');

        if ($tenantId === null) {
            return response()->json([
                'message' => __('No tenant keys available. Please ensure at least one tenant key is configured.'),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        // Inject tenant_id into request
        $request->merge(['tenant_id' => $tenantId]);

        // Set tenant for Spatie Permission (team-based permissions)
        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

        return $next($request);
    }
}

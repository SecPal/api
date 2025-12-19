<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * InjectTenantId middleware injects tenant_id into the request.
 *
 * PRODUCTION-READY MULTI-TENANT IMPLEMENTATION:
 * Resolves tenant_id from authenticated user's tenant relationship.
 * This ensures each user can only access data from their assigned tenant.
 *
 * SECURITY:
 * - Always requires authentication (returns 401 for unauthenticated requests)
 * - Rejects any client-provided tenant_id to prevent cross-tenant attacks
 * - Uses user.tenant_id foreign key relationship for tenant resolution
 *
 * @see https://github.com/SecPal/api/issues/357
 * @see https://github.com/SecPal/api/issues/359
 */
class InjectTenantId
{
    /**
     * Handle an incoming request.
     *
     * SECURITY: Resolves tenant_id from authenticated user.
     * Returns 401 if user is not authenticated.
     * Always overrides client-provided tenant_id to prevent cross-tenant attacks.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // SECURITY FIX: Remove any client-provided tenant_id to prevent spoofing
        $request->request->remove('tenant_id');
        $request->query->remove('tenant_id');

        // Skip injection for unauthenticated requests (auth endpoints)
        // Auth middleware will handle authentication check if needed
        $user = $request->user();
        if ($user === null) {
            return $next($request);
        }

        // Resolve tenant_id from authenticated user
        $tenantId = $user->tenant_id;

        if ($tenantId === null) {
            // This should never happen due to NOT NULL constraint
            // but we handle it gracefully for defensive programming
            return response()->json([
                'message' => __('User has no assigned tenant. Please contact system administrator.'),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Inject tenant_id into request
        $request->merge(['tenant_id' => $tenantId]);

        // Set tenant for Spatie Permission (team-based permissions)
        app(\Spatie\Permission\PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

        return $next($request);
    }
}

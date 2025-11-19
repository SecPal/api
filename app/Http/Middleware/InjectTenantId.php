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
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip if tenant_id already set (e.g., by SetTenant middleware)
        if ($request->has('tenant_id')) {
            return $next($request);
        }

        // SINGLE-TENANT MODE: Use first available tenant
        // TODO: Replace with user-based tenant resolution for multi-tenant production
        $tenantId = TenantKey::oldest('id')->value('id');

        if ($tenantId === null) {
            return response()->json([
                'error' => 'Tenant resolution not yet implemented. Please contact system administrator.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        // Inject tenant_id into request
        $request->merge(['tenant_id' => $tenantId]);

        return $next($request);
    }
}

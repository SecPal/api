<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to extract and validate tenant_id from route parameters.
 *
 * Sets:
 * - $request->tenant_id (validated UUID)
 * - Spatie Permission team context via setPermissionsTeamId()
 *
 * SECURITY:
 * - Validates UUID format
 * - Normalizes to lowercase
 * - Sets team context for RBAC isolation
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
        // Extract tenant_id from route parameter
        $tenantId = $request->route('tenant');

        if (! $tenantId) {
            return response()->json([
                'error' => 'tenant_id is required',
            ], 400);
        }

        // Validate UUID format using Laravel's built-in validator
        if (! Str::isUuid($tenantId)) {
            return response()->json([
                'error' => 'Invalid tenant_id format',
            ], 400);
        }

        // Normalize to lowercase
        $tenantId = strtolower($tenantId);

        // Set on request for downstream usage
        $request->merge(['tenant_id' => $tenantId]);

        // Set Spatie Permission team context (RBAC isolation)
        setPermissionsTeamId($tenantId);

        Log::debug('Tenant context set', [
            'tenant_id' => $tenantId,
            'route' => $request->path(),
        ]);

        return $next($request);
    }
}

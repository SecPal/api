<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Http\Middleware;

use App\Models\OrganizationalUnit;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CheckOrganizationalScope middleware verifies that the authenticated user
 * has access to the organizational unit specified in the route.
 *
 * This middleware uses the hierarchical scope system to determine access:
 * - Direct scope: User has explicit access to the unit
 * - Hierarchical scope: User has access to an ancestor unit with include_descendants = true
 *
 * Access levels (ascending):
 * - none (0): No access
 * - read (1): Can view organizational unit details
 * - write (2): Can update organizational unit properties
 * - manage (3): Full control including deletion and scope management
 *
 * Usage in routes:
 *   Route::middleware('check.organizational.scope:read')->get('...');
 *   Route::middleware('check.organizational.scope:write')->put('...');
 *   Route::middleware('check.organizational.scope:manage')->delete('...');
 */
class CheckOrganizationalScope
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     * @param  string  $requiredLevel  The minimum access level required (default: 'read')
     */
    public function handle(Request $request, Closure $next, string $requiredLevel = 'read'): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            return $this->unauthorizedResponse(__('Authentication required'));
        }

        $unitId = $this->extractOrganizationalUnitId($request);

        if ($unitId === null) {
            return $this->notFoundResponse(__('Organizational unit ID not provided'));
        }

        // Validate UUID format before querying database
        if (! $this->isValidUuid($unitId)) {
            return $this->notFoundResponse(__('Invalid organizational unit ID format'));
        }

        $unit = OrganizationalUnit::find($unitId);

        if ($unit === null) {
            return $this->notFoundResponse(__('Organizational unit not found'));
        }

        if (! $user->hasAccessToUnit($unit, $requiredLevel)) {
            return $this->forbiddenResponse($requiredLevel);
        }

        // Store the loaded unit in request attributes for controller use
        $request->attributes->set('organizational_unit', $unit);

        return $next($request);
    }

    /**
     * Extract the organizational unit ID from the request.
     *
     * Looks for route parameter 'organizational_unit' or 'unit'.
     */
    private function extractOrganizationalUnitId(Request $request): ?string
    {
        $route = $request->route();

        if ($route === null) {
            return null;
        }

        // Try common parameter names
        $param = $route->parameter('organizational_unit')
            ?? $route->parameter('unit')
            ?? $route->parameter('organizationalUnit');

        // Handle both string and model instances
        if ($param instanceof OrganizationalUnit) {
            return $param->id;
        }

        return is_string($param) ? $param : null;
    }

    /**
     * Return a 401 Unauthorized response.
     */
    private function unauthorizedResponse(string $message): Response
    {
        return response()->json([
            'message' => $message,
        ], 401);
    }

    /**
     * Return a 403 Forbidden response.
     */
    private function forbiddenResponse(string $requiredLevel): Response
    {
        return response()->json([
            'message' => __('Insufficient access level. Required: :level', ['level' => $requiredLevel]),
        ], 403);
    }

    /**
     * Return a 404 Not Found response.
     */
    private function notFoundResponse(string $message): Response
    {
        return response()->json([
            'message' => $message,
        ], 404);
    }

    /**
     * Check if a string is a valid UUID v4.
     */
    private function isValidUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }
}

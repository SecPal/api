<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace App\Http\Middleware;

use App\Models\Employee;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensure Not Pre-Contract Middleware
 *
 * Blocks pre-contract users from accessing operational endpoints.
 *
 * Pre-contract employees have limited access:
 * - ❌ Cannot access operational endpoints (employees, shifts, customers, etc.)
 * - ✅ Can only access onboarding endpoints
 *
 * This middleware should be applied to operational routes to prevent
 * pre-contract users from accessing them before completing onboarding.
 */
class EnsureNotPreContract
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // If no user, let authentication middleware handle it
        if ($user === null) {
            return $next($request);
        }

        /** @var Employee|null $employee */
        $employee = $user->employee()->first();

        // If user has no employee record, allow access (might be HR or another privileged operator)
        if ($employee === null) {
            return $next($request);
        }

        // Deny access for pre-contract employees
        if ($employee->status === 'pre_contract') {
            abort(403, __('Access denied. Please complete onboarding first before accessing operational features.'));
        }

        return $next($request);
    }
}

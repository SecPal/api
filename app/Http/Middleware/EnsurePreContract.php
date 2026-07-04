<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace App\Http\Middleware;

use App\Models\Employee;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensure Pre-Contract Middleware
 *
 * Restricts pre-contract users to onboarding endpoints only.
 *
 * Pre-contract employees have limited access:
 * - ✅ Can access /api/v1/onboarding/* endpoints
 * - ❌ Cannot access operational endpoints
 *
 * This middleware should be applied to onboarding routes to ensure
 * only pre-contract users can access them.
 */
class EnsurePreContract
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

        // If user has no employee record, deny access
        if ($employee === null) {
            abort(403, __('Access denied. You must be an employee to access this resource.'));
        }

        // Only pre-contract employees can access onboarding endpoints
        if ($employee->status !== 'pre_contract') {
            abort(403, __('Access denied. Onboarding is only available for pre-contract employees.'));
        }

        return $next($request);
    }
}

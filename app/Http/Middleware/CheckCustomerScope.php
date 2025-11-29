<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Middleware;

use App\Models\Customer;
use App\Models\CustomerUserAccess;
use App\Models\CustomerUserObjectAccess;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CheckCustomerScope middleware ensures customer users have valid access assignments.
 *
 * This middleware is designed for routes accessed by customer users (Client role):
 * - Verifies the user has at least one CustomerUserAccess or CustomerUserObjectAccess record
 * - For specific customer routes, validates access to the requested customer
 *
 * Usage in routes:
 *   Route::middleware('check.customer.scope')->get('/customers', ...);
 *   Route::middleware('check.customer.scope')->get('/customers/{customer}', ...);
 *
 * The middleware:
 * 1. Only applies to users with the Client role (passes through for internal employees)
 * 2. Requires at least one customer or object access record
 * 3. For specific customer routes, validates hierarchical access
 *
 * @see ADR-007 for customer hierarchy and access control design
 */
class CheckCustomerScope
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            return $this->unauthorizedResponse('Authentication required');
        }

        // Only apply to customer users (Client role)
        if (! $user->hasRole('Client')) {
            return $next($request);
        }

        // Check if user has at least one customer access or object access
        $hasCustomerAccess = CustomerUserAccess::where('user_id', $user->id)->exists();
        $hasObjectAccess = CustomerUserObjectAccess::where('user_id', $user->id)->exists();

        if (! $hasCustomerAccess && ! $hasObjectAccess) {
            return $this->forbiddenResponse('No customer access assigned');
        }

        // If route has a customer parameter, validate access to that specific customer
        $customerId = $this->extractCustomerId($request);
        if ($customerId !== null) {
            if (! $this->isValidUuid($customerId)) {
                return $this->notFoundResponse('Invalid customer ID format');
            }

            $customer = Customer::find($customerId);
            if ($customer === null) {
                return $this->notFoundResponse('Customer not found');
            }

            if (! $this->userHasAccessToCustomer($user, $customer)) {
                return $this->forbiddenResponse('Access denied to this customer');
            }

            // Store the loaded customer in request attributes for controller use
            $request->attributes->set('customer', $customer);
        }

        return $next($request);
    }

    /**
     * Check if user has access to the specific customer.
     *
     * Access is granted if:
     * 1. User has CustomerUserAccess with exact customer_id match
     * 2. User has CustomerUserAccess with include_descendants=true and customer is descendant
     * 3. User has CustomerUserObjectAccess for an object belonging to this customer
     */
    private function userHasAccessToCustomer(User $user, Customer $customer): bool
    {
        $accesses = CustomerUserAccess::with('customer')->where('user_id', $user->id)->get();

        foreach ($accesses as $access) {
            // Skip if tenant doesn't match (defense-in-depth)
            if ($access->tenant_id !== $customer->tenant_id) {
                continue;
            }

            if ($access->include_descendants) {
                // Check if customer is the assigned customer or a descendant
                if ($access->customer_id === $customer->id) {
                    return true;
                }

                $accessCustomer = $access->customer;
                if ($accessCustomer !== null && $accessCustomer->isAncestorOf($customer)) {
                    return true;
                }
            } else {
                // Exact match only
                if ($access->customer_id === $customer->id) {
                    return true;
                }
            }
        }

        // Check if user has object-level access to any object of this customer
        $hasObjectAccessToCustomer = CustomerUserObjectAccess::where('user_id', $user->id)
            ->whereHas('object', function ($query) use ($customer) {
                $query->where('customer_id', $customer->id);
            })
            ->exists();

        return $hasObjectAccessToCustomer;
    }

    /**
     * Extract the customer ID from the request.
     *
     * Looks for route parameter 'customer' or 'customer_id'.
     */
    private function extractCustomerId(Request $request): ?string
    {
        $route = $request->route();

        if ($route === null) {
            return null;
        }

        // Try common parameter names
        $param = $route->parameter('customer')
            ?? $route->parameter('customer_id');

        // Handle both string and model instances
        if ($param instanceof Customer) {
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
            'error' => 'Unauthorized',
            'message' => $message,
        ], 401);
    }

    /**
     * Return a 403 Forbidden response.
     */
    private function forbiddenResponse(string $message): Response
    {
        return response()->json([
            'error' => 'Access denied',
            'message' => $message,
        ], 403);
    }

    /**
     * Return a 404 Not Found response.
     */
    private function notFoundResponse(string $message): Response
    {
        return response()->json([
            'error' => 'Not found',
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

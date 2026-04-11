<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Assignment\StoreCustomerAssignmentRequest;
use App\Http\Requests\Api\V1\Assignment\UpdateAssignmentRequest;
use App\Http\Resources\Api\V1\CustomerAssignmentResource;
use App\Models\Customer;
use App\Models\CustomerAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for managing customer assignments.
 *
 * Handles flexible user-to-customer role assignments with customizable role names.
 * Allows organizations to use their own terminology (e.g., "Key Account Manager",
 * "Sales Representative", "Billing Contact").
 *
 * @see CustomerAssignment
 * @see SecPal/api#315 Assignment API endpoints
 * @see SecPal/.github#210 Customer & Site Management Epic
 */
class CustomerAssignmentController extends Controller
{
    /**
     * List assignments for a customer.
     *
     * Returns all user assignments for the specified customer, optionally filtered
     * by role or active status. Includes eager-loaded user relationship.
     *
     * Query Parameters:
     * - role (string): Filter by specific role name
     * - active_only (boolean): Only include currently active assignments
     *
     * @return JsonResponse Array of CustomerAssignmentResource
     */
    public function index(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('view', $customer);

        $assignments = $customer->assignments()
            ->with(['user', 'customer'])
            ->when($request->has('role'), fn ($q) => $q->where('role', $request->input('role')))
            ->when($request->boolean('active_only'), fn ($q) => $q->currentlyActive())
            ->get();

        return response()->json([
            'data' => CustomerAssignmentResource::collection($assignments),
        ]);
    }

    /**
     * Create a new customer assignment.
     *
     * Assigns a user to a customer with a flexible role name. Prevents duplicate
     * assignments (same user + role combination) and returns 409 Conflict if
     * the assignment already exists.
     *
     * @return JsonResponse CustomerAssignmentResource (201 Created) or error (409 Conflict)
     */
    public function store(StoreCustomerAssignmentRequest $request, Customer $customer): JsonResponse
    {
        $this->authorize('create', [CustomerAssignment::class, $customer]);

        $validated = $request->validated();
        $validated['tenant_id'] = $request->input('tenant_id');
        $validated['customer_id'] = $customer->id;

        // Check for existing assignment with same user+role
        $existing = CustomerAssignment::where('customer_id', $customer->id)
            ->where('user_id', $validated['user_id'])
            ->where('role', $validated['role'])
            ->first();

        if ($existing !== null) {
            return response()->json([
                'message' => 'Assignment already exists for this user and role',
            ], Response::HTTP_CONFLICT);
        }

        $assignment = CustomerAssignment::create($validated);

        return response()->json([
            'data' => new CustomerAssignmentResource($assignment->load(['user', 'customer'])),
        ], Response::HTTP_CREATED);
    }

    /**
     * Update an existing customer assignment.
     *
     * Allows updating role, validity period, and notes. Uses PATCH semantics
     * (all fields optional). Authorization checks that user can update the parent customer.
     *
     * @return JsonResponse CustomerAssignmentResource (200 OK)
     */
    public function update(UpdateAssignmentRequest $request, CustomerAssignment $customerAssignment): JsonResponse
    {
        $this->authorize('update', $customerAssignment);

        $customerAssignment->update($request->validated());

        return response()->json([
            'data' => new CustomerAssignmentResource($customerAssignment->fresh(['user', 'customer'])),
        ]);
    }

    /**
     * Delete a customer assignment.
     *
     * Permanently removes the assignment. Authorization checks that user can
     * update the parent customer.
     *
     * @return JsonResponse Empty response (204 No Content)
     */
    public function destroy(CustomerAssignment $customerAssignment): Response|JsonResponse
    {
        $this->authorize('delete', $customerAssignment);

        $customerAssignment->delete();

        return response()->noContent();
    }
}

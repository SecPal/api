<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreCustomerRequest;
use App\Http\Requests\Api\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * CustomerController handles CRUD operations for customers.
 *
 * This controller manages external customer hierarchies. Customer users (Client role)
 * have read-only access to their assigned customer hierarchy. Internal employees
 * have full CRUD access based on their organizational unit scopes.
 *
 * All operations are protected by CustomerPolicy for authorization.
 */
class CustomerController extends Controller
{
    /**
     * Display a listing of customers.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Customer::class);

        /** @var int $tenantId */
        $tenantId = $request->input('tenant_id');

        /** @var \App\Models\User $user */
        $user = $request->user();

        $query = Customer::where('tenant_id', $tenantId);

        // Filter by type if provided
        if ($request->has('type')) {
            $query->where('type', $request->input('type'));
        }

        // Filter by managed_by if provided (only for internal employees)
        if ($request->has('managed_by') && ! $user->hasRole('Client')) {
            $query->where('managed_by_organizational_unit_id', $request->input('managed_by'));
        }

        // Apply scoping for customer users
        if ($user->hasRole('Client')) {
            $accessibleCustomerIds = $this->getAccessibleCustomerIds($user);
            $query->whereIn('id', $accessibleCustomerIds);
        }

        $customers = $query->paginate($request->integer('per_page', 15));

        return response()->json([
            'data' => CustomerResource::collection($customers),
            'meta' => [
                'current_page' => $customers->currentPage(),
                'last_page' => $customers->lastPage(),
                'per_page' => $customers->perPage(),
                'total' => $customers->total(),
            ],
        ]);
    }

    /**
     * Store a newly created customer.
     */
    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $this->authorize('create', Customer::class);

        /** @var int $tenantId */
        $tenantId = $request->input('tenant_id');

        /** @var array{name: string, customer_number: string, type: string, address?: string|null, contact_email?: string|null, contact_phone?: string|null, metadata?: array<mixed>|null, parent_id?: string|null, managed_by_organizational_unit_id?: string|null} $validated */
        $validated = $request->validated();

        $customer = Customer::create([
            'tenant_id' => $tenantId,
            'name' => $validated['name'],
            'customer_number' => $validated['customer_number'],
            'type' => $validated['type'],
            'address' => $validated['address'] ?? null,
            'contact_email' => $validated['contact_email'] ?? null,
            'contact_phone' => $validated['contact_phone'] ?? null,
            'metadata' => $validated['metadata'] ?? null,
            'managed_by_organizational_unit_id' => $validated['managed_by_organizational_unit_id'] ?? null,
        ]);

        // Attach parent if specified
        $parentId = $validated['parent_id'] ?? null;
        if ($parentId !== null) {
            $parent = Customer::findOrFail($parentId);
            $customer->setParent($parent);
        }

        return response()->json([
            'data' => new CustomerResource($customer),
        ], Response::HTTP_CREATED);
    }

    /**
     * Display the specified customer.
     */
    public function show(Customer $customer): JsonResponse
    {
        $this->authorize('view', $customer);

        return response()->json([
            'data' => new CustomerResource($customer),
        ]);
    }

    /**
     * Update the specified customer.
     */
    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        $this->authorize('update', $customer);

        /** @var array{name?: string, customer_number?: string, type?: string, address?: string|null, contact_email?: string|null, contact_phone?: string|null, metadata?: array<mixed>|null, managed_by_organizational_unit_id?: string|null} $validated */
        $validated = $request->validated();

        $customer->update($validated);

        return response()->json([
            'data' => new CustomerResource($customer->fresh()),
        ]);
    }

    /**
     * Remove the specified customer (soft delete).
     */
    public function destroy(Customer $customer): JsonResponse
    {
        $this->authorize('delete', $customer);

        $customer->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Get all descendants of the customer.
     */
    public function descendants(Customer $customer): JsonResponse
    {
        $this->authorize('view', $customer);

        $descendants = $customer->descendants()->get();

        return response()->json([
            'data' => CustomerResource::collection($descendants),
        ]);
    }

    /**
     * Get all ancestors of the customer.
     */
    public function ancestors(Customer $customer): JsonResponse
    {
        $this->authorize('view', $customer);

        $ancestors = $customer->ancestors()->get();

        return response()->json([
            'data' => CustomerResource::collection($ancestors),
        ]);
    }

    /**
     * Attach a parent to the customer.
     */
    public function attachParent(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('update', $customer);

        $request->validate([
            'parent_id' => ['required', 'uuid', 'exists:customers,id'],
        ]);

        /** @var string $parentId */
        $parentId = $request->input('parent_id');
        $parent = Customer::findOrFail($parentId);

        $customer->setParent($parent);

        return response()->json([
            'data' => new CustomerResource($customer->fresh()),
        ]);
    }

    /**
     * Detach a parent from the customer.
     */
    public function detachParent(Customer $customer, Customer $parent): JsonResponse
    {
        $this->authorize('update', $customer);

        $customer->removeParent();

        return response()->json([
            'data' => new CustomerResource($customer->fresh()),
        ]);
    }

    /**
     * Get accessible customer IDs for a customer user.
     *
     * @return list<string>
     */
    private function getAccessibleCustomerIds(\App\Models\User $user): array
    {
        /** @var list<string> $customerIds */
        $customerIds = [];

        // Get direct customer access
        $accesses = \App\Models\CustomerUserAccess::where('user_id', $user->id)->get();

        foreach ($accesses as $access) {
            $customerIds[] = $access->customer_id;

            if ($access->include_descendants) {
                // Add all descendant customer IDs
                /** @var list<string> $descendantIds */
                $descendantIds = \App\Models\CustomerClosure::where('ancestor_id', $access->customer_id)
                    ->where('depth', '>', 0)
                    ->pluck('descendant_id')
                    ->toArray();
                $customerIds = array_merge($customerIds, $descendantIds);
            }
        }

        return array_values(array_unique($customerIds));
    }
}

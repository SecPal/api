<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexCustomerSitesRequest;
use App\Http\Requests\Api\V1\StoreCustomerRequest;
use App\Http\Requests\Api\V1\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\SiteResource;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * CustomerController handles Customer resource CRUD operations.
 *
 * Implements access control via CustomerPolicy:
 * - Users can only see customers they are assigned to OR
 * - Users with access to at least one site of the customer
 * - Full CRUD requires appropriate permissions
 *
 * @see SecPal/.github#210 Customer & Site Management Epic
 * @see SecPal/api#313 Customer CRUD API endpoints
 */
class CustomerController extends Controller
{
    /**
     * Display a listing of customers.
     *
     * GET /api/v1/customers
     *
     * Returns paginated list of accessible customers based on:
     * - Direct customer assignments (currently active)
     * - Access via site organizational units
     * - 403 when user has no effective collection access at all
     *
     * Supports filtering by:
     * - search (name, customer_number)
     * - is_active (boolean)
     *
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection Paginated customer list with metadata
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Customer::class);

        /** @var \App\Models\User $user */
        $user = $request->user();
        /** @var int $tenantId */
        $tenantId = $request->get('tenant_id');

        $query = Customer::query()
            ->where('tenant_id', $tenantId)
            ->with(['assignments.user']);

        // Need-to-Know filtering: users reaching this branch already have scoped collection access
        if (! $user->can('customers.read')) {
            // Pre-compute accessible unit IDs and assigned site IDs to avoid repeated execution
            $accessibleUnitIds = $user->getAccessibleOrganizationalUnitIds();
            $assignedSiteIds = $user->siteAssignments()
                ->where('valid_from', '<=', now())
                ->where(function ($q) {
                    $q->whereNull('valid_until')
                        ->orWhere('valid_until', '>=', now());
                })
                ->pluck('site_id')->toArray();

            $query->where(function ($q) use ($user, $accessibleUnitIds, $assignedSiteIds) {
                // Direct assignment (must be currently active)
                $q->whereHas('assignments', function ($a) use ($user) {
                    $a->where('user_id', $user->id)
                        ->where(function ($validityQuery) {
                            $validityQuery->where('valid_from', '<=', now())
                                ->where(function ($untilQuery) {
                                    $untilQuery->whereNull('valid_until')
                                        ->orWhere('valid_until', '>=', now());
                                });
                        });
                })
                    // Or has accessible sites
                    ->orWhereHas('sites', function ($s) use ($accessibleUnitIds, $assignedSiteIds) {
                        $s->where(function ($sq) use ($accessibleUnitIds, $assignedSiteIds) {
                            $sq->whereIn('organizational_unit_id', $accessibleUnitIds)
                                ->orWhereIn('id', $assignedSiteIds);
                        });
                    });
            });
        }

        // Search filter
        if ($request->has('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('customer_number', 'ilike', "%{$search}%");
            });
        }

        // Active status filter
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $perPage = $request->integer('per_page', 15);
        $customers = $query->paginate($perPage);

        return CustomerResource::collection($customers);
    }

    /**
     * Store a newly created customer.
     *
     * POST /api/v1/customers
     *
     * Automatically generates customer_number if not provided.
     * Requires 'customers.create' permission.
     *
     * @return JsonResponse Created customer (201)
     */
    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $this->authorize('create', Customer::class);

        $validated = $request->validated();
        /** @var int $tenantId */
        $tenantId = $request->get('tenant_id');
        $validated['tenant_id'] = $tenantId;

        // Auto-generate customer_number if not provided
        if (! isset($validated['customer_number'])) {
            $validated['customer_number'] = Customer::generateCustomerNumber($tenantId);
        }

        // Set default is_active if not provided (DB default not applied when explicitly passing null)
        if (! isset($validated['is_active'])) {
            $validated['is_active'] = true;
        }

        $customer = Customer::create($validated);

        return response()->json([
            'data' => new CustomerResource($customer),
        ], Response::HTTP_CREATED);
    }

    /**
     * Display the specified customer.
     *
     * GET /api/v1/customers/{customer}
     *
     * Includes relationships:
     * - assignments with users
     * - sites (count only)
     *
     * @return JsonResponse Customer details
     */
    public function show(Customer $customer): JsonResponse
    {
        $this->authorize('view', $customer);

        $customer->load(['assignments.user', 'sites']);

        return response()->json([
            'data' => new CustomerResource($customer),
        ]);
    }

    /**
     * Update the specified customer.
     *
     * PATCH /api/v1/customers/{customer}
     *
     * Requires:
     * - Direct assignment to customer (currently active) OR
     * - 'customers.update' permission
     *
     * @return JsonResponse Updated customer
     */
    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        $this->authorize('update', $customer);

        $customer->update($request->validated());

        return response()->json([
            'data' => new CustomerResource($customer->fresh()),
        ]);
    }

    /**
     * Remove the specified customer.
     *
     * DELETE /api/v1/customers/{customer}
     *
     * Soft deletes the customer. Blocked if customer has active sites.
     * Requires 'customers.delete' permission.
     *
     * @return JsonResponse 204 No Content on success, 409 Conflict if has active sites
     */
    public function destroy(Customer $customer): JsonResponse
    {
        $this->authorize('delete', $customer);

        // Check for active sites
        if ($customer->sites()->where('is_active', true)->exists()) {
            return response()->json([
                'message' => __('Cannot delete customer with active sites.'),
                'error' => 'has_active_sites',
            ], Response::HTTP_CONFLICT);
        }

        $customer->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * List sites for a customer.
     *
     * GET /api/v1/customers/{customer}/sites
     *
     * Returns paginated list of sites belonging to the customer.
     * User must have view access to the customer.
     *
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection<int, SiteResource> Paginated site list
     */
    public function sites(IndexCustomerSitesRequest $request, Customer $customer)
    {
        $this->authorize('view', $customer);

        /** @var \App\Models\User $user */
        $user = $request->user();
        $perPage = $request->integer('per_page', 15);
        $sites = $user->can('customers.read')
            ? $customer->sites()->with(['organizationalUnit', 'assignments.user'])
            : $user->visibleSitesQuery()
                ->where('customer_id', $customer->id)
                ->with(['organizationalUnit', 'assignments.user']);

        if ($request->has('is_active')) {
            $sites->where('is_active', $request->boolean('is_active'));
        }

        if ($request->has('type')) {
            $sites->where('type', $request->string('type')->toString());
        }

        return SiteResource::collection($sites->paginate($perPage));
    }
}

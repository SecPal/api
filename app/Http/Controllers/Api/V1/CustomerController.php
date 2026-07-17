<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexCustomerRequest;
use App\Http\Requests\Api\V1\IndexCustomerSitesRequest;
use App\Http\Requests\Api\V1\StoreCustomerRequest;
use App\Http\Requests\Api\V1\UpdateCustomerRequest;
use App\Http\Resources\Api\V1\CustomerLegalEntityLookupResource;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\SiteResource;
use App\Models\Customer;
use App\Models\Site;
use App\Models\User;
use App\Services\CustomerService;
use App\Support\LikePattern;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
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
    public function __construct(
        private readonly CustomerService $customerService,
    ) {}

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
     * @return AnonymousResourceCollection Paginated customer list with metadata
     */
    public function index(IndexCustomerRequest $request)
    {
        $this->authorize('viewAny', Customer::class);

        /** @var User $user */
        $user = $request->user();
        /** @var int $tenantId */
        $tenantId = $request->get('tenant_id');

        $query = $this->customerService->visibleQuery($user, $tenantId);

        $query = $this->withVisibleSitesCount($query, $user);

        // Search filter
        if ($request->has('search')) {
            $search = $request->string('search')->toString();
            $escapedSearch = LikePattern::escape($search);
            $query->where(function ($q) use ($escapedSearch) {
                $q->where('name', 'ilike', "%{$escapedSearch}%")
                    ->orWhere('customer_number', 'ilike', "%{$escapedSearch}%");
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
     * Display Legal Entity options that can receive new customers.
     *
     * GET /api/v1/customers/legal-entities
     *
     * @return AnonymousResourceCollection<int, CustomerLegalEntityLookupResource>
     */
    public function legalEntities(Request $request): AnonymousResourceCollection
    {
        $this->authorize('create', Customer::class);

        /** @var User $user */
        $user = $request->user();
        /** @var int $tenantId */
        $tenantId = $request->get('tenant_id');
        $legalEntities = $this->customerService->writableLegalEntities($user, $tenantId);

        return CustomerLegalEntityLookupResource::collection($legalEntities);
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

        /** @var int $tenantId */
        $tenantId = $request->get('tenant_id');
        /** @var User $user */
        $user = $request->user();

        $customer = $this->customerService->create($user, $tenantId, $request->validated());

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
        /** @var User $user */
        $user = request()->user();

        $customer->load([
            'assignments.user',
            'customerEstablishments',
            'sites' => function (HasMany $query) use ($user): void {
                if ($user->can('customers.read')) {
                    return;
                }

                $query->whereIn('sites.id', $user->visibleSitesQuery()->select('sites.id'));
            },
        ]);
        $customer->loadCount($this->visibleSitesCountDefinition($user));

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

        /** @var int $tenantId */
        $tenantId = $request->get('tenant_id');
        /** @var User $user */
        $user = $request->user();
        $customer = $this->customerService->update(
            $user,
            $tenantId,
            $customer,
            $request->validated()
        );

        return response()->json([
            'data' => new CustomerResource($customer),
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
     * @return Response|JsonResponse 204 No Content on success, 409 Conflict if has active sites
     */
    public function destroy(Customer $customer): Response|JsonResponse
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

        return response()->noContent();
    }

    /**
     * List sites for a customer.
     *
     * GET /api/v1/customers/{customer}/sites
     *
     * Returns paginated list of sites belonging to the customer.
     * User must have view access to the customer.
     *
     * @return AnonymousResourceCollection<int, SiteResource> Paginated site list
     */
    public function sites(IndexCustomerSitesRequest $request, Customer $customer)
    {
        $this->authorize('view', $customer);

        /** @var User $user */
        $user = $request->user();
        $perPage = $request->integer('per_page', 15);
        $sites = $user->can('customers.read')
            ? $customer->sites()->with(['legalEntity', 'establishment', 'assignments.user'])
            : $user->visibleSitesQuery()
                ->where('customer_id', $customer->id)
                ->with(['legalEntity', 'establishment', 'assignments.user']);

        if ($request->has('is_active')) {
            $sites->where('is_active', $request->boolean('is_active'));
        }

        if ($request->has('type')) {
            $sites->where('type', $request->string('type')->toString());
        }

        return SiteResource::collection($sites->paginate($perPage));
    }

    /**
     * Attach a sites_count that matches the current caller's effective site visibility.
     *
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    private function withVisibleSitesCount(Builder $query, User $user): Builder
    {
        return $query->withCount($this->visibleSitesCountDefinition($user));
    }

    /**
     * Build the loadCount/withCount definition for customer sites_count.
     *
     * @return array<int|string, string|\Closure(Builder<Site>): void>
     */
    private function visibleSitesCountDefinition(User $user): array
    {
        if ($user->can('customers.read')) {
            return ['sites'];
        }

        return [
            'sites as sites_count' => function (Builder $query) use ($user): void {
                $query->whereIn('sites.id', $user->visibleSitesQuery()->select('sites.id'));
            },
        ];
    }
}

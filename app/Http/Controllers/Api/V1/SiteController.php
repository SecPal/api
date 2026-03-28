<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexSiteRequest;
use App\Http\Requests\Api\V1\StoreSiteRequest;
use App\Http\Requests\Api\V1\UpdateSiteRequest;
use App\Http\Resources\SiteResource;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * SiteController handles Site resource CRUD operations.
 *
 * Implements access control via SitePolicy:
 * - Users can only see sites they are assigned to OR
 * - Sites in accessible organizational units OR
 * - Sites belonging to customers they are assigned to
 * - Full CRUD requires appropriate permissions
 *
 * @see SecPal/.github#210 Customer & Site Management Epic
 * @see SecPal/api#314 Site CRUD API endpoints
 */
class SiteController extends Controller
{
    /**
     * Display a listing of sites.
     *
     * GET /v1/sites
     *
     * Returns paginated list of accessible sites based on:
     * - Direct site assignments (currently active)
     * - Customer assignments (Key Account access)
     * - Access via site organizational units
     * - 403 when user has no effective collection access at all
     *
     * Supports filtering by:
     * - search (name, site_number)
     * - is_active (boolean)
     * - type (permanent, temporary)
     * - customer_id (UUID)
     * - organizational_unit_id (UUID)
     *
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection Paginated site list with metadata
     */
    public function index(IndexSiteRequest $request)
    {
        $this->authorize('viewAny', Site::class);

        /** @var \App\Models\User $user */
        $user = $request->user();
        /** @var int $tenantId */
        $tenantId = $request->get('tenant_id');

        $query = Site::query()
            ->where('tenant_id', $tenantId)
            ->with(['customer', 'organizationalUnit', 'assignments.user']);

        // Need-to-Know filtering: users reaching this branch already have scoped collection access
        if (! $user->can('sites.read')) {
            // Pre-compute accessible unit IDs and assigned site IDs to avoid repeated execution
            $accessibleUnitIds = $user->getAccessibleOrganizationalUnitIds();
            $assignedSiteIds = $user->siteAssignments()
                ->currentlyActive()
                ->pluck('site_id')->toArray();

            $assignedCustomerIds = $user->customerAssignments()
                ->currentlyActive()
                ->pluck('customer_id')->toArray();

            $query->where(function ($q) use ($accessibleUnitIds, $assignedSiteIds, $assignedCustomerIds) {
                // Direct assignment to site (currently active)
                $q->whereIn('id', $assignedSiteIds)
                    // Or access via customer assignment
                    ->orWhereIn('customer_id', $assignedCustomerIds)
                    // Or access via organizational unit
                    ->orWhereIn('organizational_unit_id', $accessibleUnitIds);
            });
        }

        // Search filter
        if ($request->has('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('site_number', 'ilike', "%{$search}%");
            });
        }

        // Active status filter
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Type filter
        if ($request->has('type')) {
            $query->where('type', $request->string('type')->toString());
        }

        // Customer filter
        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->string('customer_id')->toString());
        }

        // Organizational unit filter
        if ($request->has('organizational_unit_id')) {
            $query->where('organizational_unit_id', $request->string('organizational_unit_id')->toString());
        }

        $perPage = $request->integer('per_page', 15);
        $sites = $query->paginate($perPage);

        return SiteResource::collection($sites);
    }

    /**
     * Store a newly created site.
     *
     * POST /api/v1/sites
     *
     * Automatically generates site_number if not provided.
     * Requires 'sites.create' permission.
     *
     * @return JsonResponse Created site (201)
     */
    public function store(StoreSiteRequest $request): JsonResponse
    {
        $this->authorize('create', Site::class);

        $validated = $request->validated();
        /** @var int $tenantId */
        $tenantId = $request->get('tenant_id');
        $validated['tenant_id'] = $tenantId;

        // Auto-generate site_number if not provided
        if (! isset($validated['site_number'])) {
            $validated['site_number'] = Site::generateSiteNumber($tenantId);
        }

        // Set default is_active if not provided (DB default not applied when explicitly passing null)
        if (! isset($validated['is_active'])) {
            $validated['is_active'] = true;
        }

        $site = Site::create($validated);

        return response()->json([
            'data' => new SiteResource($site),
        ], Response::HTTP_CREATED);
    }

    /**
     * Display the specified site.
     *
     * GET /api/v1/sites/{site}
     *
     * Includes relationships:
     * - customer
     * - organizationalUnit
     * - assignments with users
     *
     * @return JsonResponse Site details
     */
    public function show(Site $site): JsonResponse
    {
        $this->authorize('view', $site);

        $site->load(['customer', 'organizationalUnit', 'assignments.user']);

        return response()->json([
            'data' => new SiteResource($site),
        ]);
    }

    /**
     * Update the specified site.
     *
     * PATCH /api/v1/sites/{site}
     *
     * Requires:
     * - Direct assignment to site (currently active) OR
     * - 'sites.update' permission
     *
     * @return JsonResponse Updated site
     */
    public function update(UpdateSiteRequest $request, Site $site): JsonResponse
    {
        $this->authorize('update', $site);

        $site->update($request->validated());

        return response()->json([
            'data' => new SiteResource($site->fresh()),
        ]);
    }

    /**
     * Remove the specified site.
     *
     * DELETE /api/v1/sites/{site}
     *
     * Soft deletes the site.
     * Note: Will be blocked by active cost centers check once CostCenter CRUD is implemented.
     * Requires 'sites.delete' permission.
     *
     * @return JsonResponse 204 No Content on success
     */
    public function destroy(Site $site): JsonResponse
    {
        $this->authorize('delete', $site);

        // Note: Active cost centers check will be re-enabled once CostCenter CRUD endpoints are implemented
        // if ($site->costCenters()->where('is_active', true)->exists()) {
        //     return response()->json([
        //         'message' => __('Cannot delete site with active cost centers.'),
        //         'error' => 'has_active_cost_centers',
        //     ], Response::HTTP_CONFLICT);
        // }

        $site->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * List cost centers for a site.
     *
     * GET /api/v1/sites/{site}/cost-centers
     *
     * Returns paginated list of cost centers belonging to the site.
     * User must have view access to the site.
     *
     * @return JsonResponse Paginated cost center list (temporary manual pagination until CostCenterResource is created)
     *
     * @codeCoverageIgnore CostCenterResource will be created in a later issue
     */
    public function costCenters(Request $request, Site $site): JsonResponse
    {
        $this->authorize('view', $site);

        $perPage = $request->integer('per_page', 15);
        $costCenters = $site->costCenters()
            ->with(['site'])
            ->paginate($perPage);

        // TODO: Replace with CostCenterResource::collection($costCenters) when available
        return response()->json([
            'data' => $costCenters->items(),
            'links' => [
                'first' => $costCenters->url(1),
                'last' => $costCenters->url($costCenters->lastPage()),
                'prev' => $costCenters->previousPageUrl(),
                'next' => $costCenters->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $costCenters->currentPage(),
                'from' => $costCenters->firstItem(),
                'last_page' => $costCenters->lastPage(),
                'path' => $costCenters->path(),
                'per_page' => $costCenters->perPage(),
                'to' => $costCenters->lastItem(),
                'total' => $costCenters->total(),
            ],
        ]);
    }
}

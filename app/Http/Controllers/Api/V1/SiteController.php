<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexSiteRequest;
use App\Http\Requests\Api\V1\StoreSiteRequest;
use App\Http\Requests\Api\V1\UpdateSiteRequest;
use App\Http\Resources\SiteResource;
use App\Models\Site;
use App\Models\TenantKey;
use App\Rules\AssignableOrganizationalUnit;
use App\Support\LikePattern;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

        $query = $user->visibleSitesQuery()
            ->where('tenant_id', $tenantId)
            ->with(['customer', 'organizationalUnit', 'assignments.user']);

        // Search filter
        if ($request->has('search')) {
            $search = $request->string('search')->toString();
            $escapedSearch = LikePattern::escape($search);
            $query->where(function ($q) use ($escapedSearch) {
                $q->where('name', 'ilike', "%{$escapedSearch}%")
                    ->orWhere('site_number', 'ilike', "%{$escapedSearch}%");
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

        $site = DB::transaction(function () use ($tenantId, $validated): Site {
            TenantKey::query()->select('id')->whereKey($tenantId)->lockForUpdate()->firstOrFail();

            // Auto-generate site_number within the same transaction as the insert.
            if (! isset($validated['site_number'])) {
                $validated['site_number'] = Site::generateSiteNumber($tenantId);
            }

            // Set default is_active if not provided (DB default not applied when explicitly passing null)
            if (! isset($validated['is_active'])) {
                $validated['is_active'] = true;
            }

            return Site::create($validated);
        });

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

        $validated = $request->validated();

        if ($site->organizationalUnit?->is_assignable === false && $this->wouldReactivateSite($site, $validated)) {
            throw ValidationException::withMessages([
                'organizational_unit_id' => __(AssignableOrganizationalUnit::MESSAGE),
            ]);
        }

        $site->update($validated);

        return response()->json([
            'data' => new SiteResource($site->fresh()),
        ]);
    }

    /**
     * Determine whether an update would reactivate an inactive or expired site.
     *
     * @param  array<string, mixed>  $validated
     */
    private function wouldReactivateSite(Site $site, array $validated): bool
    {
        if ($site->is_active && ! $site->is_expired) {
            return false;
        }

        $updatedSite = clone $site;
        $updatedSite->fill($validated);

        return $updatedSite->is_active && ! $updatedSite->is_expired;
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
     * @return Response 204 No Content on success
     */
    public function destroy(Site $site): Response
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

        return response()->noContent();
    }
}

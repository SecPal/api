<?php

/*
 * SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreCostCenterRequest;
use App\Http\Requests\Api\V1\UpdateCostCenterRequest;
use App\Http\Resources\CostCenterResource;
use App\Models\CostCenter;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * CostCenterController handles CostCenter resource CRUD operations.
 *
 * Implements access control via CostCenterPolicy:
 * - Users can access cost centers through their parent site access
 * - Create/Update/Delete requires both cost-centers.* permission AND sites.update permission
 * - Full CRUD requires appropriate permissions
 *
 * @see SecPal/.github#316 CostCenter API endpoints
 * @see SecPal/.github#210 Customer & Site Management Epic
 */
class CostCenterController extends Controller
{
    /**
     * Display a listing of cost centers for a site.
     */
    public function index(Site $site): AnonymousResourceCollection
    {
        $this->authorize('view', $site);
        $this->authorize('viewAny', CostCenter::class);

        $query = $site->costCenters();

        // Filter by active status if requested
        if (request()->has('active_only') && request()->boolean('active_only')) {
            $query->where('is_active', true);
        }

        $costCenters = $query->get();

        return CostCenterResource::collection($costCenters);
    }

    /**
     * Store a newly created cost center.
     */
    public function store(StoreCostCenterRequest $request, Site $site): JsonResponse
    {
        $this->authorize('create', [CostCenter::class, $site]);

        $validated = $request->validated();

        // Set default is_active if not provided (DB default not applied when explicitly passing null)
        if (! isset($validated['is_active'])) {
            $validated['is_active'] = true;
        }

        $costCenter = CostCenter::create([
            ...$validated,
            'site_id' => $site->id,
            'tenant_id' => $site->tenant_id,
        ]);

        return response()->json([
            'data' => new CostCenterResource($costCenter),
        ], 201);
    }

    /**
     * Display the specified cost center.
     */
    public function show(Site $site, CostCenter $costCenter): JsonResponse
    {
        $this->authorize('view', $costCenter);

        return response()->json([
            'data' => new CostCenterResource($costCenter),
        ]);
    }

    /**
     * Update the specified cost center.
     */
    public function update(UpdateCostCenterRequest $request, Site $site, CostCenter $costCenter): JsonResponse
    {
        $this->authorize('update', [$costCenter, $site]);

        $costCenter->update($request->validated());

        return response()->json([
            'data' => new CostCenterResource($costCenter->fresh()),
        ]);
    }

    /**
     * Remove the specified cost center from storage.
     */
    public function destroy(Site $site, CostCenter $costCenter): Response|JsonResponse
    {
        $this->authorize('delete', $costCenter);

        $costCenter->delete();

        return response()->noContent();
    }
}

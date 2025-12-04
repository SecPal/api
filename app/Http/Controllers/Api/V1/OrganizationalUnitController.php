<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreOrganizationalUnitRequest;
use App\Http\Requests\Api\UpdateOrganizationalUnitRequest;
use App\Http\Resources\OrganizationalUnitResource;
use App\Models\OrganizationalUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * OrganizationalUnitController handles CRUD operations for organizational units.
 *
 * This controller manages the internal organizational hierarchy (holdings,
 * companies, regions, branches, divisions, departments).
 *
 * All operations are protected by OrganizationalUnitPolicy for authorization.
 */
class OrganizationalUnitController extends Controller
{
    /**
     * Display a listing of organizational units.
     *
     * Returns ONLY units the authenticated user has access to (Need-to-Know principle).
     * Uses the user's organizational scopes to filter results.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', OrganizationalUnit::class);

        /** @var \App\Models\User $user */
        $user = $request->user();

        // Get accessible units based on user's organizational scopes (Need-to-Know principle)
        $accessibleUnits = $user->getAccessibleOrganizationalUnits();
        $accessibleIds = $accessibleUnits->pluck('id')->toArray();

        // Determine root unit IDs (units without accessible parents)
        $rootUnitIds = $this->determineRootUnitIds($accessibleUnits);

        /** @var string $tenantId */
        $tenantId = $request->input('tenant_id');

        // Build query filtered to accessible units only AND current tenant (defense-in-depth)
        $query = OrganizationalUnit::with('parent')
            ->whereIn('id', $accessibleIds)
            ->where('tenant_id', $tenantId);

        // Filter by type if provided
        if ($request->has('type')) {
            $query->where('type', $request->input('type'));
        }

        // Filter by parent_id if provided
        if ($request->has('parent_id')) {
            $parentId = $request->input('parent_id');
            if ($parentId === 'null' || $parentId === null) {
                // Get root units (units without accessible parents)
                $query->whereIn('id', $rootUnitIds);
            } else {
                // Get children of specific parent (only if parent is accessible)
                if (! in_array($parentId, $accessibleIds, true)) {
                    // Parent not accessible - return empty result
                    return response()->json([
                        'data' => [],
                        'meta' => [
                            'current_page' => 1,
                            'last_page' => 1,
                            'per_page' => $request->integer('per_page', 15),
                            'total' => 0,
                            'root_unit_ids' => $rootUnitIds,
                        ],
                    ]);
                }
                $childIds = \App\Models\OrganizationalUnitClosure::where('ancestor_id', $parentId)
                    ->where('depth', 1)
                    ->whereIn('descendant_id', $accessibleIds)
                    ->pluck('descendant_id');
                $query->whereIn('id', $childIds);
            }
        }

        $units = $query->paginate($request->integer('per_page', 15));

        return response()->json([
            'data' => OrganizationalUnitResource::collection($units),
            'meta' => [
                'current_page' => $units->currentPage(),
                'last_page' => $units->lastPage(),
                'per_page' => $units->perPage(),
                'total' => $units->total(),
                'root_unit_ids' => $rootUnitIds,
            ],
        ]);
    }

    /**
     * Determine root unit IDs for the user's accessible tree.
     *
     * A unit is a "root" in the accessible tree if it has no accessible parent.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, OrganizationalUnit>  $accessibleUnits
     * @return list<string>
     */
    private function determineRootUnitIds($accessibleUnits): array
    {
        $accessibleIds = $accessibleUnits->pluck('id')->toArray();
        $rootIds = [];

        foreach ($accessibleUnits as $unit) {
            // Get immediate parent via closure table
            $parentClosure = \App\Models\OrganizationalUnitClosure::where('descendant_id', $unit->id)
                ->where('depth', 1)
                ->first();

            if ($parentClosure === null) {
                // No parent at all - this is a true root
                $rootIds[] = $unit->id;
            } elseif (! in_array($parentClosure->ancestor_id, $accessibleIds, true)) {
                // Parent exists but is not accessible - this is a root in the user's view
                $rootIds[] = $unit->id;
            }
        }

        return $rootIds;
    }

    /**
     * Store a newly created organizational unit.
     */
    public function store(StoreOrganizationalUnitRequest $request): JsonResponse
    {
        /** @var int $tenantId */
        $tenantId = $request->input('tenant_id');

        // Check authorization for creating under parent (if specified) or root
        $parentId = $request->validated()['parent_id'] ?? null;
        if ($parentId !== null) {
            $parent = OrganizationalUnit::findOrFail($parentId);
            $this->authorize('create', $parent);
        } else {
            // Creating root unit requires admin somewhere
            $this->authorize('viewAny', OrganizationalUnit::class);
        }

        /** @var array{name: string, type: string, custom_type_name?: string|null, description?: string|null, metadata?: array<mixed>|null, parent_id?: string|null} $validated */
        $validated = $request->validated();

        $unit = OrganizationalUnit::create([
            'tenant_id' => $tenantId,
            'name' => $validated['name'],
            'type' => $validated['type'],
            'custom_type_name' => $validated['custom_type_name'] ?? null,
            'description' => $validated['description'] ?? null,
            'metadata' => $validated['metadata'] ?? null,
        ]);

        // Attach parent if specified
        if ($parentId !== null) {
            /** @var OrganizationalUnit $parent */
            $parent = OrganizationalUnit::findOrFail($parentId);
            $unit->setParent($parent);
        }

        return response()->json([
            'data' => new OrganizationalUnitResource($unit),
        ], Response::HTTP_CREATED);
    }

    /**
     * Display the specified organizational unit.
     */
    public function show(OrganizationalUnit $organizational_unit): JsonResponse
    {
        $this->authorize('view', $organizational_unit);

        return response()->json([
            'data' => new OrganizationalUnitResource($organizational_unit),
        ]);
    }

    /**
     * Update the specified organizational unit.
     */
    public function update(UpdateOrganizationalUnitRequest $request, OrganizationalUnit $organizational_unit): JsonResponse
    {
        $this->authorize('update', $organizational_unit);

        /** @var array{name?: string, type?: string, custom_type_name?: string|null, description?: string|null, metadata?: array<mixed>|null} $validated */
        $validated = $request->validated();

        $organizational_unit->update($validated);

        return response()->json([
            'data' => new OrganizationalUnitResource($organizational_unit->fresh()),
        ]);
    }

    /**
     * Remove the specified organizational unit (soft delete).
     */
    public function destroy(OrganizationalUnit $organizational_unit): JsonResponse
    {
        $this->authorize('delete', $organizational_unit);

        $organizational_unit->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Get all descendants of the organizational unit.
     */
    public function descendants(OrganizationalUnit $organizational_unit): JsonResponse
    {
        $this->authorize('view', $organizational_unit);

        $descendants = $organizational_unit->descendants()->get();

        return response()->json([
            'data' => OrganizationalUnitResource::collection($descendants),
        ]);
    }

    /**
     * Get all ancestors of the organizational unit.
     */
    public function ancestors(OrganizationalUnit $organizational_unit): JsonResponse
    {
        $this->authorize('view', $organizational_unit);

        $ancestors = $organizational_unit->ancestors()->get();

        return response()->json([
            'data' => OrganizationalUnitResource::collection($ancestors),
        ]);
    }

    /**
     * Attach a parent to the organizational unit.
     */
    public function attachParent(Request $request, OrganizationalUnit $organizational_unit): JsonResponse
    {
        $this->authorize('update', $organizational_unit);

        $request->validate([
            'parent_id' => ['required', 'uuid', 'exists:organizational_units,id'],
        ]);

        /** @var string $parentId */
        $parentId = $request->input('parent_id');
        /** @var OrganizationalUnit $parent */
        $parent = OrganizationalUnit::findOrFail($parentId);

        // Also need permission on the parent to add children
        $this->authorize('create', $parent);

        $organizational_unit->setParent($parent);

        return response()->json([
            'data' => new OrganizationalUnitResource($organizational_unit->fresh()),
        ]);
    }

    /**
     * Detach a parent from the organizational unit.
     */
    public function detachParent(OrganizationalUnit $organizational_unit, OrganizationalUnit $parent): JsonResponse
    {
        $this->authorize('update', $organizational_unit);

        $organizational_unit->removeParent();

        return response()->json([
            'data' => new OrganizationalUnitResource($organizational_unit->fresh()),
        ]);
    }
}

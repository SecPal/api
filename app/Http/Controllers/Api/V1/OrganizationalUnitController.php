<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\IndexOrganizationalUnitRequest;
use App\Http\Requests\Api\StoreOrganizationalUnitRequest;
use App\Http\Requests\Api\UpdateOrganizationalUnitRequest;
use App\Http\Resources\OrganizationalUnitResource;
use App\Models\OrganizationalUnit;
use App\Models\OrganizationalUnitClosure;
use App\Models\UserInternalOrganizationalScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

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
    private function respondWithUnit(Request $request, OrganizationalUnit $unit, int $status = Response::HTTP_OK): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $request->attributes->set(
            'accessible_unit_ids',
            $user->getAccessibleOrganizationalUnits()->pluck('id')->toArray()
        );

        /** @var OrganizationalUnit $responseUnit */
        $responseUnit = $unit->fresh()->load('parent');

        return response()->json([
            'data' => new OrganizationalUnitResource($responseUnit),
        ], $status);
    }

    /**
     * Display a listing of organizational units.
     *
     * Returns ONLY units the authenticated user has access to (Need-to-Know principle).
     * Uses the user's organizational scopes to filter results.
     */
    public function index(IndexOrganizationalUnitRequest $request): JsonResponse
    {
        $this->authorize('viewAny', OrganizationalUnit::class);

        /** @var array{parent_id?: string, type?: string} $validated */
        $validated = $request->validated();

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
        // Parent filtering is done in OrganizationalUnitResource (Need-to-Know principle)
        $query = OrganizationalUnit::with('parent')
            ->whereIn('id', $accessibleIds)
            ->where('tenant_id', $tenantId);

        // Filter by type if provided
        if (array_key_exists('type', $validated) && $validated['type'] !== null) {
            $query->where('type', $validated['type']);
        }

        // Filter by parent_id if provided
        if (array_key_exists('parent_id', $validated)) {
            $parentId = $validated['parent_id'];
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
                $childIds = OrganizationalUnitClosure::where('ancestor_id', $parentId)
                    ->where('depth', 1)
                    ->whereIn('descendant_id', $accessibleIds)
                    ->pluck('descendant_id');
                $query->whereIn('id', $childIds);
            }
        }

        $units = $query->paginate($request->integer('per_page', 15));

        // Store accessible IDs on the container request for OrganizationalUnitResource to use (Need-to-Know filtering)
        request()->attributes->set('accessible_unit_ids', $accessibleIds);

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
            $parentClosure = OrganizationalUnitClosure::where('descendant_id', $unit->id)
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
            $this->grantCreatorAdminScopeOnNewChildUnit($request, $unit);
        } else {
            // Root unit created: Auto-assign admin scope to creator
            // This ensures the creator can see and manage their new unit
            /** @var \App\Models\User $user */
            $user = $request->user();
            UserInternalOrganizationalScope::create([
                'user_id' => $user->id,
                'organizational_unit_id' => $unit->id,
                'access_level' => 'admin',
                'include_descendants' => true,
            ]);
        }

        return $this->respondWithUnit($request, $unit, Response::HTTP_CREATED);
    }

    /**
     * Ensure creators retain full control over newly created child units.
     *
     * Child-unit creation requires manage access on the parent, but the newly
     * created unit should remain directly manageable by its creator even when
     * the parent scope would otherwise grant only inherited manage access.
     */
    private function grantCreatorAdminScopeOnNewChildUnit(Request $request, OrganizationalUnit $unit): void
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        UserInternalOrganizationalScope::updateOrCreate(
            [
                'user_id' => $user->id,
                'organizational_unit_id' => $unit->id,
            ],
            [
                'access_level' => 'admin',
                'include_descendants' => false,
            ]
        );
    }

    /**
     * Ensure the acting user retains at least read visibility to a child unit after hierarchy changes.
     *
     * Users may be allowed to create under a parent via a direct scope that does
     * not include descendants. The same pattern can happen when moving an
     * existing child under a new parent. In both cases the affected unit would
     * otherwise disappear from list/detail operations immediately after
     * the successful response.
     *
     * If the actor already has any access to the unit (even read-only), their existing
     * scope is preserved as-is. A new scope is only granted when the actor would
     * otherwise lose all visibility, in which case access is inherited from their
     * highest-level manage-or-above scope on the parent. Explicitly granted
     * lower-level scopes are intentionally not escalated.
     */
    private function ensureActorCanAccessChildUnit(Request $request, OrganizationalUnit $parent, OrganizationalUnit $unit): void
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        if ($user->hasAccessToUnit($unit, 'read')) {
            return;
        }

        $relevantAncestorIds = OrganizationalUnitClosure::where('descendant_id', $parent->id)
            ->pluck('ancestor_id');

        $creatorScope = $user->organizationalScopes()
            ->where(function ($query) use ($parent, $relevantAncestorIds): void {
                $query->where('organizational_unit_id', $parent->id)
                    ->orWhere(function ($descendantQuery) use ($relevantAncestorIds): void {
                        $descendantQuery
                            ->whereIn('organizational_unit_id', $relevantAncestorIds)
                            ->where('include_descendants', true);
                    });
            })
            ->get()
            ->filter(fn (UserInternalOrganizationalScope $scope): bool => $scope->hasMinimumAccessLevel('manage'))
            ->sortByDesc(fn (UserInternalOrganizationalScope $scope): int => $scope->getAccessLevelValue())
            ->first();

        if ($creatorScope === null) {
            return;
        }

        UserInternalOrganizationalScope::firstOrCreate(
            [
                'user_id' => $user->id,
                'organizational_unit_id' => $unit->id,
            ],
            [
                'access_level' => $creatorScope->access_level,
                'include_descendants' => false,
            ]
        );
    }

    /**
     * Display the specified organizational unit.
     */
    public function show(Request $request, OrganizationalUnit $organizational_unit): JsonResponse
    {
        $this->authorize('view', $organizational_unit);

        return $this->respondWithUnit($request, $organizational_unit);
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

        return $this->respondWithUnit($request, $organizational_unit);
    }

    /**
     * Remove the specified organizational unit (soft delete).
     *
     * Uses blocking mode: deletion is prevented if the unit has child units.
     * This prevents accidental data loss and enforces explicit restructuring.
     *
     * @see https://github.com/SecPal/api/issues/284 Edge-case decision: blocking mode
     */
    public function destroy(OrganizationalUnit $organizational_unit): Response|JsonResponse
    {
        $this->authorize('delete', $organizational_unit);

        // Check for children (Blocking Mode - Issue #284)
        // We count direct children only (depth=1), as moving them is sufficient
        $childCount = OrganizationalUnitClosure::where('ancestor_id', $organizational_unit->id)
            ->where('depth', 1)
            ->count();

        if ($childCount > 0) {
            return response()->json([
                'message' => trans_choice('Cannot delete: :count child unit exists|Cannot delete: :count child units exist', $childCount, ['count' => $childCount]),
                'child_count' => $childCount,
                'hint' => __('Delete or move child units first'),
            ], Response::HTTP_CONFLICT);
        }

        $organizational_unit->delete();

        return response()->noContent();
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

        /** @var \App\Models\User $user */
        $user = $request->user();

        /** @var array{parent_id: string} $validated */
        $validated = $request->validate([
            'parent_id' => [
                'required',
                'uuid',
                Rule::exists('organizational_units', 'id')->where(function (\Illuminate\Database\Query\Builder $query) use ($user): void {
                    $query->where('tenant_id', $user->tenant_id)
                        ->whereNull('deleted_at');
                }),
            ],
        ]);

        /** @var OrganizationalUnit $parent */
        $parent = OrganizationalUnit::findOrFail($validated['parent_id']);

        // Also need permission on the parent to add children
        $this->authorize('create', $parent);

        $organizational_unit->setParent($parent);
        $this->ensureActorCanAccessChildUnit($request, $parent, $organizational_unit);

        return $this->respondWithUnit($request, $organizational_unit);
    }

    /**
     * Detach a parent from the organizational unit.
     *
     * This operation will make the unit a root unit. Before proceeding,
     * we verify that the user will still have access to the unit after
     * the parent is detached. If the user's access is only inherited
     * from the parent via include_descendants, this operation will fail
     * with a 403 Forbidden response.
     */
    public function detachParent(Request $request, OrganizationalUnit $organizational_unit, OrganizationalUnit $parent): JsonResponse
    {
        $this->authorize('update', $organizational_unit);

        /** @var \App\Models\User $user */
        $user = $request->user();

        // Check if user will still have access after detaching parent.
        // User needs a direct scope on the unit itself, not just inherited access via parent.
        $hasDirectScope = UserInternalOrganizationalScope::where('user_id', $user->id)
            ->where('organizational_unit_id', $organizational_unit->id)
            ->exists();

        if (! $hasDirectScope) {
            // User's access is only via the parent's include_descendants flag.
            // After detaching, they would lose access to this unit.
            return response()->json([
                'message' => __('Cannot make this unit a root unit. Your access to this unit is inherited from the parent hierarchy. Making it a root unit would remove your access. Please contact an administrator to get direct access to this unit first.'),
            ], Response::HTTP_FORBIDDEN);
        }

        $organizational_unit->removeParent();

        return $this->respondWithUnit($request, $organizational_unit);
    }
}

<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLeadershipLevelRequest;
use App\Http\Requests\UpdateLeadershipLevelRequest;
use App\Http\Resources\LeadershipLevelResource;
use App\Models\LeadershipLevel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * LeadershipLevelController handles Leadership Level CRUD operations (Operation 1).
 *
 * CRITICAL ARCHITECTURAL PRINCIPLE (ADR-009):
 * This controller implements Operation 1: Leadership Level Definition CRUD
 * - Authorization uses PURE permission checks via LeadershipLevelPolicy
 * - User's OWN leadership level has ZERO influence on these operations
 * - Any user with appropriate permission can manage ANY level definition
 *
 * Examples:
 * - FE6 person with 'leadership_level.update' → Can edit FE1 definitions ✅
 * - FE1 person without permission → Cannot edit any definitions ❌
 * - Person with null (no FE) + permission → Can create all levels ✅
 *
 * Operations 2 & 3 (Assignment & Permission Granting with rank validation)
 * are implemented in EmployeePolicy and OrganizationalScopePolicy (Issue #425).
 *
 * @see https://github.com/SecPal/api/issues/399 Epic #399: Leadership Levels System
 * @see https://github.com/SecPal/api/issues/424 Issue #424: Leadership Levels Backend API
 * @see https://github.com/SecPal/.github/blob/main/docs/adr/20251221-inheritance-blocking-and-leadership-access-control.md ADR-009
 */
class LeadershipLevelController extends Controller
{
    /**
     * Display a listing of leadership levels for the current tenant.
     *
     * GET /api/v1/leadership-levels
     *
     * Returns all leadership levels ordered by rank (1=highest, ascending=lower).
     * Supports filtering by:
     * - is_active (boolean): Active vs inactive levels
     * - include_trashed (boolean): Include soft-deleted records
     *
     * Authorization: Requires 'leadership_level.view' permission.
     * User's own leadership level is irrelevant (pure permission check).
     *
     * @param  Request  $request  The incoming request
     * @return JsonResponse Leadership levels collection
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LeadershipLevel::class);

        /** @var \App\Models\User $user */
        $user = Auth::guard('sanctum')->user();
        $tenantId = $user->tenant_id;

        $query = LeadershipLevel::where('tenant_id', $tenantId);

        // Filter by is_active
        if ($request->has('is_active')) {
            $isActive = filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN);
            $query->where('is_active', $isActive);
        }

        // Include soft-deleted records if requested
        if ($request->boolean('include_trashed')) {
            $query->withTrashed();
        }

        // Order by rank (1=highest authority, ascending numbers=lower levels)
        $levels = $query->orderBy('rank')->get();

        // Append employees_count attribute
        $levels->each->append('employees_count');

        return response()->json([
            'data' => LeadershipLevelResource::collection($levels),
        ]);
    }

    /**
     * Store a newly created leadership level.
     *
     * POST /api/v1/leadership-levels
     *
     * Creates a new leadership level definition for the current tenant.
     * Enforces uniqueness of rank and name within tenant.
     *
     * Authorization: Requires 'leadership_level.create' permission.
     * User's own leadership level is irrelevant (pure permission check).
     *
     * @param  StoreLeadershipLevelRequest  $request  The validated request
     * @return JsonResponse Created leadership level
     */
    public function store(StoreLeadershipLevelRequest $request): JsonResponse
    {
        $this->authorize('create', LeadershipLevel::class);

        /** @var \App\Models\User $user */
        $user = Auth::guard('sanctum')->user();
        $tenantId = $user->tenant_id;

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        $leadershipLevel = LeadershipLevel::create([
            'tenant_id' => $tenantId,
            'rank' => $validated['rank'],
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'color' => $validated['color'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        // Load employees_count attribute
        $leadershipLevel->append('employees_count');

        return response()->json([
            'data' => new LeadershipLevelResource($leadershipLevel),
        ], Response::HTTP_CREATED);
    }

    /**
     * Display the specified leadership level.
     *
     * GET /api/v1/leadership-levels/{leadershipLevel}
     *
     * Authorization: Requires 'leadership_level.view' permission.
     * User's own leadership level is irrelevant (pure permission check).
     *
     * @param  LeadershipLevel  $leadershipLevel  The leadership level to display
     * @return JsonResponse Leadership level details
     */
    public function show(LeadershipLevel $leadershipLevel): JsonResponse
    {
        $this->authorize('view', $leadershipLevel);

        // Load employees_count attribute
        $leadershipLevel->append('employees_count');

        return response()->json([
            'data' => new LeadershipLevelResource($leadershipLevel),
        ]);
    }

    /**
     * Update the specified leadership level.
     *
     * PATCH /api/v1/leadership-levels/{leadershipLevel}
     *
     * Updates leadership level definition.
     * All fields are optional (PATCH semantics).
     * Enforces uniqueness of rank and name within tenant.
     *
     * Authorization: Requires 'leadership_level.update' permission.
     * User's own leadership level is irrelevant (pure permission check).
     *
     * @param  UpdateLeadershipLevelRequest  $request  The validated request
     * @param  LeadershipLevel  $leadershipLevel  The leadership level to update
     * @return JsonResponse Updated leadership level
     */
    public function update(
        UpdateLeadershipLevelRequest $request,
        LeadershipLevel $leadershipLevel
    ): JsonResponse {
        $this->authorize('update', $leadershipLevel);

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        $leadershipLevel->update($validated);

        /** @var LeadershipLevel $freshLeadershipLevel */
        $freshLeadershipLevel = $leadershipLevel->fresh();
        $freshLeadershipLevel->append('employees_count');

        return response()->json([
            'data' => new LeadershipLevelResource($freshLeadershipLevel),
        ]);
    }

    /**
     * Remove the specified leadership level (soft delete).
     *
     * DELETE /api/v1/leadership-levels/{leadershipLevel}
     *
     * Soft deletes a leadership level if no employees are assigned.
     *
     * Authorization: Requires 'leadership_level.delete' permission
     * AND business rule: no employees must be assigned.
     * User's own leadership level is irrelevant (pure permission check).
     *
     * @param  LeadershipLevel  $leadershipLevel  The leadership level to delete
     * @return JsonResponse No content on success, error on failure
     */
    public function destroy(LeadershipLevel $leadershipLevel): JsonResponse
    {
        $this->authorize('delete', $leadershipLevel);

        // Business rule check (also in Policy, but double-check here for clarity)
        if (! $leadershipLevel->canBeDeleted()) {
            return response()->json([
                'message' => __('Cannot delete leadership level with assigned employees'),
                'employees_count' => $leadershipLevel->employees_count,
            ], Response::HTTP_CONFLICT);
        }

        $leadershipLevel->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Restore a soft-deleted leadership level.
     *
     * POST /api/v1/leadership-levels/{leadershipLevel}/restore
     *
     * Restores a previously soft-deleted leadership level.
     *
     * Authorization: Requires 'leadership_level.update' permission.
     * User's own leadership level is irrelevant (pure permission check).
     *
     * @param  string  $id  The UUID of the leadership level to restore
     * @return JsonResponse Restored leadership level
     */
    public function restore(string $id): JsonResponse
    {
        /** @var LeadershipLevel $leadershipLevel */
        $leadershipLevel = LeadershipLevel::onlyTrashed()->findOrFail($id);

        $this->authorize('restore', $leadershipLevel);

        $leadershipLevel->restore();

        /** @var LeadershipLevel $freshLeadershipLevel */
        $freshLeadershipLevel = $leadershipLevel->fresh();
        $freshLeadershipLevel->append('employees_count');

        return response()->json([
            'data' => new LeadershipLevelResource($freshLeadershipLevel),
        ]);
    }

    /**
     * Permanently delete a leadership level (force delete).
     *
     * DELETE /api/v1/leadership-levels/{leadershipLevel}/force
     *
     * Permanently removes a leadership level from database.
     * This is irreversible and should be used with caution.
     *
     * Authorization: Requires 'leadership_level.delete' permission.
     * User's own leadership level is irrelevant (pure permission check).
     *
     * @param  string  $id  The UUID of the leadership level to force delete
     * @return JsonResponse No content on success
     */
    public function forceDelete(string $id): JsonResponse
    {
        /** @var LeadershipLevel $leadershipLevel */
        $leadershipLevel = LeadershipLevel::withTrashed()->findOrFail($id);

        $this->authorize('forceDelete', $leadershipLevel);

        // Business rule: Cannot force delete if employees are assigned
        if (! $leadershipLevel->canBeDeleted()) {
            return response()->json([
                'message' => __('Cannot permanently delete leadership level with assigned employees'),
                'employees_count' => $leadershipLevel->employees_count,
            ], Response::HTTP_CONFLICT);
        }

        $leadershipLevel->forceDelete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Get only inactive leadership levels.
     *
     * GET /api/v1/leadership-levels/inactive
     *
     * Returns all inactive leadership levels for the current tenant.
     * Useful for archival views or reporting.
     *
     * Authorization: Requires 'leadership_level.view' permission.
     * User's own leadership level is irrelevant (pure permission check).
     *
     * @return JsonResponse Inactive leadership levels collection
     */
    public function inactive(): JsonResponse
    {
        $this->authorize('viewAny', LeadershipLevel::class);

        /** @var \App\Models\User $user */
        $user = Auth::guard('sanctum')->user();
        $tenantId = $user->tenant_id;

        $levels = LeadershipLevel::where('tenant_id', $tenantId)
            ->where('is_active', false)
            ->orderBy('rank')
            ->get();

        $levels->each->append('employees_count');

        return response()->json([
            'data' => LeadershipLevelResource::collection($levels),
        ]);
    }

    /**
     * Search leadership levels by name or description.
     *
     * GET /api/v1/leadership-levels/search
     *
     * Searches leadership levels using case-insensitive partial matching.
     * Searches in: name, description
     *
     * Query parameters:
     * - q (required): Search term (min 2 characters)
     * - is_active (optional): Filter by active status
     *
     * Authorization: Requires 'leadership_level.view' permission.
     * User's own leadership level is irrelevant (pure permission check).
     *
     * @param  Request  $request  The incoming request with search query
     * @return JsonResponse Matching leadership levels
     */
    public function search(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LeadershipLevel::class);

        $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        /** @var \App\Models\User $user */
        $user = Auth::guard('sanctum')->user();
        $tenantId = $user->tenant_id;

        /** @var string|null $searchTerm */
        $searchTerm = $request->input('q');

        $query = LeadershipLevel::where('tenant_id', $tenantId)
            ->where(function ($q) use ($searchTerm) {
                $q->where('name', 'ILIKE', "%{$searchTerm}%")
                    ->orWhere('description', 'ILIKE', "%{$searchTerm}%");
            });

        // Filter by is_active if provided
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $levels = $query->orderBy('rank')->get();
        $levels->each->append('employees_count');

        return response()->json([
            'data' => LeadershipLevelResource::collection($levels),
        ]);
    }

    /**
     * Get available leadership levels for assignment.
     *
     * GET /api/v1/leadership-levels/available
     *
     * Returns only active leadership levels, suitable for dropdown selection
     * when assigning leadership levels to employees.
     *
     * Note: This endpoint returns AVAILABLE levels, but actual assignment
     * authorization happens in EmployeePolicy based on max_assignable_rank
     * (Operation 2, implemented in Issue #425).
     *
     * Authorization: Requires 'leadership_level.view' permission.
     * User's own leadership level is irrelevant (pure permission check).
     *
     * @return JsonResponse Active leadership levels for assignment
     */
    public function available(): JsonResponse
    {
        $this->authorize('viewAny', LeadershipLevel::class);

        /** @var \App\Models\User $user */
        $user = Auth::guard('sanctum')->user();
        $tenantId = $user->tenant_id;

        $levels = LeadershipLevel::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('rank')
            ->get();

        // Don't include employees_count for available list (performance)
        return response()->json([
            'data' => LeadershipLevelResource::collection($levels),
        ]);
    }
}

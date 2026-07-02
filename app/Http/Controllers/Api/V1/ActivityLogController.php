<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexActivityLogRequest;
use App\Http\Resources\ActivityResource;
use App\Models\Activity;
use App\Models\User;
use App\Support\LikePattern;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ActivityLogController handles activity log retrieval and verification.
 *
 * Implements scoped access control via ActivityPolicy:
 * - Tenant isolation (mandatory)
 * - Permission check (activity_log.read)
 * - Organizational scope filtering
 * - Leadership level filtering (can only view logs from subordinates)
 *
 * Features:
 * - Paginated listing with comprehensive filtering
 * - Single log retrieval with relationships
 * - Verification endpoint (hash chain + Merkle + OpenTimestamp)
 *
 * @see \App\Policies\ActivityPolicy
 * @see Activity
 * @see SecPal/api#394 PR-11: ActivityLogController with scoped filtering
 * @see SecPal/api#385 Epic: Activity Logging & Audit Trail Strategy
 * @see SecPal/.github#docs/adr/20251221-activity-logging-audit-trail-strategy.md ADR-010
 */
class ActivityLogController extends Controller
{
    /**
     * Display a paginated listing of activity logs.
     *
     * GET /v1/activity-logs
     *
     * Returns activity logs accessible to the user based on:
     * - Tenant isolation (mandatory)
     * - Organizational scopes (with inheritance-blocking support)
     * - Leadership level filtering (only subordinates' activities)
     *
     * Supports filtering by:
     * - Date range (from_date, to_date)
     * - Log name (log_name)
     * - Search in description and persisted subject metadata
     * - Organizational unit
     * - Causer (type + ID)
     * - Subject (type + ID)
     *
     * @param  IndexActivityLogRequest  $request  Validated request with filters
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection Paginated activity logs
     */
    public function index(IndexActivityLogRequest $request)
    {
        $this->authorize('viewAny', Activity::class);

        /** @var User $user */
        $user = $request->user();
        /** @var int $tenantId */
        $tenantId = $request->get('tenant_id');

        // Base query: Tenant isolation (CRITICAL - always first)
        $query = Activity::query()
            ->where('tenant_id', $tenantId)
            ->with(['causer', 'subject', 'organizationalUnit']);

        // Apply scoped filtering (ADR-010 + ADR-009)
        $query = $this->applyScopedFiltering($query, $user);

        // Apply user-provided filters
        $this->applyFilters($query, $request);

        // Order by most recent first
        $query->orderByDesc('created_at');

        // Paginate
        $perPage = $request->integer('per_page', 50);
        $activities = $query->paginate($perPage);

        return ActivityResource::collection($activities);
    }

    /**
     * Display the specified activity log.
     *
     * GET /v1/activity-logs/{activity}
     *
     * Returns single activity log with relationships.
     * Authorization checked via ActivityPolicy.
     *
     * @param  Activity  $activity  Activity model (route model binding)
     * @return ActivityResource Single activity log
     */
    public function show(Request $request, Activity $activity): ActivityResource
    {
        $this->authorize('view', $activity);

        // Eager load relationships
        $activity->load(['causer', 'subject', 'organizationalUnit']);

        return new ActivityResource($activity);
    }

    /**
     * Verify activity log integrity.
     *
     * GET /v1/activity-logs/{activity}/verify
     *
     * Returns verification status for:
     * - Hash chain (previous_hash linkage)
     * - Merkle tree proof (batch verification)
     * - OpenTimestamp proof (Bitcoin anchoring)
     *
     * @param  Activity  $activity  Activity model (route model binding)
     * @return JsonResponse Verification results
     */
    public function verify(Request $request, Activity $activity): JsonResponse
    {
        $this->authorize('view', $activity);

        return response()->json([
            'data' => [
                'activity_id' => $activity->id,
                'verification' => [
                    'chain_valid' => $activity->verifyChain(),
                    'chain_link_valid' => $activity->verifyChainLink(),
                    'merkle_valid' => $activity->verifyMerkleProof(),
                    'ots_valid' => $activity->verifyOpenTimestamp(),
                ],
                'details' => [
                    'event_hash' => $activity->event_hash,
                    'previous_hash' => $activity->previous_hash,
                    'merkle_root' => $activity->merkle_root,
                    'merkle_batch_id' => $activity->merkle_batch_id,
                    'ots_confirmed_at' => \App\Support\ApiTimestamp::nullable($activity->ots_confirmed_at),
                    'is_orphaned_genesis' => $activity->is_orphaned_genesis,
                    'orphaned_reason' => $activity->orphaned_reason,
                ],
            ],
        ]);
    }

    /**
     * Apply scoped filtering based on organizational scopes and leadership levels.
     *
     * CRITICAL AUTHORIZATION LOGIC (ADR-010 + ADR-009):
     * 1. Users without organizational scopes: see all tenant activities, including global ones (no organizational_unit_id).
     * 2. Users with organizational scopes: see only activities within their scoped organizational units (global activities are excluded).
     * 3. Apply leadership level filtering on the scoped result (only subordinates' activities).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Activity>  $query
     * @param  User  $user
     * @return \Illuminate\Database\Eloquent\Builder<Activity>
     */
    protected function applyScopedFiltering($query, $user)
    {
        $canReadSystemActivities = $user->can('activity_log.read_system');

        if (! $canReadSystemActivities) {
            $query->where(function ($visibleActivityQuery) use ($user) {
                $visibleActivityQuery->where(function ($nonUserQuery) {
                    $nonUserQuery->where('causer_type', '!=', User::class)
                        ->orWhereNull('causer_type');
                })->orWhere(function ($employeeBackedUserQuery) {
                    $employeeBackedUserQuery->where('causer_type', User::class)
                        ->whereNotNull('causer_id')
                        ->where(function ($employeeContextQuery) {
                            $employeeContextQuery->whereNotNull('causer_employee_id')
                                ->orWhereExists(function ($employeeCheckQuery): void {
                                    /** @var \Illuminate\Database\Query\Builder $employeeCheckQuery */
                                    $employeeCheckQuery->select(DB::raw(1))
                                        ->from('employees')
                                        ->whereColumn('employees.user_id', 'activity_log.causer_id');
                                });
                        });
                })->orWhere(function ($ownPrivilegedActivityQuery) use ($user) {
                    $ownPrivilegedActivityQuery->where('causer_type', User::class)
                        ->where('causer_id', $user->id);
                });
            });
        }

        // Get user's organizational scopes
        $scopes = $user->organizationalScopes()->get();

        if ($scopes->isEmpty()) {
            // User has no scopes - can see all activities (global access)
            return $query;
        }

        // User has scopes - show only scoped activities
        // Collect accessible organizational unit IDs
        $accessibleUnitIds = $scopes->pluck('organizational_unit_id')->unique()->toArray();

        // Collect viewable rank ranges per organizational unit
        $rankRangesByUnit = [];
        foreach ($scopes as $scope) {
            $unitId = $scope->organizational_unit_id;
            if (! isset($rankRangesByUnit[$unitId])) {
                $rankRangesByUnit[$unitId] = [];
            }
            $rankRangesByUnit[$unitId][] = [
                'min' => $scope->min_viewable_rank,
                'max' => $scope->max_viewable_rank,
            ];
        }

        // Apply filtering: activities in accessible organizational units only
        $query->whereIn('organizational_unit_id', $accessibleUnitIds);

        // Leadership level filtering (only for User causers with Employee records)
        $query->where(function ($leadershipQuery) use ($rankRangesByUnit, $canReadSystemActivities) {
            // Activities without User causer (system-generated) - always visible
            $leadershipQuery->where(function ($nonUserQuery) {
                $nonUserQuery->where('causer_type', '!=', User::class)
                    ->orWhereNull('causer_type');
            });

            if ($canReadSystemActivities) {
                // OR activities with User causer but no Employee record (system users/admins)
                $leadershipQuery->orWhere(function ($systemUserQuery) {
                    $systemUserQuery->where('causer_type', User::class)
                        ->whereNotNull('causer_id')
                        ->whereNull('causer_employee_id')
                        ->whereNotExists(function ($employeeCheckQuery): void {
                            /** @var \Illuminate\Database\Query\Builder $employeeCheckQuery */
                            $employeeCheckQuery->select(DB::raw(1))
                                ->from('employees')
                                ->whereColumn('employees.user_id', 'activity_log.causer_id');
                        });
                });
            }

            // OR activities with User causer WITH Employee record - apply rank filtering
            $leadershipQuery->orWhere(function ($userCauserQuery) use ($rankRangesByUnit) {
                $userCauserQuery->where('causer_type', User::class)
                    ->whereNotNull('causer_id');

                // For each organizational unit, check if activity is in that unit AND causer matches rank range
                $userCauserQuery->where(function ($unitsQuery) use ($rankRangesByUnit) {
                    foreach ($rankRangesByUnit as $unitId => $rankRanges) {
                        $unitsQuery->orWhere(function ($unitQuery) use ($unitId, $rankRanges) {
                            // Activity must be in THIS specific organizational unit
                            $unitQuery->where('organizational_unit_id', $unitId);

                            // AND causer must match rank range for THIS unit
                            $unitQuery->whereExists(function ($employeeQuery) use ($rankRanges): void {
                                /** @var \Illuminate\Database\Query\Builder $employeeQuery */
                                $employeeQuery->select(DB::raw(1))
                                    ->from('employees')
                                    ->whereColumn('employees.user_id', 'activity_log.causer_id')
                                    ->whereColumn('employees.organizational_unit_id', 'activity_log.organizational_unit_id')
                                    ->where(function ($rankQueryBuilder) use ($rankRanges): void {
                                        /** @var \Illuminate\Database\Query\Builder $rankQueryBuilder */
                                        foreach ($rankRanges as $range) {
                                            $rankQueryBuilder->orWhere(function ($rangeQuery) use ($range): void {
                                                /** @var \Illuminate\Database\Query\Builder $rangeQuery */
                                                // Handle 0/0 scope (non-management only)
                                                if ($range['max'] === 0) {
                                                    $rangeQuery->where('management_level', 0);
                                                } else {
                                                    // Management level scopes (1-255)
                                                    $rangeQuery->where('management_level', '>', 0);

                                                    if ($range['min'] !== null) {
                                                        $rangeQuery->where('management_level', '>=', $range['min']);
                                                    }

                                                    if ($range['max'] !== null) {
                                                        $rangeQuery->where('management_level', '<=', $range['max']);
                                                    }
                                                }
                                            });
                                        }
                                    });
                            });
                        });
                    }
                });
            });

            // OR deprovisioned employee causers whose immutable rank context was preserved before unlinking
            $leadershipQuery->orWhere(function ($deprovisionedCauserQuery) use ($rankRangesByUnit) {
                $deprovisionedCauserQuery->where('causer_type', User::class)
                    ->whereNotNull('causer_id')
                    ->whereNotNull('causer_employee_id')
                    ->whereNotNull('causer_employee_management_level')
                    ->whereNotExists(function ($employeeCheckQuery): void {
                        /** @var \Illuminate\Database\Query\Builder $employeeCheckQuery */
                        $employeeCheckQuery->select(DB::raw(1))
                            ->from('employees')
                            ->whereColumn('employees.user_id', 'activity_log.causer_id')
                            ->whereColumn('employees.organizational_unit_id', 'activity_log.organizational_unit_id');
                    })
                    ->where(function ($unitsQuery) use ($rankRangesByUnit) {
                        foreach ($rankRangesByUnit as $unitId => $rankRanges) {
                            $unitsQuery->orWhere(function ($unitQuery) use ($unitId, $rankRanges) {
                                $unitQuery->where('organizational_unit_id', $unitId)
                                    ->where('causer_employee_organizational_unit_id', $unitId)
                                    ->where(function ($rankQueryBuilder) use ($rankRanges): void {
                                        /** @var \Illuminate\Database\Query\Builder $rankQueryBuilder */
                                        foreach ($rankRanges as $range) {
                                            $rankQueryBuilder->orWhere(function ($rangeQuery) use ($range): void {
                                                /** @var \Illuminate\Database\Query\Builder $rangeQuery */
                                                if ($range['max'] === 0) {
                                                    $rangeQuery->where('causer_employee_management_level', 0);
                                                } else {
                                                    $rangeQuery->where('causer_employee_management_level', '>', 0);

                                                    if ($range['min'] !== null) {
                                                        $rangeQuery->where('causer_employee_management_level', '>=', $range['min']);
                                                    }

                                                    if ($range['max'] !== null) {
                                                        $rangeQuery->where('causer_employee_management_level', '<=', $range['max']);
                                                    }
                                                }
                                            });
                                        }
                                    });
                            });
                        }
                    });
            });
        });

        return $query;
    }

    /**
     * Apply user-provided filters to the query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Activity>  $query
     */
    protected function applyFilters($query, IndexActivityLogRequest $request): void
    {
        // Date range filter
        if ($request->has('from_date')) {
            $query->where('created_at', '>=', $request->date('from_date'));
        }

        if ($request->has('to_date')) {
            $toDate = $request->date('to_date');
            if ($toDate !== null) {
                $query->where('created_at', '<=', $toDate->endOfDay());
            }
        }

        // Log name filter (exact match)
        if ($request->has('log_name')) {
            $query->where('log_name', $request->string('log_name')->toString());
        }

        // Search in description and persisted subject metadata (case-insensitive)
        if ($request->has('search')) {
            $search = LikePattern::escape($request->string('search')->toString());
            $query->where(function ($searchQuery) use ($search): void {
                $like = "%{$search}%";

                $searchQuery->where('description', 'ilike', $like)
                    ->orWhereRaw("coalesce(properties::jsonb ->> 'subject_name', '') ilike ?", [$like])
                    ->orWhereRaw("coalesce(properties::jsonb ->> 'subject_identifier', '') ilike ?", [$like]);
            });
        }

        // Organizational unit filter
        if ($request->has('organizational_unit_id')) {
            $query->where('organizational_unit_id', $request->string('organizational_unit_id')->toString());
        }

        // Causer filter
        if ($request->has('causer_type')) {
            $query->where('causer_type', $request->string('causer_type')->toString());
        }

        if ($request->has('causer_id')) {
            $query->where('causer_id', $request->string('causer_id')->toString());
        }

        // Subject filter
        if ($request->has('subject_type')) {
            $query->where('subject_type', $request->string('subject_type')->toString());
        }

        if ($request->has('subject_id')) {
            $query->where('subject_id', $request->string('subject_id')->toString());
        }
    }
}

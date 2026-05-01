<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\Assignment\IndexSiteAssignmentRequest;
use App\Http\Requests\Api\V1\Assignment\StoreSiteAssignmentRequest;
use App\Http\Requests\Api\V1\Assignment\UpdateAssignmentRequest;
use App\Http\Resources\Api\V1\SiteAssignmentResource;
use App\Models\Site;
use App\Models\SiteAssignment;
use App\Models\User;
use App\Services\EmployeeComplianceService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for managing site assignments.
 *
 * Handles flexible user-to-site role assignments with customizable role names.
 * Allows organizations to use their own terminology (e.g., "Account Manager",
 * "Site Manager", "Operations Lead", "Quality Manager").
 *
 * @see SiteAssignment
 * @see SecPal/api#315 Assignment API endpoints
 * @see SecPal/.github#210 Customer & Site Management Epic
 */
class SiteAssignmentController extends AssignmentController
{
    /**
     * List assignments for a site.
     *
     * Returns all user assignments for the specified site, optionally filtered
     * by role or active status. Includes eager-loaded user relationship.
     *
     * Query Parameters:
     * - role (string): Filter by specific role name
     * - active_only (boolean): Only include currently active assignments
     *
     * @return JsonResponse Array of SiteAssignmentResource
     */
    public function index(IndexSiteAssignmentRequest $request, Site $site): JsonResponse
    {
        $this->authorize('view', $site);

        /** @var array{active_only?: mixed, role?: string} $validated */
        $validated = $request->validated();
        $role = $validated['role'] ?? null;

        $assignments = $site->assignments()
            ->with(['user', 'site'])
            ->when(is_string($role), fn ($q) => $q->where('role', $role))
            ->when($request->boolean('active_only'), fn ($q) => $q->currentlyActive())
            ->get();

        return response()->json([
            'data' => SiteAssignmentResource::collection($assignments),
        ]);
    }

    /**
     * Create a new site assignment.
     *
     * Assigns a user to a site with a flexible role name. Prevents duplicate
     * assignments (same user + role combination) and returns 409 Conflict if
     * the assignment already exists.
     *
     * @return JsonResponse SiteAssignmentResource (201 Created) or error (409 Conflict)
     */
    public function store(StoreSiteAssignmentRequest $request, Site $site, EmployeeComplianceService $complianceService): JsonResponse
    {
        $this->authorize('create', [SiteAssignment::class, $site]);

        $validated = $request->validated();
        $validated['tenant_id'] = $request->input('tenant_id');
        $validated['site_id'] = $site->id;

        /** @var User $targetUser */
        $targetUser = User::query()->with('employee')->findOrFail($validated['user_id']);
        $complianceBlockingResponse = $this->complianceBlockingResponse($targetUser, $complianceService);

        if ($complianceBlockingResponse instanceof JsonResponse) {
            return $complianceBlockingResponse;
        }

        // Check for existing assignment with same user+role
        $existing = SiteAssignment::where('site_id', $site->id)
            ->where('user_id', $validated['user_id'])
            ->where('role', $validated['role'])
            ->first();

        if ($existing !== null) {
            return response()->json([
                'message' => 'Assignment already exists for this user and role',
            ], Response::HTTP_CONFLICT);
        }

        $assignment = SiteAssignment::create($validated);

        return response()->json([
            'data' => new SiteAssignmentResource($assignment->load(['user', 'site'])),
        ], Response::HTTP_CREATED);
    }

    /**
     * Update an existing site assignment.
     *
     * Allows updating role, validity period, and notes. Uses PATCH semantics
     * (all fields optional). Authorization checks that user can update the parent site.
     *
     * @return JsonResponse SiteAssignmentResource (200 OK)
     */
    public function update(UpdateAssignmentRequest $request, SiteAssignment $siteAssignment): JsonResponse
    {
        $this->authorize('update', $siteAssignment);

        $siteAssignment->update($request->validated());

        return response()->json([
            'data' => new SiteAssignmentResource($siteAssignment->fresh(['user', 'site'])),
        ]);
    }

    /**
     * Delete a site assignment.
     *
     * Permanently removes the assignment. Authorization checks that user can
     * update the parent site.
     *
     * @return Response Empty response (204 No Content)
     */
    public function destroy(SiteAssignment $siteAssignment): Response
    {
        $this->authorize('delete', $siteAssignment);

        $siteAssignment->delete();

        return response()->noContent();
    }
}

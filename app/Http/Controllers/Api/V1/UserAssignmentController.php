<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CustomerAssignmentResource;
use App\Http\Resources\Api\V1\SiteAssignmentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controller for retrieving current user's assignments.
 *
 * Provides endpoints for authenticated users to view their own customer and site
 * assignments. Useful for "My Assignments" UI and determining user's access scope.
 *
 * @see \App\Models\CustomerAssignment
 * @see \App\Models\SiteAssignment
 * @see SecPal/api#315 Assignment API endpoints
 * @see SecPal/.github#210 Customer & Site Management Epic
 */
class UserAssignmentController extends Controller
{
    /**
     * Get current user's customer assignments.
     *
     * Returns all customer assignments for the authenticated user, optionally
     * filtered to only include currently active assignments. Includes eager-loaded
     * customer relationship.
     *
     * Query Parameters:
     * - active_only (boolean): Only include currently active assignments
     *
     * @return JsonResponse Array of CustomerAssignmentResource
     */
    public function customerAssignments(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $assignments = $user->customerAssignments()
            ->with('customer')
            ->when($request->boolean('active_only'), fn ($q) => $q->currentlyActive())
            ->get();

        return response()->json([
            'data' => CustomerAssignmentResource::collection($assignments),
        ]);
    }

    /**
     * Get current user's site assignments.
     *
     * Returns all site assignments for the authenticated user, optionally
     * filtered to only include currently active assignments. Includes eager-loaded
     * site and site.customer relationships.
     *
     * Query Parameters:
     * - active_only (boolean): Only include currently active assignments
     *
     * @return JsonResponse Array of SiteAssignmentResource
     */
    public function siteAssignments(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $assignments = $user->siteAssignments()
            ->with(['site', 'site.customer'])
            ->when($request->boolean('active_only'), fn ($q) => $q->currentlyActive())
            ->get();

        return response()->json([
            'data' => SiteAssignmentResource::collection($assignments),
        ]);
    }
}

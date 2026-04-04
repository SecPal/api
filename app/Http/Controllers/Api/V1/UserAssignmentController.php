<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CustomerAssignmentResource;
use App\Http\Resources\Api\V1\SiteAssignmentResource;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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
        /** @var int $tenantId */
        $tenantId = $request->get('tenant_id');

        $assignments = $user->customerAssignments()
            ->where('tenant_id', $tenantId)
            ->with(['user', 'customer'])
            ->when($request->boolean('active_only'), fn ($q) => $q->currentlyActive())
            ->get();

        $this->hydrateCustomerUpdateFlags($assignments, $user, $tenantId);

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
        /** @var int $tenantId */
        $tenantId = $request->get('tenant_id');

        $assignments = $user->siteAssignments()
            ->where('tenant_id', $tenantId)
            ->with(['user', 'site', 'site.customer'])
            ->when($request->boolean('active_only'), fn ($q) => $q->currentlyActive())
            ->get();

        $this->hydrateSiteUpdateFlags($assignments, $user, $tenantId);

        return response()->json([
            'data' => SiteAssignmentResource::collection($assignments),
        ]);
    }

    /**
     * @param  EloquentCollection<int, \App\Models\CustomerAssignment>  $assignments
     */
    private function hydrateCustomerUpdateFlags(EloquentCollection $assignments, \App\Models\User $user, int $tenantId): void
    {
        $canUpdateAnyCustomer = $user->can('customers.update');
        $updateableCustomerLookup = $canUpdateAnyCustomer
            ? []
            : array_fill_keys($this->normalizeLookupKeys(
                $user->customerAssignments()
                    ->where('tenant_id', $tenantId)
                    ->currentlyActive()
                    ->pluck('customer_id')
                    ->all()
            ), true);

        foreach ($assignments as $assignment) {
            $customer = $assignment->customer;

            if ($customer === null) {
                continue;
            }

            $customer->setAttribute(
                '_resource_can_update',
                $canUpdateAnyCustomer || isset($updateableCustomerLookup[$customer->id])
            );
        }
    }

    /**
     * @param  EloquentCollection<int, \App\Models\SiteAssignment>  $assignments
     */
    private function hydrateSiteUpdateFlags(EloquentCollection $assignments, \App\Models\User $user, int $tenantId): void
    {
        $canUpdateAnySite = $user->can('sites.update');
        $canUpdateAnyCustomer = $user->can('customers.update');

        $updateableSiteLookup = $canUpdateAnySite
            ? []
            : array_fill_keys($this->normalizeLookupKeys(
                $user->siteAssignments()
                    ->where('tenant_id', $tenantId)
                    ->currentlyActive()
                    ->pluck('site_id')
                    ->all()
            ), true);

        $updateableCustomerLookup = $canUpdateAnyCustomer
            ? []
            : array_fill_keys($this->normalizeLookupKeys(
                $user->customerAssignments()
                    ->where('tenant_id', $tenantId)
                    ->currentlyActive()
                    ->pluck('customer_id')
                    ->all()
            ), true);

        foreach ($assignments as $assignment) {
            $site = $assignment->site;

            if ($site === null) {
                continue;
            }

            $site->setAttribute(
                '_resource_can_update',
                $canUpdateAnySite || isset($updateableSiteLookup[$site->id])
            );

            $customer = $site->customer;

            if ($customer === null) {
                continue;
            }

            $customer->setAttribute(
                '_resource_can_update',
                $canUpdateAnyCustomer || isset($updateableCustomerLookup[$customer->id])
            );
        }
    }

    /**
     * @param  array<mixed>  $ids
     * @return list<string>
     */
    private function normalizeLookupKeys(array $ids): array
    {
        $normalized = [];

        foreach ($ids as $id) {
            if (is_string($id) || is_int($id) || $id instanceof \Stringable) {
                $normalized[] = (string) $id;
            }
        }

        return $normalized;
    }
}

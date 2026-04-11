<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQualificationRequest;
use App\Http\Requests\UpdateQualificationRequest;
use App\Http\Resources\QualificationResource;
use App\Models\Qualification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * QualificationController handles Qualification resource CRUD operations.
 *
 * Manages both system-wide qualifications and tenant-specific custom qualifications.
 * All operations are protected by QualificationPolicy.
 */
class QualificationController extends Controller
{
    /**
     * Display a listing of qualifications.
     *
     * GET /api/v1/qualifications
     *
     * Returns system qualifications + tenant-specific custom qualifications.
     * Supports filtering by:
     * - is_system_qualification (boolean)
     * - category
     * - is_mandatory (boolean)
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Qualification::class);

        /** @var int $tenantId */
        $tenantId = $request->input('tenant_id');

        // Fetch system qualifications (tenant_id = null) + tenant-specific qualifications
        $query = Qualification::where(function ($q) use ($tenantId) {
            $q->whereNull('tenant_id')
                ->orWhere('tenant_id', $tenantId);
        });

        // Filter by is_system_qualification
        if ($request->has('is_system_qualification')) {
            $isSystem = filter_var($request->input('is_system_qualification'), FILTER_VALIDATE_BOOLEAN);
            if ($isSystem) {
                $query->where('is_system_qualification', true)->whereNull('tenant_id');
            } else {
                $query->where('is_system_qualification', false)->where('tenant_id', $tenantId);
            }
        }

        // Filter by category
        if ($request->has('category')) {
            $query->where('category', $request->input('category'));
        }

        // Filter by is_mandatory
        if ($request->has('is_mandatory')) {
            $query->where('is_mandatory', filter_var($request->input('is_mandatory'), FILTER_VALIDATE_BOOLEAN));
        }

        $qualifications = $query->orderBy('sort_order')->orderBy('name')->get();

        return response()->json([
            'data' => QualificationResource::collection($qualifications),
        ]);
    }

    /**
     * Store a newly created custom qualification.
     *
     * POST /api/v1/qualifications
     *
     * HR can create tenant-specific custom qualifications.
     * System qualifications are seeded and cannot be created via API.
     */
    public function store(StoreQualificationRequest $request): JsonResponse
    {
        $this->authorize('create', Qualification::class);

        /** @var int $tenantId */
        $tenantId = $request->input('tenant_id');

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        $qualification = Qualification::create([
            'tenant_id' => $tenantId,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'category' => $validated['category'],
            'requires_renewal' => $validated['requires_renewal'],
            'renewal_period_months' => $validated['renewal_period_months'] ?? null,
            'is_mandatory' => $validated['is_mandatory'],
            'is_system_qualification' => false, // Custom qualifications are never system qualifications
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        return response()->json([
            'data' => new QualificationResource($qualification),
        ], Response::HTTP_CREATED);
    }

    /**
     * Display the specified qualification.
     *
     * GET /api/v1/qualifications/{qualification}
     */
    public function show(Qualification $qualification): JsonResponse
    {
        $this->authorize('view', $qualification);

        return response()->json([
            'data' => new QualificationResource($qualification),
        ]);
    }

    /**
     * Update the specified qualification.
     *
     * PATCH /api/v1/qualifications/{qualification}
     *
     * System qualifications cannot be updated via API.
     */
    public function update(UpdateQualificationRequest $request, Qualification $qualification): JsonResponse
    {
        $this->authorize('update', $qualification);

        if ($qualification->is_system_qualification) {
            return response()->json([
                'message' => __('System qualifications cannot be modified'),
            ], Response::HTTP_FORBIDDEN);
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        $qualification->update($validated);

        /** @var Qualification $freshQualification */
        $freshQualification = $qualification->fresh();

        return response()->json([
            'data' => new QualificationResource($freshQualification),
        ]);
    }

    /**
     * Remove the specified qualification (soft delete).
     *
     * DELETE /api/v1/qualifications/{qualification}
     *
     * System qualifications cannot be deleted via API.
     */
    public function destroy(Qualification $qualification): Response|JsonResponse
    {
        $this->authorize('delete', $qualification);

        if ($qualification->is_system_qualification) {
            return response()->json([
                'message' => __('System qualifications cannot be deleted'),
            ], Response::HTTP_FORBIDDEN);
        }

        $qualification->delete();

        return response()->noContent();
    }
}

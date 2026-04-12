<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AttachQualificationRequest;
use App\Http\Requests\UpdateEmployeeQualificationRequest;
use App\Http\Resources\EmployeeQualificationResource;
use App\Models\Employee;
use App\Models\EmployeeQualification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * EmployeeQualificationController handles employee qualification assignments.
 *
 * Manages the pivot relationship between employees and qualifications with certificate details.
 * All operations are protected by EmployeeQualificationPolicy.
 */
class EmployeeQualificationController extends Controller
{
    /**
     * Display a listing of an employee's qualifications.
     *
     * GET /api/v1/employees/{employee}/qualifications
     */
    public function index(Request $request, Employee $employee): JsonResponse
    {
        $this->authorize('viewAny', [EmployeeQualification::class, $employee]);

        // Check organizational scope access for scoped users
        /** @var \App\Models\User $user */
        $user = $request->user();
        $hasScopes = $user->organizationalScopes()->exists();

        if ($hasScopes && $employee->organizationalUnit !== null) {
            if (! $user->hasAccessToUnit($employee->organizationalUnit)) {
                abort(Response::HTTP_FORBIDDEN, 'You do not have access to this employee\'s organizational unit');
            }
        }

        $qualifications = $employee->employeeQualifications()
            ->with(['qualification', 'employee'])
            ->get();

        return response()->json([
            'data' => EmployeeQualificationResource::collection($qualifications),
        ]);
    }

    /**
     * Attach a qualification to an employee.
     *
     * POST /api/v1/employees/{employee}/qualifications
     */
    public function store(AttachQualificationRequest $request, Employee $employee): JsonResponse
    {
        $this->authorize('create', [EmployeeQualification::class, $employee]);

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        // Check if qualification already attached
        $existing = EmployeeQualification::where('employee_id', $employee->id)
            ->where('qualification_id', $validated['qualification_id'])
            ->first();

        if ($existing) {
            return response()->json([
                'message' => __('Qualification is already attached to this employee'),
            ], Response::HTTP_CONFLICT);
        }

        $employeeQualification = EmployeeQualification::create([
            'employee_id' => $employee->id,
            'qualification_id' => $validated['qualification_id'],
            'obtained_date' => $validated['obtained_date'],
            'expiry_date' => $validated['expiry_date'] ?? null,
            'certificate_number' => $validated['certificate_number'] ?? null,
            'issuing_authority' => $validated['issuing_authority'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'document_path' => $validated['document_path'] ?? null,
            'status' => $validated['status'] ?? 'valid',
        ]);

        $employeeQualification->load('qualification');

        return response()->json([
            'data' => new EmployeeQualificationResource($employeeQualification),
        ], Response::HTTP_CREATED);
    }

    /**
     * Display the specified employee qualification.
     *
     * GET /api/v1/employee-qualifications/{employeeQualification}
     */
    public function show(EmployeeQualification $employeeQualification): JsonResponse
    {
        $this->authorize('view', $employeeQualification);

        $employeeQualification->load(['qualification', 'employee']);

        return response()->json([
            'data' => new EmployeeQualificationResource($employeeQualification),
        ]);
    }

    /**
     * Update the specified employee qualification.
     *
     * PATCH /api/v1/employee-qualifications/{employeeQualification}
     */
    public function update(UpdateEmployeeQualificationRequest $request, EmployeeQualification $employeeQualification): JsonResponse
    {
        $this->authorize('update', $employeeQualification);

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        $employeeQualification->update($validated);

        /** @var EmployeeQualification $fresh */
        $fresh = $employeeQualification->fresh();
        $fresh->load('qualification');

        return response()->json([
            'data' => new EmployeeQualificationResource($fresh),
        ]);
    }

    /**
     * Detach qualification from employee.
     *
     * DELETE /api/v1/employee-qualifications/{employeeQualification}
     */
    public function destroy(EmployeeQualification $employeeQualification): Response
    {
        $this->authorize('delete', $employeeQualification);

        $employeeQualification->delete();

        return response()->noContent();
    }
}

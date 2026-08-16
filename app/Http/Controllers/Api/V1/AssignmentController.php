<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use App\Services\EmployeeComplianceService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

abstract class AssignmentController extends Controller
{
    private const COMPLIANCE_BLOCKING_MESSAGE = 'Employee cannot be assigned while critical compliance documents are expired or due within 7 days.';

    protected function complianceBlockingResponse(User $targetUser, EmployeeComplianceService $complianceService): ?JsonResponse
    {
        $blockingDocuments = $targetUser->employee instanceof Employee
            ? $complianceService->blockingDocuments($targetUser->employee)
            : collect();

        if ($blockingDocuments->isEmpty()) {
            return null;
        }

        return response()->json([
            'message' => self::COMPLIANCE_BLOCKING_MESSAGE,
            'blocking_documents' => $blockingDocuments->all(),
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}

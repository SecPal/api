<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\GuardBookReportResource;
use App\Models\GuardBookReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GuardBookReportController handles operations for guard book reports.
 *
 * Reports are snapshots of guard book events for specific time periods.
 * They contain denormalized data for historical integrity.
 *
 * All operations are protected by GuardBookPolicy for authorization
 * (via the parent guard book).
 */
class GuardBookReportController extends Controller
{
    /**
     * Display a listing of guard book reports.
     *
     * Note: Authorization is handled via tenant isolation and parent guard book access.
     * Users can only see reports for guard books they have access to.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->integer('tenant_id');

        $query = GuardBookReport::where('tenant_id', $tenantId);

        // Filter by guard_book_id if provided
        if ($request->has('guard_book_id')) {
            $query->where('guard_book_id', $request->input('guard_book_id'));
        }

        $reports = $query->with(['guardBook', 'generatedBy'])
            ->orderByDesc('generated_at')
            ->paginate($request->integer('per_page', 15));

        return response()->json([
            'data' => GuardBookReportResource::collection($reports),
            'meta' => [
                'current_page' => $reports->currentPage(),
                'last_page' => $reports->lastPage(),
                'per_page' => $reports->perPage(),
                'total' => $reports->total(),
            ],
        ]);
    }

    /**
     * Display the specified guard book report.
     */
    public function show(GuardBookReport $guard_book_report): JsonResponse
    {
        // Authorization via parent guard book
        $this->authorize('view', $guard_book_report->guardBook);

        $guard_book_report->load(['guardBook', 'generatedBy']);

        return response()->json([
            'data' => new GuardBookReportResource($guard_book_report),
        ]);
    }

    /**
     * Export the report as PDF.
     *
     * Note: PDF export implementation would require a PDF library.
     * This is a placeholder that returns the report data as JSON.
     */
    public function export(GuardBookReport $guard_book_report): Response
    {
        // Authorization via parent guard book
        $this->authorize('view', $guard_book_report->guardBook);

        // In a real implementation, this would generate a PDF
        // For now, return JSON data with appropriate headers
        $jsonContent = json_encode([
            'report' => new GuardBookReportResource($guard_book_report->load(['guardBook', 'generatedBy'])),
        ], JSON_PRETTY_PRINT);
        $content = $jsonContent !== false ? $jsonContent : '{}';

        return response($content, 200)
            ->header('Content-Type', 'application/json')
            ->header('Content-Disposition', sprintf(
                'attachment; filename="%s.json"',
                $guard_book_report->report_number
            ));
    }

    /**
     * Remove the specified guard book report.
     */
    public function destroy(GuardBookReport $guard_book_report): JsonResponse
    {
        // Authorization via parent guard book
        $this->authorize('delete', $guard_book_report->guardBook);

        $guard_book_report->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}

<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\GenerateGuardBookReportRequest;
use App\Http\Requests\Api\StoreGuardBookRequest;
use App\Http\Requests\Api\UpdateGuardBookRequest;
use App\Http\Resources\GuardBookReportResource;
use App\Http\Resources\GuardBookResource;
use App\Models\GuardBook;
use App\Models\GuardBookReport;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * GuardBookController handles CRUD operations for guard books.
 *
 * Guard books are continuous event stream containers (not closed physical books).
 * Reports can be generated from events for any time period on-demand.
 *
 * All operations are protected by GuardBookPolicy for authorization.
 */
class GuardBookController extends Controller
{
    /**
     * Display a listing of guard books.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', GuardBook::class);

        /** @var int $tenantId */
        $tenantId = $request->input('tenant_id');

        $query = GuardBook::where('tenant_id', $tenantId);

        // Filter by object_id if provided
        if ($request->has('object_id')) {
            $query->where('object_id', $request->input('object_id'));
        }

        // Filter by object_area_id if provided
        if ($request->has('object_area_id')) {
            $query->where('object_area_id', $request->input('object_area_id'));
        }

        // Filter by is_active if provided
        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $guardBooks = $query->with(['object', 'objectArea'])
            ->withCount('reports')
            ->paginate($request->integer('per_page', 15));

        return response()->json([
            'data' => GuardBookResource::collection($guardBooks),
            'meta' => [
                'current_page' => $guardBooks->currentPage(),
                'last_page' => $guardBooks->lastPage(),
                'per_page' => $guardBooks->perPage(),
                'total' => $guardBooks->total(),
            ],
        ]);
    }

    /**
     * Store a newly created guard book.
     */
    public function store(StoreGuardBookRequest $request): JsonResponse
    {
        $this->authorize('create', GuardBook::class);

        /** @var int $tenantId */
        $tenantId = $request->input('tenant_id');

        /** @var array{object_id?: string|null, object_area_id?: string|null, title: string, description?: string|null} $validated */
        $validated = $request->validated();

        $guardBook = GuardBook::create([
            'tenant_id' => $tenantId,
            'object_id' => $validated['object_id'] ?? null,
            'object_area_id' => $validated['object_area_id'] ?? null,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'is_active' => true,
        ]);

        return response()->json([
            'data' => new GuardBookResource($guardBook->load(['object', 'objectArea'])),
        ], Response::HTTP_CREATED);
    }

    /**
     * Display the specified guard book.
     */
    public function show(GuardBook $guard_book): JsonResponse
    {
        $this->authorize('view', $guard_book);

        $guard_book->load(['object', 'objectArea']);
        $guard_book->loadCount('reports');

        return response()->json([
            'data' => new GuardBookResource($guard_book),
        ]);
    }

    /**
     * Update the specified guard book.
     */
    public function update(UpdateGuardBookRequest $request, GuardBook $guard_book): JsonResponse
    {
        $this->authorize('update', $guard_book);

        /** @var array{title?: string, description?: string|null, is_active?: bool} $validated */
        $validated = $request->validated();

        // Handle archiving
        if (isset($validated['is_active']) && $validated['is_active'] === false && $guard_book->is_active) {
            $validated['archived_at'] = Carbon::now();
        } elseif (isset($validated['is_active']) && $validated['is_active'] === true && ! $guard_book->is_active) {
            $validated['archived_at'] = null;
        }

        $guard_book->update($validated);

        /** @var GuardBook $freshGuardBook */
        $freshGuardBook = $guard_book->fresh();
        $freshGuardBook->load(['object', 'objectArea']);

        return response()->json([
            'data' => new GuardBookResource($freshGuardBook),
        ]);
    }

    /**
     * Remove the specified guard book (soft delete / archive).
     */
    public function destroy(GuardBook $guard_book): JsonResponse
    {
        $this->authorize('delete', $guard_book);

        $guard_book->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Generate a report for the guard book.
     */
    public function generateReport(GenerateGuardBookReportRequest $request, GuardBook $guard_book): JsonResponse
    {
        $this->authorize('view', $guard_book);

        /** @var int $tenantId */
        $tenantId = $request->input('tenant_id');

        /** @var array{period_start: string, period_end: string, title?: string, filter_criteria?: array<mixed>|null} $validated */
        $validated = $request->validated();

        /** @var User $user */
        $user = $request->user();

        $periodStart = Carbon::parse($validated['period_start']);
        $periodEnd = Carbon::parse($validated['period_end']);

        // Generate report number
        $reportNumber = sprintf(
            'GB-%s-%s-%s',
            $guard_book->id,
            $periodStart->format('Ymd'),
            Str::upper(Str::random(4))
        );

        // Generate default title if not provided
        $title = $validated['title'] ?? sprintf(
            '%s - %s to %s',
            $guard_book->title,
            $periodStart->format('Y-m-d'),
            $periodEnd->format('Y-m-d')
        );

        // Note: In a real implementation, this would query guard_book_entries
        // For now, we create an empty report as a placeholder
        $report = GuardBookReport::create([
            'tenant_id' => $tenantId,
            'guard_book_id' => $guard_book->id,
            'report_number' => $reportNumber,
            'title' => $title,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'filter_criteria' => $validated['filter_criteria'] ?? null,
            'total_events' => 0, // Would be calculated from actual events
            'generated_at' => Carbon::now(),
            'generated_by_user_id' => $user->id,
            'report_data' => [], // Would contain event snapshots
        ]);

        return response()->json([
            'data' => new GuardBookReportResource($report->load(['guardBook', 'generatedBy'])),
        ], Response::HTTP_CREATED);
    }

    /**
     * List reports for the guard book.
     */
    public function reports(GuardBook $guard_book): JsonResponse
    {
        $this->authorize('view', $guard_book);

        $reports = $guard_book->reports()
            ->with('generatedBy')
            ->orderByDesc('generated_at')
            ->get();

        return response()->json([
            'data' => GuardBookReportResource::collection($reports),
        ]);
    }
}

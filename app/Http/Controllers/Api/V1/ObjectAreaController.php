<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateObjectAreaRequest;
use App\Http\Resources\ObjectAreaResource;
use App\Models\ObjectArea;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * ObjectAreaController handles CRUD operations for object areas.
 *
 * Object areas are sub-divisions of objects (e.g., Terminal 1, Parking Lot).
 * Areas can have separate guard books if required.
 *
 * All operations are protected by ObjectPolicy for authorization.
 */
class ObjectAreaController extends Controller
{
    /**
     * Display a listing of object areas.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var int $tenantId */
        $tenantId = $request->input('tenant_id');

        $query = ObjectArea::where('tenant_id', $tenantId);

        // Filter by object_id if provided
        if ($request->has('object_id')) {
            $query->where('object_id', $request->input('object_id'));
        }

        $areas = $query->with('object')->paginate($request->integer('per_page', 15));

        return response()->json([
            'data' => ObjectAreaResource::collection($areas),
            'meta' => [
                'current_page' => $areas->currentPage(),
                'last_page' => $areas->lastPage(),
                'per_page' => $areas->perPage(),
                'total' => $areas->total(),
            ],
        ]);
    }

    /**
     * Display the specified object area.
     */
    public function show(ObjectArea $object_area): JsonResponse
    {
        // Authorization via parent object
        $this->authorize('view', $object_area->object);

        $object_area->load(['object', 'guardBook']);

        return response()->json([
            'data' => new ObjectAreaResource($object_area),
        ]);
    }

    /**
     * Update the specified object area.
     */
    public function update(UpdateObjectAreaRequest $request, ObjectArea $object_area): JsonResponse
    {
        // Authorization via parent object
        $this->authorize('update', $object_area->object);

        /** @var array{name?: string, description?: string|null, requires_separate_guard_book?: bool, metadata?: array<mixed>|null} $validated */
        $validated = $request->validated();

        $object_area->update($validated);

        return response()->json([
            'data' => new ObjectAreaResource($object_area->fresh()?->load('object')),
        ]);
    }

    /**
     * Remove the specified object area (soft delete).
     */
    public function destroy(ObjectArea $object_area): JsonResponse
    {
        // Authorization via parent object
        $this->authorize('delete', $object_area->object);

        $object_area->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}

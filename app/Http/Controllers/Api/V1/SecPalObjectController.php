<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreObjectAreaRequest;
use App\Http\Requests\Api\StoreSecPalObjectRequest;
use App\Http\Requests\Api\UpdateSecPalObjectRequest;
use App\Http\Resources\ObjectAreaResource;
use App\Http\Resources\SecPalObjectResource;
use App\Models\ObjectArea;
use App\Models\SecPalObject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * SecPalObjectController handles CRUD operations for objects (physical locations).
 *
 * Objects are physical locations where security services are provided.
 * Customer users (Client role) have read-only access. Internal employees
 * have full CRUD access based on their organizational unit scopes.
 *
 * All operations are protected by ObjectPolicy for authorization.
 */
class SecPalObjectController extends Controller
{
    /**
     * Display a listing of objects.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SecPalObject::class);

        /** @var int $tenantId */
        $tenantId = $request->input('tenant_id');

        $query = SecPalObject::where('tenant_id', $tenantId);

        // Filter by customer_id if provided
        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->input('customer_id'));
        }

        $objects = $query->with('customer')->paginate($request->integer('per_page', 15));

        return response()->json([
            'data' => SecPalObjectResource::collection($objects),
            'meta' => [
                'current_page' => $objects->currentPage(),
                'last_page' => $objects->lastPage(),
                'per_page' => $objects->perPage(),
                'total' => $objects->total(),
            ],
        ]);
    }

    /**
     * Store a newly created object.
     */
    public function store(StoreSecPalObjectRequest $request): JsonResponse
    {
        $this->authorize('create', SecPalObject::class);

        /** @var int $tenantId */
        $tenantId = $request->input('tenant_id');

        /** @var array{customer_id: string, object_number: string, name: string, address: string, gps_coordinates?: array{lat: float, lon: float}|null, metadata?: array<mixed>|null} $validated */
        $validated = $request->validated();

        $object = SecPalObject::create([
            'tenant_id' => $tenantId,
            'customer_id' => $validated['customer_id'],
            'object_number' => $validated['object_number'],
            'name' => $validated['name'],
            'address' => $validated['address'],
            'gps_coordinates' => $validated['gps_coordinates'] ?? null,
            'metadata' => $validated['metadata'] ?? null,
        ]);

        return response()->json([
            'data' => new SecPalObjectResource($object->load('customer')),
        ], Response::HTTP_CREATED);
    }

    /**
     * Display the specified object.
     */
    public function show(SecPalObject $object): JsonResponse
    {
        $this->authorize('view', $object);

        $object->load(['customer', 'areas']);

        return response()->json([
            'data' => new SecPalObjectResource($object),
        ]);
    }

    /**
     * Update the specified object.
     */
    public function update(UpdateSecPalObjectRequest $request, SecPalObject $object): JsonResponse
    {
        $this->authorize('update', $object);

        /** @var array{object_number?: string, name?: string, address?: string, gps_coordinates?: array{lat: float, lon: float}|null, metadata?: array<mixed>|null} $validated */
        $validated = $request->validated();

        $object->update($validated);

        /** @var SecPalObject $freshObject */
        $freshObject = $object->fresh();
        $freshObject->load('customer');

        return response()->json([
            'data' => new SecPalObjectResource($freshObject),
        ]);
    }

    /**
     * Remove the specified object (soft delete).
     */
    public function destroy(SecPalObject $object): JsonResponse
    {
        $this->authorize('delete', $object);

        $object->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Get all areas of the object.
     */
    public function areas(SecPalObject $object): JsonResponse
    {
        $this->authorize('view', $object);

        $areas = $object->areas()->get();

        return response()->json([
            'data' => ObjectAreaResource::collection($areas),
        ]);
    }

    /**
     * Create a new area for the object.
     */
    public function storeArea(StoreObjectAreaRequest $request, SecPalObject $object): JsonResponse
    {
        $this->authorize('update', $object);

        /** @var int $tenantId */
        $tenantId = $request->input('tenant_id');

        /** @var array{name: string, description?: string|null, requires_separate_guard_book?: bool, metadata?: array<mixed>|null} $validated */
        $validated = $request->validated();

        $area = ObjectArea::create([
            'tenant_id' => $tenantId,
            'object_id' => $object->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'requires_separate_guard_book' => $validated['requires_separate_guard_book'] ?? false,
            'metadata' => $validated['metadata'] ?? null,
        ]);

        return response()->json([
            'data' => new ObjectAreaResource($area->load('object')),
        ], Response::HTTP_CREATED);
    }
}

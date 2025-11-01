<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePersonRequest;
use App\Http\Requests\UpdatePersonRequest;
use App\Http\Resources\PersonCollection;
use App\Http\Resources\PersonResource;
use App\Models\Person;
use App\Repositories\PersonRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Person API Controller.
 *
 * SECURITY:
 * - auth:sanctum middleware required
 * - tenant middleware required (sets tenant_id and Permission team context)
 * - Policy authorization on all methods
 * - FormRequest validation
 * - API Resources hide encrypted/index fields
 */
class PersonController extends Controller
{
    public function __construct(
        private PersonRepository $repository
    ) {}

    /**
     * List all persons for tenant (paginated).
     *
     * GET /api/v1/tenants/{tenant}/persons
     */
    public function index(Request $request): PersonCollection
    {
        $this->authorize('viewAny', Person::class);

        $tenantId = $request->tenant_id;
        $perPage = $request->input('per_page', 15);

        $persons = $this->repository->getAllForTenant($tenantId, $perPage);

        return new PersonCollection($persons);
    }

    /**
     * Create a new person.
     *
     * POST /api/v1/tenants/{tenant}/persons
     */
    public function store(StorePersonRequest $request): JsonResponse
    {
        $this->authorize('create', Person::class);

        $tenantId = $request->tenant_id;
        $validated = $request->validated();

        $person = $this->repository->createOrUpdate($tenantId, $validated);

        Log::info('Person created', [
            'tenant_id' => $tenantId,
            'person_id' => $person->id,
            'user_id' => $request->user()->id,
        ]);

        return (new PersonResource($person))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Show a specific person.
     *
     * GET /api/v1/tenants/{tenant}/persons/{id}
     */
    public function show(Request $request, string $id): PersonResource
    {
        $tenantId = $request->tenant_id;
        $person = $this->repository->findById($tenantId, $id);

        $this->authorize('view', $person);

        return new PersonResource($person);
    }

    /**
     * Update a person.
     *
     * PUT/PATCH /api/v1/tenants/{tenant}/persons/{id}
     */
    public function update(UpdatePersonRequest $request, string $id): PersonResource
    {
        $tenantId = $request->tenant_id;
        $person = $this->repository->findById($tenantId, $id);

        $this->authorize('update', $person);

        $validated = $request->validated();

        // Update via repository (handles encryption and blind indexes)
        $updated = $this->repository->createOrUpdate($tenantId, $validated);

        Log::info('Person updated', [
            'tenant_id' => $tenantId,
            'person_id' => $person->id,
            'user_id' => $request->user()->id,
        ]);

        return new PersonResource($updated);
    }

    /**
     * Delete a person.
     *
     * DELETE /api/v1/tenants/{tenant}/persons/{id}
     */
    public function destroy(Request $request, string $id): \Illuminate\Http\JsonResponse
    {
        $tenantId = $request->tenant_id;
        $person = $this->repository->findById($tenantId, $id);

        $this->authorize('delete', $person);

        $this->repository->delete($tenantId, $id);

        Log::info('Person deleted', [
            'tenant_id' => $tenantId,
            'person_id' => $id,
            'user_id' => $request->user()->id,
        ]);

        return response()->json(null, 204);
    }

    /**
     * Find person by email (blind index search).
     *
     * GET /api/v1/tenants/{tenant}/persons/by-email?email={email}
     */
    public function findByEmail(Request $request): PersonResource|JsonResponse
    {
        $this->authorize('viewAny', Person::class);

        $request->validate([
            'email' => 'required|email',
        ]);

        $tenantId = $request->tenant_id;
        $email = $request->input('email');

        $person = $this->repository->findByEmail($tenantId, $email);

        if (! $person) {
            return response()->json([
                'message' => 'Person not found',
            ], 404);
        }

        return new PersonResource($person);
    }
}

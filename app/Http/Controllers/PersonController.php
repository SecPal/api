<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Http\Controllers;

use App\Http\Requests\StorePersonRequest;
use App\Http\Resources\PersonResource;
use App\Repositories\PersonRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * PersonController handles Person resource API endpoints.
 *
 * All endpoints require:
 * - Sanctum authentication (auth:sanctum)
 * - Tenant context (SetTenant middleware)
 * - Appropriate permissions (person.write, person.read)
 */
class PersonController extends Controller
{
    public function __construct(
        private readonly PersonRepository $repository
    ) {}

    /**
     * Store a new Person.
     *
     * POST /v1/tenants/{tenant}/persons
     *
     * @param  StorePersonRequest  $request  Validated request with email_plain, phone_plain, note_enc
     * @return JsonResponse Created Person (201) with sanitized data
     */
    public function store(StorePersonRequest $request): JsonResponse
    {
        /** @var int $tenantId */
        $tenantId = $request->get('tenant_id');

        $person = $this->repository->createOrUpdate($tenantId, [
            'email_plain' => $request->input('email_plain'),
            'phone_plain' => $request->input('phone_plain'),
            'note_enc' => $request->input('note_enc'),
        ]);

        return (new PersonResource($person))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Find a Person by email.
     *
     * GET /v1/tenants/{tenant}/persons/by-email?email=...
     *
     * @param  Request  $request  Must contain 'email' query parameter
     * @return JsonResponse Person (200) or not found (404)
     */
    public function byEmail(Request $request): JsonResponse
    {
        $email = $request->query('email');

        if (! $email) {
            return response()->json([
                'error' => 'Email query parameter is required',
            ], Response::HTTP_BAD_REQUEST);
        }

        /** @var int $tenantId */
        $tenantId = $request->get('tenant_id');

        $person = $this->repository->findByEmail($tenantId, $email);

        if (! $person) {
            return response()->json([
                'error' => 'Person not found',
            ], Response::HTTP_NOT_FOUND);
        }

        return (new PersonResource($person))->response();
    }
}

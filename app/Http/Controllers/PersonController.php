<?php

/*
 * SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
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
use Illuminate\Support\Facades\Validator;

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
     * @param  StorePersonRequest  $request  Validated request with email_plain, phone_plain, note_plain
     * @return JsonResponse Created Person (201) with sanitized data
     */
    public function store(StorePersonRequest $request): JsonResponse
    {
        /** @var int $tenantId */
        $tenantId = $request->get('tenant_id');

        $attributes = [
            'email_plain' => $request->input('email_plain'),
            'phone_plain' => $request->input('phone_plain'),
        ];

        if ($request->exists('note_plain')) {
            $attributes['note_plain'] = $request->input('note_plain');
        }

        $person = $this->repository->createOrUpdate($tenantId, $attributes);

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
                'message' => __('Email query parameter is required'),
            ], Response::HTTP_BAD_REQUEST);
        }

        $validator = Validator::make([
            'email' => $email,
        ], [
            'email' => ['string', 'email'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => __('The given data was invalid.'),
                'errors' => $validator->errors()->toArray(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var int $tenantId */
        $tenantId = $request->get('tenant_id');

        $person = $this->repository->findByEmail($tenantId, $email);

        if (! $person) {
            return response()->json([
                'message' => __('Person not found'),
            ], Response::HTTP_NOT_FOUND);
        }

        return (new PersonResource($person))->response();
    }
}

<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexContractRequest;
use App\Http\Requests\Api\V1\RetireContractRequest;
use App\Http\Requests\Api\V1\ShowContractRequest;
use App\Http\Requests\Api\V1\StoreContractRequest;
use App\Http\Requests\Api\V1\UpdateContractRequest;
use App\Http\Resources\Api\V1\ContractResource;
use App\Models\User;
use App\Services\ContractService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

final class ContractController extends Controller
{
    public function __construct(private readonly ContractService $service) {}

    public function index(IndexContractRequest $request): AnonymousResourceCollection
    {
        return ContractResource::collection($this->service->list(
            $this->actor($request),
            $request->integer('page', 1),
            $request->integer('per_page', 15),
        ));
    }

    public function store(StoreContractRequest $request): JsonResponse
    {
        return response()->json([
            'data' => new ContractResource(
                $this->service->create($this->actor($request), $request->validated()),
            ),
        ], Response::HTTP_CREATED);
    }

    public function show(ShowContractRequest $request, string $contract): JsonResponse
    {
        return response()->json([
            'data' => new ContractResource(
                $this->service->inspect($this->actor($request), $contract),
            ),
        ]);
    }

    public function update(UpdateContractRequest $request, string $contract): JsonResponse
    {
        /** @var array<string, mixed> $attributes */
        $attributes = $request->safe()->only(UpdateContractRequest::BUSINESS_FIELDS);

        return response()->json([
            'data' => new ContractResource(
                $this->service->update($this->actor($request), $contract, $attributes),
            ),
        ]);
    }

    public function retire(RetireContractRequest $request, string $contract): JsonResponse
    {
        return response()->json([
            'data' => new ContractResource(
                $this->service->retire($this->actor($request), $contract),
            ),
        ]);
    }

    private function actor(
        IndexContractRequest|StoreContractRequest|ShowContractRequest|UpdateContractRequest|RetireContractRequest $request,
    ): User {
        /** @var User $actor */
        $actor = $request->user();

        return $actor;
    }
}

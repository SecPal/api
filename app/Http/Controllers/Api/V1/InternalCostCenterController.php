<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DeactivateInternalCostCenterRequest;
use App\Http\Requests\Api\V1\IndexInternalCostCenterRequest;
use App\Http\Requests\Api\V1\ShowInternalCostCenterRequest;
use App\Http\Requests\Api\V1\StoreInternalCostCenterRequest;
use App\Http\Requests\Api\V1\UpdateInternalCostCenterRequest;
use App\Http\Resources\Api\V1\InternalCostCenterResource;
use App\Models\User;
use App\Services\InternalCostCenterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

final class InternalCostCenterController extends Controller
{
    public function __construct(private readonly InternalCostCenterService $service) {}

    public function index(IndexInternalCostCenterRequest $request): AnonymousResourceCollection
    {
        return InternalCostCenterResource::collection($this->service->list(
            $this->actor($request),
            $request->integer('page', 1),
            $request->integer('per_page', 15),
        ));
    }

    public function store(StoreInternalCostCenterRequest $request): JsonResponse
    {
        return response()->json([
            'data' => new InternalCostCenterResource(
                $this->service->create($this->actor($request), $request->validated()),
            ),
        ], Response::HTTP_CREATED);
    }

    public function show(ShowInternalCostCenterRequest $request, string $internalCostCenter): JsonResponse
    {
        return response()->json([
            'data' => new InternalCostCenterResource(
                $this->service->inspect($this->actor($request), $internalCostCenter),
            ),
        ]);
    }

    public function update(UpdateInternalCostCenterRequest $request, string $internalCostCenter): JsonResponse
    {
        /** @var array<string, mixed> $attributes */
        $attributes = $request->safe()->only(UpdateInternalCostCenterRequest::UPDATE_FIELDS);

        return response()->json([
            'data' => new InternalCostCenterResource(
                $this->service->update($this->actor($request), $internalCostCenter, $attributes),
            ),
        ]);
    }

    public function deactivate(DeactivateInternalCostCenterRequest $request, string $internalCostCenter): JsonResponse
    {
        return response()->json([
            'data' => new InternalCostCenterResource(
                $this->service->deactivate($this->actor($request), $internalCostCenter),
            ),
        ]);
    }

    private function actor(
        IndexInternalCostCenterRequest|StoreInternalCostCenterRequest|ShowInternalCostCenterRequest|UpdateInternalCostCenterRequest|DeactivateInternalCostCenterRequest $request,
    ): User {
        /** @var User $actor */
        $actor = $request->user();

        return $actor;
    }
}

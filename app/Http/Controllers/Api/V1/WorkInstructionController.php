<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexWorkInstructionRequest;
use App\Http\Requests\Api\V1\ShowWorkInstructionRequest;
use App\Http\Requests\Api\V1\StoreWorkInstructionRequest;
use App\Http\Requests\Api\V1\UpdateWorkInstructionRequest;
use App\Http\Requests\Api\V1\WorkInstructionLifecycleActionRequest;
use App\Http\Resources\Api\V1\WorkInstructionResource;
use App\Models\User;
use App\Services\WorkInstructionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

final class WorkInstructionController extends Controller
{
    public function __construct(private readonly WorkInstructionService $service) {}

    public function index(IndexWorkInstructionRequest $request): AnonymousResourceCollection
    {
        return WorkInstructionResource::collection($this->service->list(
            $this->actor($request),
            $request->integer('page', 1),
            $request->integer('per_page', 15),
        ));
    }

    public function store(StoreWorkInstructionRequest $request): JsonResponse
    {
        return response()->json([
            'data' => new WorkInstructionResource(
                $this->service->create($this->actor($request), $request->validated()),
            ),
        ], Response::HTTP_CREATED);
    }

    public function show(ShowWorkInstructionRequest $request, string $workInstruction): JsonResponse
    {
        return $this->resourceResponse(
            $this->service->inspect($this->actor($request), $workInstruction),
        );
    }

    public function update(UpdateWorkInstructionRequest $request, string $workInstruction): JsonResponse
    {
        /** @var array<string, mixed> $attributes */
        $attributes = $request->safe()->only(UpdateWorkInstructionRequest::UPDATE_FIELDS);

        return $this->resourceResponse(
            $this->service->update($this->actor($request), $workInstruction, $attributes),
        );
    }

    public function submitForReview(WorkInstructionLifecycleActionRequest $request, string $workInstruction): JsonResponse
    {
        return $this->resourceResponse(
            $this->service->submitForReview($this->actor($request), $workInstruction),
        );
    }

    public function publish(WorkInstructionLifecycleActionRequest $request, string $workInstruction): JsonResponse
    {
        return $this->resourceResponse(
            $this->service->publish($this->actor($request), $workInstruction),
        );
    }

    public function archive(WorkInstructionLifecycleActionRequest $request, string $workInstruction): JsonResponse
    {
        return $this->resourceResponse(
            $this->service->archive($this->actor($request), $workInstruction),
        );
    }

    private function resourceResponse(\App\Models\WorkInstruction $workInstruction): JsonResponse
    {
        return response()->json(['data' => new WorkInstructionResource($workInstruction)]);
    }

    private function actor(
        IndexWorkInstructionRequest|StoreWorkInstructionRequest|ShowWorkInstructionRequest|UpdateWorkInstructionRequest|WorkInstructionLifecycleActionRequest $request,
    ): User {
        /** @var User $actor */
        $actor = $request->user();

        return $actor;
    }
}

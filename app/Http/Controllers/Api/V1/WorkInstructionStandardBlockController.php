<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexWorkInstructionContentRequest;
use App\Http\Requests\Api\V1\ShowWorkInstructionStandardBlockRequest;
use App\Http\Resources\Api\V1\WorkInstructionStandardBlockResource;
use App\Models\User;
use App\Services\WorkInstructionStandardBlockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class WorkInstructionStandardBlockController extends Controller
{
    public function __construct(private readonly WorkInstructionStandardBlockService $service) {}

    public function index(IndexWorkInstructionContentRequest $request): AnonymousResourceCollection
    {
        return WorkInstructionStandardBlockResource::collection($this->service->list(
            $this->actor($request),
            $request->requestedLocale(),
            $request->integer('page', 1),
            $request->integer('per_page', 15),
        ));
    }

    public function show(ShowWorkInstructionStandardBlockRequest $request, string $standardBlock): JsonResponse
    {
        return response()->json(['data' => new WorkInstructionStandardBlockResource(
            $this->service->inspect($this->actor($request), $standardBlock, $request->requestedLocale()),
        )]);
    }

    private function actor(IndexWorkInstructionContentRequest|ShowWorkInstructionStandardBlockRequest $request): User
    {
        /** @var User $actor */
        $actor = $request->user();

        return $actor;
    }
}

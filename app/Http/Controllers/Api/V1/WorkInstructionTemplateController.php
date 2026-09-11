<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexWorkInstructionContentRequest;
use App\Http\Requests\Api\V1\ReplaceWorkInstructionTemplateRequest;
use App\Http\Requests\Api\V1\ShowWorkInstructionTemplateRequest;
use App\Http\Requests\Api\V1\StoreWorkInstructionTemplateRequest;
use App\Http\Resources\Api\V1\WorkInstructionTemplateResource;
use App\Models\User;
use App\Services\WorkInstructionTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

final class WorkInstructionTemplateController extends Controller
{
    public function __construct(private readonly WorkInstructionTemplateService $service) {}

    public function index(IndexWorkInstructionContentRequest $request): AnonymousResourceCollection
    {
        return WorkInstructionTemplateResource::collection($this->service->list(
            $this->actor($request),
            $request->requestedLocale(),
            $request->integer('page', 1),
            $request->integer('per_page', 15),
        ));
    }

    public function store(StoreWorkInstructionTemplateRequest $request): JsonResponse
    {
        /** @var array<string, array{title: string, body: string}> $translations */
        $translations = $request->validated('translations');

        return response()->json(['data' => new WorkInstructionTemplateResource(
            $this->service->create($this->actor($request), $translations, $request->requestedLocale()),
        )], Response::HTTP_CREATED);
    }

    public function show(ShowWorkInstructionTemplateRequest $request, string $workInstructionTemplate): JsonResponse
    {
        return response()->json(['data' => new WorkInstructionTemplateResource(
            $this->service->inspect($this->actor($request), $workInstructionTemplate, $request->requestedLocale()),
        )]);
    }

    public function update(ReplaceWorkInstructionTemplateRequest $request, string $workInstructionTemplate): JsonResponse
    {
        /** @var array<string, array{title: string, body: string}> $translations */
        $translations = $request->validated('translations');

        return response()->json(['data' => new WorkInstructionTemplateResource(
            $this->service->replace(
                $this->actor($request),
                $workInstructionTemplate,
                $translations,
                $request->requestedLocale(),
            ),
        )]);
    }

    private function actor(IndexWorkInstructionContentRequest|StoreWorkInstructionTemplateRequest|ShowWorkInstructionTemplateRequest|ReplaceWorkInstructionTemplateRequest $request): User
    {
        /** @var User $actor */
        $actor = $request->user();

        return $actor;
    }
}

<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AttachLegalHoldActivityRequest;
use App\Http\Requests\Api\V1\DetachLegalHoldActivityRequest;
use App\Http\Requests\Api\V1\IndexLegalHoldRequest;
use App\Http\Requests\Api\V1\ReleaseLegalHoldRequest;
use App\Http\Requests\Api\V1\ShowLegalHoldRequest;
use App\Http\Requests\Api\V1\StoreLegalHoldRequest;
use App\Http\Resources\Api\V1\LegalHoldActivityAttachmentResource;
use App\Http\Resources\Api\V1\LegalHoldResource;
use App\Http\Resources\Api\V1\LegalHoldSummaryResource;
use App\Models\User;
use App\Services\LegalHoldService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

final class LegalHoldController extends Controller
{
    public function __construct(private readonly LegalHoldService $service) {}

    public function index(IndexLegalHoldRequest $request): AnonymousResourceCollection
    {
        return LegalHoldSummaryResource::collection(
            $this->service->list(
                $this->actor($request),
                $request->integer('page', 1),
                $request->integer('per_page', 15),
            ),
        );
    }

    public function store(StoreLegalHoldRequest $request): JsonResponse
    {
        $legalHold = $this->service->create(
            $this->actor($request),
            $request->string('case_reference')->toString(),
            $request->string('justification')->toString(),
        );

        return response()->json([
            'data' => new LegalHoldResource($legalHold),
        ], Response::HTTP_CREATED);
    }

    public function show(ShowLegalHoldRequest $request, string $legalHold): JsonResponse
    {
        return response()->json([
            'data' => new LegalHoldResource(
                $this->service->inspect($this->actor($request), $legalHold),
            ),
        ]);
    }

    public function attach(AttachLegalHoldActivityRequest $request, string $legalHold): JsonResponse
    {
        return response()->json([
            'data' => new LegalHoldActivityAttachmentResource(
                $this->service->attach(
                    $this->actor($request),
                    $legalHold,
                    $request->integer('activity_id'),
                ),
            ),
        ], Response::HTTP_CREATED);
    }

    public function detach(
        DetachLegalHoldActivityRequest $request,
        string $legalHold,
        string $attachment,
    ): JsonResponse {
        return response()->json([
            'data' => new LegalHoldActivityAttachmentResource(
                $this->service->detach(
                    $this->actor($request),
                    $legalHold,
                    $attachment,
                    $request->string('justification')->toString(),
                ),
            ),
        ]);
    }

    public function release(ReleaseLegalHoldRequest $request, string $legalHold): JsonResponse
    {
        return response()->json([
            'data' => new LegalHoldResource(
                $this->service->release(
                    $this->actor($request),
                    $legalHold,
                    $request->string('justification')->toString(),
                ),
            ),
        ]);
    }

    private function actor(IndexLegalHoldRequest|StoreLegalHoldRequest|ShowLegalHoldRequest|AttachLegalHoldActivityRequest|DetachLegalHoldActivityRequest|ReleaseLegalHoldRequest $request): User
    {
        /** @var User $actor */
        $actor = $request->user();

        return $actor;
    }
}

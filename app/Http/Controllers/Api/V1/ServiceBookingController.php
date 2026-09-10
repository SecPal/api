<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexServiceBookingRequest;
use App\Http\Requests\Api\V1\RetireServiceBookingRequest;
use App\Http\Requests\Api\V1\ShowServiceBookingRequest;
use App\Http\Requests\Api\V1\StoreServiceBookingRequest;
use App\Http\Requests\Api\V1\UpdateServiceBookingRequest;
use App\Http\Resources\Api\V1\ServiceBookingResource;
use App\Models\User;
use App\Services\ServiceBookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

final class ServiceBookingController extends Controller
{
    public function __construct(private readonly ServiceBookingService $service) {}

    public function index(IndexServiceBookingRequest $request): AnonymousResourceCollection
    {
        return ServiceBookingResource::collection($this->service->list(
            $this->actor($request),
            $request->integer('page', 1),
            $request->integer('per_page', 15),
        ));
    }

    public function store(StoreServiceBookingRequest $request): JsonResponse
    {
        return response()->json([
            'data' => new ServiceBookingResource(
                $this->service->create($this->actor($request), $request->validated()),
            ),
        ], Response::HTTP_CREATED);
    }

    public function show(ShowServiceBookingRequest $request, string $serviceBooking): JsonResponse
    {
        return response()->json([
            'data' => new ServiceBookingResource(
                $this->service->inspect($this->actor($request), $serviceBooking),
            ),
        ]);
    }

    public function update(UpdateServiceBookingRequest $request, string $serviceBooking): JsonResponse
    {
        /** @var array<string, mixed> $attributes */
        $attributes = $request->safe()->only(UpdateServiceBookingRequest::UPDATE_FIELDS);

        return response()->json([
            'data' => new ServiceBookingResource(
                $this->service->update($this->actor($request), $serviceBooking, $attributes),
            ),
        ]);
    }

    public function retire(RetireServiceBookingRequest $request, string $serviceBooking): JsonResponse
    {
        return response()->json([
            'data' => new ServiceBookingResource(
                $this->service->retire($this->actor($request), $serviceBooking),
            ),
        ]);
    }

    private function actor(
        IndexServiceBookingRequest|StoreServiceBookingRequest|ShowServiceBookingRequest|UpdateServiceBookingRequest|RetireServiceBookingRequest $request,
    ): User {
        /** @var User $actor */
        $actor = $request->user();

        return $actor;
    }
}

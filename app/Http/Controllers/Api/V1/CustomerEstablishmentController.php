<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexCustomerEstablishmentRequest;
use App\Http\Requests\Api\V1\StoreCustomerEstablishmentRequest;
use App\Http\Requests\Api\V1\UpdateCustomerEstablishmentRequest;
use App\Http\Resources\Api\V1\CustomerEstablishmentResource;
use App\Models\CustomerEstablishment;
use App\Models\User;
use App\Services\CustomerEstablishmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class CustomerEstablishmentController extends Controller
{
    public function __construct(private readonly CustomerEstablishmentService $service) {}

    public function index(IndexCustomerEstablishmentRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CustomerEstablishment::class);
        /** @var User $user */
        $user = $request->user();
        $query = $this->service->visibleQuery($user, $request->integer('tenant_id'));

        foreach (['customer_id', 'establishment_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->string($filter)->toString());
            }
        }

        return CustomerEstablishmentResource::collection(
            $query->paginate($request->integer('per_page', 15))
        );
    }

    public function store(StoreCustomerEstablishmentRequest $request): JsonResponse
    {
        $this->authorize('create', CustomerEstablishment::class);
        /** @var User $user */
        $user = $request->user();
        $customerEstablishment = $this->service->create(
            $user,
            $request->integer('tenant_id'),
            $request->validated(),
        );

        return response()->json([
            'data' => new CustomerEstablishmentResource($customerEstablishment),
        ], Response::HTTP_CREATED);
    }

    public function show(CustomerEstablishment $customerEstablishment): JsonResponse
    {
        $this->authorize('view', $customerEstablishment);

        return response()->json(['data' => new CustomerEstablishmentResource($customerEstablishment)]);
    }

    public function update(
        UpdateCustomerEstablishmentRequest $request,
        CustomerEstablishment $customerEstablishment,
    ): JsonResponse {
        $this->authorize('update', $customerEstablishment);
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => new CustomerEstablishmentResource(
                $this->service->update(
                    $user,
                    $request->integer('tenant_id'),
                    $customerEstablishment,
                    $request->validated(),
                )
            ),
        ]);
    }

    public function destroy(CustomerEstablishment $customerEstablishment): Response|JsonResponse
    {
        $this->authorize('delete', $customerEstablishment);
        /** @var User $user */
        $user = request()->user();

        try {
            $this->service->delete(
                $user,
                request()->integer('tenant_id'),
                $customerEstablishment,
            );
        } catch (\Illuminate\Validation\ValidationException $exception) {
            return response()->json([
                'message' => __('A customer establishment used by sites cannot be deleted.'),
                'errors' => $exception->errors(),
            ], Response::HTTP_CONFLICT);
        }

        return response()->noContent();
    }
}

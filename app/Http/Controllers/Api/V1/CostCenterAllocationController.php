<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ReplaceCostCenterAllocationRequest;
use App\Http\Requests\Api\V1\ShowCostCenterAllocationRequest;
use App\Http\Resources\Api\V1\CostCenterAllocationResource;
use App\Models\User;
use App\Services\CostCenterAllocationService;
use Illuminate\Http\JsonResponse;

final class CostCenterAllocationController extends Controller
{
    public function __construct(private readonly CostCenterAllocationService $service) {}

    public function show(ShowCostCenterAllocationRequest $request, string $serviceBooking): JsonResponse
    {
        return response()->json([
            'data' => new CostCenterAllocationResource(
                $this->service->inspect($this->actor($request), $serviceBooking),
            ),
        ]);
    }

    public function update(ReplaceCostCenterAllocationRequest $request, string $serviceBooking): JsonResponse
    {
        /** @var list<array{internal_cost_center_id: string, share_bps: int}> $allocations */
        $allocations = $request->validated('allocations');

        return response()->json([
            'data' => new CostCenterAllocationResource(
                $this->service->replace($this->actor($request), $serviceBooking, $allocations),
            ),
        ]);
    }

    private function actor(ShowCostCenterAllocationRequest|ReplaceCostCenterAllocationRequest $request): User
    {
        /** @var User $actor */
        $actor = $request->user();

        return $actor;
    }
}

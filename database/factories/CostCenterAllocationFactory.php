<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CostCenterAllocation;
use App\Models\InternalCostCenter;
use App\Models\ServiceBooking;
use App\Models\TenantKey;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/** @extends Factory<CostCenterAllocation> */
class CostCenterAllocationFactory extends Factory
{
    protected $model = CostCenterAllocation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => TenantKey::factory(),
            'service_booking_id' => function (array $attributes): string {
                $tenantId = $this->tenantId($attributes);

                return ServiceBooking::factory()->forTenant($tenantId)->create()->id;
            },
            'internal_cost_center_id' => function (array $attributes): string {
                $tenantId = $this->tenantId($attributes);

                return InternalCostCenter::factory()->forTenant($tenantId)->create()->id;
            },
            'share_bps' => 10000,
        ];
    }

    public function forTenant(int|string $tenantId): static
    {
        return $this->state(fn (): array => ['tenant_id' => $tenantId]);
    }

    public function forServiceBooking(ServiceBooking $booking): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $booking->tenant_id,
            'service_booking_id' => $booking->id,
        ]);
    }

    public function forCostCenter(InternalCostCenter $costCenter): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $costCenter->tenant_id,
            'internal_cost_center_id' => $costCenter->id,
        ]);
    }

    public function complete(): static
    {
        return $this->state(fn (): array => ['share_bps' => 10000]);
    }

    /**
     * @param  list<int>  $shares
     * @return Collection<int, CostCenterAllocation>
     */
    public function createCompleteSplit(ServiceBooking $booking, array $shares = [10000]): Collection
    {
        if ($shares === [] || array_sum($shares) !== 10000) {
            throw new \InvalidArgumentException('A complete allocation split must total exactly 10000 basis points.');
        }

        foreach ($shares as $share) {
            if ($share < 1 || $share > 10000) {
                throw new \InvalidArgumentException('Every allocation share must be between 1 and 10000 basis points.');
            }
        }

        return DB::transaction(function () use ($booking, $shares): Collection {
            DB::statement('SET CONSTRAINTS cost_center_allocations_complete_split DEFERRED');
            $allocations = new Collection;

            foreach ($shares as $share) {
                $center = InternalCostCenter::factory()->forTenant($booking->tenant_id)->create();
                $allocation = CostCenterAllocation::factory()
                    ->forServiceBooking($booking)
                    ->forCostCenter($center)
                    ->create(['share_bps' => $share]);
                $allocations->push($allocation);
            }

            DB::statement('SET CONSTRAINTS cost_center_allocations_complete_split IMMEDIATE');

            return $allocations;
        });
    }

    /** @param array<mixed> $attributes */
    private function tenantId(array $attributes): int|string
    {
        $tenantId = $attributes['tenant_id'] ?? null;
        if (! is_int($tenantId) && ! is_string($tenantId)) {
            throw new \LogicException('Cost center allocation factories require a tenant identity.');
        }

        return $tenantId;
    }
}

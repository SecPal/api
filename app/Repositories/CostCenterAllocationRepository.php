<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Repositories;

use App\Models\CostCenterAllocation;
use App\Models\InternalCostCenter;
use App\Models\ServiceBooking;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class CostCenterAllocationRepository
{
    private const INACTIVE_MESSAGE = 'inactive cost centers cannot receive allocations';

    public function inspectBooking(int $tenantId, string $serviceBookingId): ServiceBooking
    {
        return ServiceBooking::query()
            ->forTenant($tenantId)
            ->whereKey($serviceBookingId)
            ->with(['costCenterAllocations' => static function (Relation $query): void {
                $query->getQuery()->orderBy('internal_cost_center_id');
            }])
            ->firstOrFail();
    }

    public function lockBooking(int $tenantId, string $serviceBookingId): ServiceBooking
    {
        return ServiceBooking::query()
            ->forTenant($tenantId)
            ->whereKey($serviceBookingId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /** @param list<string> $ids
     * @return Collection<int, InternalCostCenter>
     */
    public function lockTargets(int $tenantId, array $ids): Collection
    {
        if ($ids === []) {
            return new Collection;
        }

        return InternalCostCenter::query()
            ->forTenant($tenantId)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /** @param list<array{internal_cost_center_id: string, share_bps: int}> $allocations */
    public function replace(ServiceBooking $serviceBooking, array $allocations): void
    {
        DB::statement('SET CONSTRAINTS cost_center_allocations_complete_split DEFERRED');

        CostCenterAllocation::query()
            ->forTenant($serviceBooking->tenant_id)
            ->where('service_booking_id', $serviceBooking->id)
            ->delete();

        foreach ($allocations as $allocation) {
            CostCenterAllocation::query()->create([
                'tenant_id' => $serviceBooking->tenant_id,
                'service_booking_id' => $serviceBooking->id,
                'internal_cost_center_id' => $allocation['internal_cost_center_id'],
                'share_bps' => $allocation['share_bps'],
            ]);
        }

        DB::statement('SET CONSTRAINTS cost_center_allocations_complete_split IMMEDIATE');
        DB::statement('SET CONSTRAINTS cost_center_allocations_complete_split DEFERRED');
    }

    public function isInactiveTargetViolation(QueryException $exception): bool
    {
        $databaseMessage = $exception->errorInfo[2] ?? '';

        return ($exception->errorInfo[0] ?? $exception->getCode()) === '23514'
            && is_string($databaseMessage)
            && str_contains($databaseMessage, self::INACTIVE_MESSAGE);
    }

    public function isConcurrencyConflict(QueryException $exception): bool
    {
        return in_array($exception->errorInfo[0] ?? $exception->getCode(), ['40001', '40P01'], true);
    }
}

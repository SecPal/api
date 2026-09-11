<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services;

use App\Models\Activity;
use App\Models\CostCenterAllocation;
use App\Models\ServiceBooking;
use App\Models\User;
use RuntimeException;

class CostCenterAllocationAuditRecorder
{
    private const int SCHEMA_VERSION = 1;

    /** @return list<array{internal_cost_center_id: string, share_bps: int}> */
    public function snapshot(ServiceBooking $serviceBooking): array
    {
        return array_values($serviceBooking->costCenterAllocations()
            ->orderBy('internal_cost_center_id')
            ->get(['internal_cost_center_id', 'share_bps'])
            ->map(static fn (CostCenterAllocation $allocation): array => [
                'internal_cost_center_id' => (string) $allocation->internal_cost_center_id,
                'share_bps' => (int) $allocation->share_bps,
            ])->all());
    }

    /**
     * @param  list<array{internal_cost_center_id: string, share_bps: int}>  $previous
     * @param  list<array{internal_cost_center_id: string, share_bps: int}>  $current
     */
    public function recordReplace(
        User $actor,
        ServiceBooking $serviceBooking,
        array $previous,
        array $current,
    ): Activity {
        $audit = activity('cost_center_allocation_change')
            ->causedBy($actor)
            ->performedOn($serviceBooking)
            ->useLog('cost_center_allocation_change')
            ->event('cost_center_allocation.replace')
            ->withProperties([
                'schema_version' => self::SCHEMA_VERSION,
                'operation' => 'replace',
                'service_booking_id' => $serviceBooking->id,
                'previous_allocations' => $previous,
                'allocations' => $current,
            ])
            ->tap(function ($activity) use ($serviceBooking): void {
                /** @var Activity $activity */
                $activity->tenant_id = $serviceBooking->tenant_id;
                $activity->suppressRequestOrganizationalUnitCapture();
            })
            ->log('Service Booking cost center allocation replacement');

        if (! $audit instanceof Activity) {
            throw new RuntimeException('Required Cost Center allocation audit activity was not persisted.');
        }

        return $audit;
    }
}

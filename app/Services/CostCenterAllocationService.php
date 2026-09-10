<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services;

use App\Enums\InternalCostCenterStatus;
use App\Exceptions\CostCenterAllocationConflictException;
use App\Exceptions\CostCenterAllocationInactiveTargetException;
use App\Exceptions\CostCenterAllocationTargetNotFoundException;
use App\Models\Activity;
use App\Models\CostCenterAllocation;
use App\Models\ServiceBooking;
use App\Models\User;
use App\Repositories\CostCenterAllocationRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

final readonly class CostCenterAllocationService
{
    public function __construct(
        private CostCenterAllocationRepository $allocations,
        private PermissionRegistrar $permissions,
        private CostCenterAllocationAuditRecorder $audits,
    ) {}

    public function inspect(User $actor, string $serviceBookingId): ServiceBooking
    {
        try {
            return $this->allocations->inspectBooking(
                $this->authorizeActor($actor, 'viewAny'),
                $serviceBookingId,
            );
        } catch (ModelNotFoundException $exception) {
            throw new CostCenterAllocationTargetNotFoundException(previous: $exception);
        }
    }

    /** @param list<array{internal_cost_center_id: string, share_bps: int}> $desired */
    public function replace(User $actor, string $serviceBookingId, array $desired): ServiceBooking
    {
        $tenantId = $this->authorizeActor($actor, 'update');

        try {
            return DB::transaction(function () use ($actor, $tenantId, $serviceBookingId, $desired): ServiceBooking {
                Activity::acquireHashChainLock($tenantId);
                $serviceBooking = $this->allocations->lockBooking($tenantId, $serviceBookingId);
                $previous = $this->audits->snapshot($serviceBooking);
                $targetIds = array_values(array_unique(array_column($desired, 'internal_cost_center_id')));
                sort($targetIds, SORT_STRING);
                $targets = $this->allocations->lockTargets($tenantId, $targetIds);

                if ($targets->count() !== count($targetIds)) {
                    throw new CostCenterAllocationTargetNotFoundException;
                }
                if ($targets->contains(fn ($target): bool => $target->status !== InternalCostCenterStatus::Active)) {
                    throw new CostCenterAllocationInactiveTargetException;
                }

                $this->allocations->replace($serviceBooking, $desired);
                $current = $this->audits->snapshot($serviceBooking);
                $this->audits->recordReplace($actor, $serviceBooking, $previous, $current);

                return $this->allocations->inspectBooking($tenantId, $serviceBookingId);
            });
        } catch (ModelNotFoundException $exception) {
            throw new CostCenterAllocationTargetNotFoundException(previous: $exception);
        } catch (QueryException $exception) {
            if ($this->allocations->isInactiveTargetViolation($exception)) {
                throw new CostCenterAllocationInactiveTargetException(previous: $exception);
            }
            if ($this->allocations->isConcurrencyConflict($exception)) {
                throw new CostCenterAllocationConflictException(previous: $exception);
            }

            throw $exception;
        }
    }

    private function authorizeActor(User $actor, string $ability): int
    {
        Gate::forUser($actor)->authorize($ability, CostCenterAllocation::class);
        $tenantId = $this->permissions->getPermissionsTeamId();
        if (! is_int($tenantId) || $actor->tenant_id === null || $actor->tenant_id !== $tenantId) {
            throw new AuthorizationException;
        }

        return $tenantId;
    }
}

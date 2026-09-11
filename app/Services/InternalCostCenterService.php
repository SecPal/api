<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services;

use App\Enums\InternalCostCenterStatus;
use App\Exceptions\InternalCostCenterConflictException;
use App\Exceptions\InternalCostCenterTargetNotFoundException;
use App\Models\Activity;
use App\Models\InternalCostCenter;
use App\Models\User;
use App\Repositories\InternalCostCenterRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

final readonly class InternalCostCenterService
{
    public function __construct(
        private InternalCostCenterRepository $internalCostCenters,
        private PermissionRegistrar $permissions,
        private InternalCostCenterAuditRecorder $audits,
    ) {}

    /** @return LengthAwarePaginator<int, InternalCostCenter> */
    public function list(User $actor, int $page = 1, int $perPage = 15): LengthAwarePaginator
    {
        return $this->internalCostCenters->paginate($this->authorizeActor($actor, 'viewAny'), $page, $perPage);
    }

    public function inspect(User $actor, string $internalCostCenterId): InternalCostCenter
    {
        try {
            $internalCostCenter = $this->internalCostCenters->inspect(
                $this->authorizeActor($actor, 'viewAny'),
                $internalCostCenterId,
            );
        } catch (ModelNotFoundException $exception) {
            throw new InternalCostCenterTargetNotFoundException(previous: $exception);
        }

        Gate::forUser($actor)->authorize('view', $internalCostCenter);

        return $internalCostCenter;
    }

    /** @param array<string, mixed> $attributes */
    public function create(User $actor, array $attributes): InternalCostCenter
    {
        $this->requireAttributes($attributes, false);
        $tenantId = $this->authorizeActor($actor, 'create');

        try {
            return DB::transaction(function () use ($actor, $tenantId, $attributes): InternalCostCenter {
                Activity::acquireHashChainLock($tenantId);
                $internalCostCenter = $this->internalCostCenters->create([
                    'tenant_id' => $tenantId,
                    'code' => $attributes['code'],
                    'name' => $attributes['name'],
                    'status' => InternalCostCenterStatus::Active,
                    'inactive_at' => null,
                ]);
                $this->audits->recordCreate($actor, $internalCostCenter);

                return $internalCostCenter;
            });
        } catch (QueryException $exception) {
            if (! $this->internalCostCenters->isDuplicateCode($exception)) {
                throw $exception;
            }

            throw new InternalCostCenterConflictException(
                'An Internal Cost Center with this code already exists.',
                previous: $exception,
            );
        }
    }

    /** @param array<string, mixed> $attributes */
    public function update(User $actor, string $internalCostCenterId, array $attributes): InternalCostCenter
    {
        $this->requireAttributes($attributes, true);
        $tenantId = $this->authorizeActor($actor, 'update');

        try {
            return DB::transaction(function () use ($actor, $tenantId, $internalCostCenterId, $attributes): InternalCostCenter {
                Activity::acquireHashChainLock($tenantId);
                $internalCostCenter = $this->internalCostCenters->lock($tenantId, $internalCostCenterId);
                Gate::forUser($actor)->authorize('update', $internalCostCenter);
                $this->requireActive($internalCostCenter);
                $previous = $this->audits->snapshot($internalCostCenter);
                $internalCostCenter = $this->internalCostCenters->update($internalCostCenter, $attributes);
                $this->audits->recordUpdate($actor, $internalCostCenter, $previous);

                return $internalCostCenter;
            });
        } catch (ModelNotFoundException $exception) {
            throw new InternalCostCenterTargetNotFoundException(previous: $exception);
        }
    }

    public function deactivate(User $actor, string $internalCostCenterId): InternalCostCenter
    {
        $tenantId = $this->authorizeActor($actor, 'deactivate');

        try {
            return DB::transaction(function () use ($actor, $tenantId, $internalCostCenterId): InternalCostCenter {
                Activity::acquireHashChainLock($tenantId);
                $internalCostCenter = $this->internalCostCenters->lock($tenantId, $internalCostCenterId);
                Gate::forUser($actor)->authorize('deactivate', $internalCostCenter);
                $this->requireActive($internalCostCenter);
                $previous = $this->audits->snapshot($internalCostCenter);
                $internalCostCenter = $this->internalCostCenters->update($internalCostCenter, [
                    'status' => InternalCostCenterStatus::Inactive,
                    'inactive_at' => now(),
                ]);
                $this->audits->recordDeactivate($actor, $internalCostCenter, $previous);

                return $internalCostCenter;
            });
        } catch (ModelNotFoundException $exception) {
            throw new InternalCostCenterTargetNotFoundException(previous: $exception);
        }
    }

    private function requireActive(InternalCostCenter $internalCostCenter): void
    {
        if ($internalCostCenter->status !== InternalCostCenterStatus::Active) {
            throw new InternalCostCenterConflictException('The Internal Cost Center is inactive.');
        }
    }

    private function authorizeActor(User $actor, string $ability): int
    {
        Gate::forUser($actor)->authorize($ability, InternalCostCenter::class);
        $tenantId = $this->permissions->getPermissionsTeamId();
        if (! is_int($tenantId) || $actor->tenant_id === null || $actor->tenant_id !== $tenantId) {
            throw new AuthorizationException;
        }

        return $tenantId;
    }

    /** @param array<string, mixed> $attributes */
    private function requireAttributes(array $attributes, bool $partial): void
    {
        $allowed = $partial ? InternalCostCenter::MUTABLE_BUSINESS_FIELDS : InternalCostCenter::CREATE_FIELDS;
        if (array_diff(array_keys($attributes), $allowed) !== []) {
            throw new \InvalidArgumentException('Internal Cost Center attributes contain a server-owned or unknown field.');
        }
        if (array_diff($allowed, array_keys($attributes)) !== []) {
            throw new \InvalidArgumentException('Internal Cost Center attributes are incomplete.');
        }
    }
}

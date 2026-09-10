<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services;

use App\Enums\InvoiceState;
use App\Enums\ServiceBookingStatus;
use App\Exceptions\ServiceBookingConcurrentTransitionException;
use App\Exceptions\ServiceBookingInvoicedException;
use App\Exceptions\ServiceBookingRetiredException;
use App\Exceptions\ServiceBookingTargetNotFoundException;
use App\Models\Activity;
use App\Models\ServiceBooking;
use App\Models\User;
use App\Repositories\ServiceBookingRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

final readonly class ServiceBookingService
{
    public function __construct(
        private ServiceBookingRepository $serviceBookings,
        private PermissionRegistrar $permissions,
        private ServiceBookingAuditRecorder $audits,
    ) {}

    /** @return LengthAwarePaginator<int, ServiceBooking> */
    public function list(User $actor, int $page = 1, int $perPage = 15): LengthAwarePaginator
    {
        return $this->serviceBookings->paginate(
            $this->authorizeActor($actor, 'viewAny'),
            $page,
            $perPage,
        );
    }

    public function inspect(User $actor, string $serviceBookingId): ServiceBooking
    {
        try {
            $serviceBooking = $this->serviceBookings->inspect(
                $this->authorizeActor($actor, 'viewAny'),
                $serviceBookingId,
            );
        } catch (ModelNotFoundException $exception) {
            throw new ServiceBookingTargetNotFoundException(previous: $exception);
        }

        Gate::forUser($actor)->authorize('view', $serviceBooking);

        return $serviceBooking;
    }

    /** @param array<string, mixed> $attributes */
    public function create(User $actor, array $attributes): ServiceBooking
    {
        $this->requireBusinessAttributes($attributes, false);
        $tenantId = $this->authorizeActor($actor, 'create');

        try {
            return DB::transaction(function () use ($actor, $tenantId, $attributes): ServiceBooking {
                Activity::acquireHashChainLock($tenantId);
                $contract = $this->serviceBookings->lockContract(
                    $tenantId,
                    $this->requiredString($attributes, 'contract_id'),
                );

                $serviceBooking = $this->serviceBookings->create(array_merge($attributes, [
                    'tenant_id' => $tenantId,
                    'currency_code' => $contract->currency_code,
                    'invoice_state' => InvoiceState::Unbilled,
                    'invoiced_at' => null,
                    'status' => ServiceBookingStatus::Active,
                    'retired_at' => null,
                ]));
                $this->audits->recordCreate($actor, $serviceBooking);

                return $serviceBooking;
            });
        } catch (ModelNotFoundException $exception) {
            throw new ServiceBookingTargetNotFoundException(previous: $exception);
        }
    }

    /** @param array<string, mixed> $attributes */
    public function update(User $actor, string $serviceBookingId, array $attributes): ServiceBooking
    {
        $this->requireBusinessAttributes($attributes, true);
        $tenantId = $this->authorizeActor($actor, 'update');

        try {
            return DB::transaction(function () use ($actor, $tenantId, $serviceBookingId, $attributes): ServiceBooking {
                Activity::acquireHashChainLock($tenantId);
                $serviceBooking = $this->serviceBookings->lock($tenantId, $serviceBookingId);
                Gate::forUser($actor)->authorize('update', $serviceBooking);
                $this->requireMutable($serviceBooking);
                $previous = $this->audits->snapshot($serviceBooking);

                try {
                    $serviceBooking = $this->serviceBookings->update($serviceBooking, $attributes);
                } catch (QueryException $exception) {
                    if (! $this->serviceBookings->isInvoicedHistoryGuardViolation($exception)) {
                        throw $exception;
                    }

                    throw new ServiceBookingConcurrentTransitionException(previous: $exception);
                }

                $this->audits->recordUpdate($actor, $serviceBooking, $previous);

                return $serviceBooking;
            });
        } catch (ModelNotFoundException $exception) {
            throw new ServiceBookingTargetNotFoundException(previous: $exception);
        }
    }

    public function retire(User $actor, string $serviceBookingId): ServiceBooking
    {
        $tenantId = $this->authorizeActor($actor, 'retire');

        try {
            return DB::transaction(function () use ($actor, $tenantId, $serviceBookingId): ServiceBooking {
                Activity::acquireHashChainLock($tenantId);
                $serviceBooking = $this->serviceBookings->lock($tenantId, $serviceBookingId);
                Gate::forUser($actor)->authorize('retire', $serviceBooking);
                $this->requireActive($serviceBooking);
                $previous = $this->audits->snapshot($serviceBooking);

                $serviceBooking = $this->serviceBookings->update($serviceBooking, [
                    'status' => ServiceBookingStatus::Retired,
                    'retired_at' => now(),
                ]);
                $this->audits->recordRetire($actor, $serviceBooking, $previous);

                return $serviceBooking;
            });
        } catch (ModelNotFoundException $exception) {
            throw new ServiceBookingTargetNotFoundException(previous: $exception);
        }
    }

    /** @throws AuthorizationException */
    private function authorizeActor(User $actor, string $ability): int
    {
        Gate::forUser($actor)->authorize($ability, ServiceBooking::class);
        $tenantId = $this->permissions->getPermissionsTeamId();

        if (! is_int($tenantId)
            || $actor->tenant_id === null
            || $actor->tenant_id !== $tenantId) {
            throw new AuthorizationException;
        }

        return $tenantId;
    }

    private function requireMutable(ServiceBooking $serviceBooking): void
    {
        $this->requireActive($serviceBooking);

        if ($serviceBooking->invoice_state !== InvoiceState::Unbilled) {
            throw new ServiceBookingInvoicedException;
        }
    }

    private function requireActive(ServiceBooking $serviceBooking): void
    {
        if ($serviceBooking->status !== ServiceBookingStatus::Active) {
            throw new ServiceBookingRetiredException;
        }
    }

    /** @param array<string, mixed> $attributes */
    private function requiredString(array $attributes, string $key): string
    {
        $value = $attributes[$key] ?? null;

        if (! is_string($value)) {
            throw new \InvalidArgumentException($key.' must be a string.');
        }

        return $value;
    }

    /** @param array<string, mixed> $attributes */
    private function requireBusinessAttributes(array $attributes, bool $partial): void
    {
        $keys = array_keys($attributes);
        $allowed = $partial
            ? ServiceBooking::MUTABLE_BUSINESS_FIELDS
            : ServiceBooking::CREATE_FIELDS;

        if (array_diff($keys, $allowed) !== []) {
            throw new \InvalidArgumentException('Service Booking attributes contain a server-owned or unknown field.');
        }

        if ($partial) {
            if ($keys === []) {
                throw new \InvalidArgumentException('At least one Service Booking property is required.');
            }

            return;
        }

        if (array_diff(ServiceBooking::CREATE_FIELDS, $keys) !== []) {
            throw new \InvalidArgumentException('Service Booking create attributes are incomplete.');
        }
    }
}

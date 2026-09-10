<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services;

use App\Enums\ContractStatus;
use App\Exceptions\ContractCurrencyHistoryConflictException;
use App\Exceptions\ContractCustomerHistoryConflictException;
use App\Exceptions\ContractRetiredException;
use App\Exceptions\ContractTargetNotFoundException;
use App\Models\Activity;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\User;
use App\Repositories\ContractRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

final readonly class ContractService
{
    public function __construct(
        private ContractRepository $contracts,
        private PermissionRegistrar $permissions,
        private ContractAuditRecorder $audits,
    ) {}

    /** @return LengthAwarePaginator<int, Contract> */
    public function list(User $actor, int $page = 1, int $perPage = 15): LengthAwarePaginator
    {
        return $this->contracts->paginate(
            $this->authorizeActor($actor, 'viewAny'),
            $page,
            $perPage,
        );
    }

    public function inspect(User $actor, string $contractId): Contract
    {
        try {
            $contract = $this->contracts->inspect(
                $this->authorizeActor($actor, 'viewAny'),
                $contractId,
            );
        } catch (ModelNotFoundException $exception) {
            throw new ContractTargetNotFoundException(previous: $exception);
        }

        Gate::forUser($actor)->authorize('view', $contract);

        return $contract;
    }

    /** @param array<string, mixed> $attributes */
    public function create(User $actor, array $attributes): Contract
    {
        $this->requireBusinessAttributes($attributes, false);
        $tenantId = $this->authorizeActor($actor, 'create');

        return DB::transaction(function () use ($actor, $tenantId, $attributes): Contract {
            Activity::acquireHashChainLock($tenantId);
            $customerId = $this->requiredString($attributes, 'customer_id');
            $this->visibleCustomer($actor, $tenantId, $customerId);

            $contract = $this->contracts->create(array_merge($attributes, [
                'tenant_id' => $tenantId,
                'status' => ContractStatus::Active,
                'retired_at' => null,
                'ends_on' => $attributes['ends_on'] ?? null,
            ]));
            $this->audits->recordCreate($actor, $contract);

            return $contract;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(User $actor, string $contractId, array $attributes): Contract
    {
        $this->requireBusinessAttributes($attributes, true);
        $tenantId = $this->authorizeActor($actor, 'update');

        try {
            return DB::transaction(function () use ($actor, $tenantId, $contractId, $attributes): Contract {
                Activity::acquireHashChainLock($tenantId);
                $contract = $this->contracts->lock($tenantId, $contractId);
                Gate::forUser($actor)->authorize('update', $contract);
                $this->requireActive($contract);
                $this->validateResultingDates($contract, $attributes);
                $previous = $this->audits->snapshot($contract);

                $customerChanges = array_key_exists('customer_id', $attributes)
                    && $attributes['customer_id'] !== $contract->customer_id;
                $currencyChanges = array_key_exists('currency_code', $attributes)
                    && $attributes['currency_code'] !== $contract->currency_code;

                if ($customerChanges) {
                    $this->visibleCustomer(
                        $actor,
                        $tenantId,
                        $this->requiredString($attributes, 'customer_id'),
                    );
                }

                if (($customerChanges || $currencyChanges) && $this->contracts->hasBookingHistory($contract)) {
                    throw $customerChanges
                        ? new ContractCustomerHistoryConflictException
                        : new ContractCurrencyHistoryConflictException;
                }

                try {
                    $contract = $this->contracts->update($contract, $attributes);
                } catch (QueryException $exception) {
                    if (! $this->contracts->isHistoryGuardViolation($exception)) {
                        throw $exception;
                    }

                    throw $customerChanges
                        ? new ContractCustomerHistoryConflictException(previous: $exception)
                        : new ContractCurrencyHistoryConflictException(previous: $exception);
                }

                $this->audits->recordUpdate($actor, $contract, $previous);

                return $contract;
            });
        } catch (ModelNotFoundException $exception) {
            throw new ContractTargetNotFoundException(previous: $exception);
        }
    }

    public function retire(User $actor, string $contractId): Contract
    {
        $tenantId = $this->authorizeActor($actor, 'retire');

        try {
            return DB::transaction(function () use ($actor, $tenantId, $contractId): Contract {
                Activity::acquireHashChainLock($tenantId);
                $contract = $this->contracts->lock($tenantId, $contractId);
                Gate::forUser($actor)->authorize('retire', $contract);
                $this->requireActive($contract);
                $previous = $this->audits->snapshot($contract);

                $contract = $this->contracts->update($contract, [
                    'status' => ContractStatus::Retired,
                    'retired_at' => now(),
                ]);
                $this->audits->recordRetire($actor, $contract, $previous);

                return $contract;
            });
        } catch (ModelNotFoundException $exception) {
            throw new ContractTargetNotFoundException(previous: $exception);
        }
    }

    /** @throws AuthorizationException */
    private function authorizeActor(User $actor, string $ability): int
    {
        Gate::forUser($actor)->authorize($ability, Contract::class);
        $tenantId = $this->permissions->getPermissionsTeamId();

        if (! is_int($tenantId)
            || $actor->tenant_id === null
            || $actor->tenant_id !== $tenantId) {
            throw new AuthorizationException;
        }

        return $tenantId;
    }

    private function visibleCustomer(User $actor, int $tenantId, string $customerId): Customer
    {
        try {
            $customer = $this->contracts->lockCustomer($tenantId, $customerId);
            Gate::forUser($actor)->authorize('view', $customer);

            return $customer;
        } catch (ModelNotFoundException|AuthorizationException $exception) {
            throw new ContractTargetNotFoundException(previous: $exception);
        }
    }

    private function requireActive(Contract $contract): void
    {
        if ($contract->status !== ContractStatus::Active) {
            throw new ContractRetiredException;
        }
    }

    /** @param array<string, mixed> $attributes */
    private function validateResultingDates(Contract $contract, array $attributes): void
    {
        $startsOn = array_key_exists('starts_on', $attributes)
            ? $attributes['starts_on']
            : $contract->starts_on->format('Y-m-d');
        $endsOn = array_key_exists('ends_on', $attributes)
            ? $attributes['ends_on']
            : $contract->ends_on?->format('Y-m-d');

        if (is_string($startsOn) && is_string($endsOn) && $endsOn < $startsOn) {
            throw ValidationException::withMessages([
                'ends_on' => ['The resulting end date must be on or after the start date.'],
            ]);
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
        $unknown = array_diff($keys, Contract::MUTABLE_BUSINESS_FIELDS);

        if ($unknown !== []) {
            throw new \InvalidArgumentException('Contract attributes contain a server-owned or unknown field.');
        }

        if ($partial) {
            if ($keys === []) {
                throw new \InvalidArgumentException('At least one Contract property is required.');
            }

            return;
        }

        $required = array_diff(Contract::MUTABLE_BUSINESS_FIELDS, ['ends_on']);
        if (array_diff($required, $keys) !== []) {
            throw new \InvalidArgumentException('Contract create attributes are incomplete.');
        }
    }
}

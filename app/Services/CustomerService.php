<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DuplicateResourceException;
use App\Models\Customer;
use App\Models\LegalEntity;
use App\Models\User;
use App\Repositories\CustomerRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CustomerService
{
    public function __construct(
        private readonly CustomerRepository $customers,
    ) {}

    /**
     * @return Builder<Customer>
     */
    public function visibleQuery(User $user, int $tenantId): Builder
    {
        return $this->customers->visibleQuery($user, $tenantId);
    }

    /**
     * @return Collection<int, LegalEntity>
     */
    public function writableLegalEntities(User $user, int $tenantId): Collection
    {
        if ($user->organizationalScopes()->exists()) {
            /** @var Collection<int, LegalEntity> $empty */
            $empty = new Collection;

            return $empty;
        }

        return LegalEntity::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->get();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $user, int $tenantId, array $attributes): Customer
    {
        try {
            return DB::transaction(function () use ($user, $tenantId, $attributes): Customer {
                $this->customers->lockTenant($tenantId);
                $this->lockWritableLegalEntity($user, $tenantId, $this->legalEntityId($attributes));

                $attributes['tenant_id'] = $tenantId;

                if (! isset($attributes['customer_number'])) {
                    $attributes['customer_number'] = $this->customers->nextCustomerNumber($tenantId);
                }

                if (! isset($attributes['is_active'])) {
                    $attributes['is_active'] = true;
                }

                return $this->customers->create($attributes);
            });
        } catch (QueryException $exception) {
            throw DuplicateResourceException::fromQueryException($exception) ?? $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $user, int $tenantId, Customer $customer, array $attributes): Customer
    {
        try {
            return DB::transaction(function () use ($user, $tenantId, $customer, $attributes): Customer {
                if (isset($attributes['legal_entity_id'])
                    && $attributes['legal_entity_id'] !== $customer->legal_entity_id) {
                    $this->lockWritableLegalEntity($user, $tenantId, $this->legalEntityId($attributes));
                }

                return $this->customers->update($customer, $attributes);
            });
        } catch (QueryException $exception) {
            throw DuplicateResourceException::fromQueryException($exception) ?? $exception;
        }
    }

    private function lockWritableLegalEntity(User $user, int $tenantId, string $legalEntityId): LegalEntity
    {
        $legalEntity = LegalEntity::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereKey($legalEntityId)
            ->lockForUpdate()
            ->first();

        if ($legalEntity === null) {
            throw ValidationException::withMessages([
                'legal_entity_id' => [__('The selected legal entity is invalid.')],
            ]);
        }

        if ($user->organizationalScopes()->exists()) {
            throw new AuthorizationException(
                __('No organizational entitlement exists for the selected legal entity.')
            );
        }

        return $legalEntity;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function legalEntityId(array $attributes): string
    {
        $legalEntityId = $attributes['legal_entity_id'] ?? null;

        if (! is_string($legalEntityId)) {
            throw new \InvalidArgumentException('legal_entity_id must be a string.');
        }

        return $legalEntityId;
    }
}

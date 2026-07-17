<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DuplicateResourceException;
use App\Models\Customer;
use App\Models\User;
use App\Repositories\CustomerRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CustomerService
{
    public function __construct(
        private readonly CustomerRepository $customers,
        private readonly DomainAccessService $domainAccess,
    ) {}

    /**
     * @return Builder<Customer>
     */
    public function visibleQuery(User $user, int $tenantId): Builder
    {
        return $this->domainAccess->visibleCustomersQuery($user, $tenantId)
            ->with([
                'assignments.user',
                'customerEstablishments' => $this->visibleCustomerEstablishmentsConstraint($user, $tenantId),
            ]);
    }

    public function loadVisibleCustomerEstablishments(
        User $user,
        int $tenantId,
        Customer $customer,
    ): Customer {
        $customer->load([
            'customerEstablishments' => $this->visibleCustomerEstablishmentsConstraint($user, $tenantId),
        ]);

        return $customer;
    }

    /** @return \Closure(Relation<*, *, *>): void */
    private function visibleCustomerEstablishmentsConstraint(User $user, int $tenantId): \Closure
    {
        $visibleCustomerEstablishmentIds = $this->domainAccess
            ->visibleCustomerEstablishmentsQuery($user, $tenantId)
            ->select('customer_establishments.id');

        return static function (Relation $relation) use ($visibleCustomerEstablishmentIds): void {
            $relation->getQuery()->whereIn(
                'customer_establishments.id',
                $visibleCustomerEstablishmentIds,
            );
        };
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $user, int $tenantId, array $attributes): Customer
    {
        try {
            return DB::transaction(function () use ($user, $tenantId, $attributes): Customer {
                $this->customers->lockTenant($tenantId);
                $this->domainAccess->ensureCustomerCreatable(
                    $user,
                    $tenantId,
                    $this->legalEntityId($attributes),
                );

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
                $this->domainAccess->ensureCustomerWritable($user, $tenantId, $customer);

                if (isset($attributes['legal_entity_id'])
                    && $attributes['legal_entity_id'] !== $customer->legal_entity_id) {
                    $this->domainAccess->ensureCustomerLegalEntityWritable(
                        $user,
                        $tenantId,
                        $customer,
                        $this->legalEntityId($attributes),
                    );

                    if ($this->customers->hasEstablishmentLinks($customer)) {
                        throw ValidationException::withMessages([
                            'legal_entity_id' => [__('A customer with establishment links cannot change legal entity.')],
                        ]);
                    }
                }

                return $this->customers->update($customer, $attributes);
            });
        } catch (QueryException $exception) {
            throw DuplicateResourceException::fromQueryException($exception) ?? $exception;
        }
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

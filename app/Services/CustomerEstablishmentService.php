<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DuplicateResourceException;
use App\Models\CustomerEstablishment;
use App\Models\User;
use App\Repositories\CustomerEstablishmentRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CustomerEstablishmentService
{
    public function __construct(
        private readonly CustomerEstablishmentRepository $customerEstablishments,
        private readonly DomainAccessService $domainAccess,
    ) {}

    /** @return Builder<CustomerEstablishment> */
    public function visibleQuery(User $user, int $tenantId): Builder
    {
        return $this->domainAccess->visibleCustomerEstablishmentsQuery($user, $tenantId);
    }

    /** @param array<string, mixed> $attributes */
    public function create(User $user, int $tenantId, array $attributes): CustomerEstablishment
    {
        try {
            return DB::transaction(function () use ($user, $tenantId, $attributes): CustomerEstablishment {
                $customer = $this->customerEstablishments->lockCustomer(
                    $tenantId,
                    $this->stringAttribute($attributes, 'customer_id'),
                );
                $establishment = $this->customerEstablishments->lockEstablishment(
                    $tenantId,
                    $this->stringAttribute($attributes, 'establishment_id'),
                );

                $this->domainAccess->ensureCustomerEstablishmentWritable(
                    $user,
                    $tenantId,
                    $customer,
                    $establishment,
                );

                $existing = $this->customerEstablishments->lockIncludingTrashed(
                    $tenantId,
                    $customer->id,
                    $establishment->id,
                );

                if ($existing instanceof CustomerEstablishment) {
                    if (! $existing->trashed()) {
                        throw new DuplicateResourceException('A matching record already exists.');
                    }

                    return $this->customerEstablishments->restore(
                        $existing,
                        $this->plainContactAttributes($attributes, clearMissing: true),
                    );
                }

                return $this->customerEstablishments->create([
                    'tenant_id' => $tenantId,
                    'legal_entity_id' => $customer->legal_entity_id,
                    'customer_id' => $customer->id,
                    'establishment_id' => $establishment->id,
                    ...$this->plainContactAttributes($attributes),
                ]);
            });
        } catch (QueryException $exception) {
            throw DuplicateResourceException::fromQueryException($exception) ?? $exception;
        }
    }

    /** @param array<string, mixed> $attributes */
    public function update(
        User $user,
        int $tenantId,
        CustomerEstablishment $customerEstablishment,
        array $attributes,
    ): CustomerEstablishment {
        $this->domainAccess->ensureCustomerEstablishmentWritableRecord(
            $user,
            $tenantId,
            $customerEstablishment,
        );

        return $this->customerEstablishments->update(
            $customerEstablishment,
            $this->plainContactAttributes($attributes),
        );
    }

    public function delete(
        User $user,
        int $tenantId,
        CustomerEstablishment $customerEstablishment,
    ): void {
        $this->domainAccess->ensureCustomerEstablishmentWritableRecord(
            $user,
            $tenantId,
            $customerEstablishment,
        );

        if ($this->customerEstablishments->hasSites($customerEstablishment)) {
            throw ValidationException::withMessages([
                'customer_establishment' => [__('A customer establishment used by sites cannot be deleted.')],
            ]);
        }

        $this->customerEstablishments->delete($customerEstablishment);
    }

    /** @param array<string, mixed> $attributes */
    private function stringAttribute(array $attributes, string $key): string
    {
        $value = $attributes[$key] ?? null;

        if (! is_string($value)) {
            throw new \InvalidArgumentException("{$key} must be a string.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function plainContactAttributes(array $attributes, bool $clearMissing = false): array
    {
        $plainAttributes = [];

        foreach (['contact_name', 'phone', 'email', 'comments'] as $attribute) {
            if ($clearMissing || array_key_exists($attribute, $attributes)) {
                $plainAttributes["{$attribute}_plain"] = $attributes[$attribute] ?? null;
            }
        }

        return $plainAttributes;
    }
}

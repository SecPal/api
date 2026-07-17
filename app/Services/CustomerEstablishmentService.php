<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

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
    public function __construct(private readonly CustomerEstablishmentRepository $customerEstablishments) {}

    /** @return Builder<CustomerEstablishment> */
    public function visibleQuery(User $user, int $tenantId): Builder
    {
        return $this->customerEstablishments->visibleQuery($user, $tenantId);
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

                if (! $user->can('update', $customer)
                    || $customer->legal_entity_id !== $establishment->legal_entity_id) {
                    throw ValidationException::withMessages([
                        'establishment_id' => [__('The selected establishment is invalid.')],
                    ]);
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
    public function update(CustomerEstablishment $customerEstablishment, array $attributes): CustomerEstablishment
    {
        return $this->customerEstablishments->update(
            $customerEstablishment,
            $this->plainContactAttributes($attributes),
        );
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
    private function plainContactAttributes(array $attributes): array
    {
        $plainAttributes = [];

        foreach (['contact_name', 'phone', 'email', 'comments'] as $attribute) {
            if (array_key_exists($attribute, $attributes)) {
                $plainAttributes["{$attribute}_plain"] = $attributes[$attribute];
            }
        }

        return $plainAttributes;
    }
}

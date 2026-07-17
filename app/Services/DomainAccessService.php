<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerEstablishment;
use App\Models\Employee;
use App\Models\Establishment;
use App\Models\LegalEntity;
use App\Models\Site;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Validation\ValidationException;

/**
 * Internal domain access boundary built on the existing tenant and RBAC state.
 */
final class DomainAccessService
{
    /** @return Builder<Employee> */
    public function visibleEmployeesQuery(User $user, int $tenantId): Builder
    {
        $this->ensureTenant($user, $tenantId);

        return Employee::query()
            ->where('tenant_id', $tenantId)
            ->whereIn(
                'establishment_id',
                $this->visibleEmployeeEstablishmentsQuery($user, $tenantId)->select('establishments.id'),
            );
    }

    public function employeeDomainIsAccessible(
        User $user,
        int $tenantId,
        string $legalEntityId,
        string $establishmentId,
    ): bool {
        $this->ensureTenant($user, $tenantId);

        return $this->visibleEmployeeEstablishmentsQuery($user, $tenantId)
            ->whereKey($establishmentId)
            ->where('legal_entity_id', $legalEntityId)
            ->exists();
    }

    public function ensureEmployeeDomainWritable(
        User $user,
        int $tenantId,
        string $legalEntityId,
        string $establishmentId,
    ): void {
        $this->ensureTenant($user, $tenantId);

        $isWritable = $this->visibleEmployeeEstablishmentsQuery($user, $tenantId)
            ->whereNull('establishments.deleted_at')
            ->where('establishments.is_active', true)
            ->whereHas('legalEntity', fn (Builder $query): Builder => $query->where('is_active', true))
            ->whereKey($establishmentId)
            ->where('legal_entity_id', $legalEntityId)
            ->exists();

        if (! $isWritable) {
            throw ValidationException::withMessages([
                'establishment_id' => [__('The selected establishment is invalid.')],
            ]);
        }
    }

    /** @return Builder<Customer> */
    public function visibleCustomersQuery(User $user, int $tenantId): Builder
    {
        $this->ensureTenant($user, $tenantId);

        if ($user->can('customers.read') && ! $user->organizationalScopes()->exists()) {
            return Customer::query()->where('tenant_id', $tenantId);
        }

        return $user->accessibleCustomersQuery()->where('customers.tenant_id', $tenantId);
    }

    /** @return Builder<CustomerEstablishment> */
    public function visibleCustomerEstablishmentsQuery(User $user, int $tenantId): Builder
    {
        $this->ensureTenant($user, $tenantId);
        $query = CustomerEstablishment::query()->where('tenant_id', $tenantId);

        if ($user->can('customers.read') && ! $user->organizationalScopes()->exists()) {
            return $query;
        }

        $assignedCustomerIds = $user->customerAssignments()
            ->where('tenant_id', $tenantId)
            ->currentlyActive()
            ->pluck('customer_id');
        $assignedSiteIds = $user->siteAssignments()
            ->where('tenant_id', $tenantId)
            ->currentlyActive()
            ->pluck('site_id');

        return $query->where(function (Builder $query) use ($assignedCustomerIds, $assignedSiteIds, $tenantId): void {
            $query->whereIn('customer_id', $assignedCustomerIds)
                ->orWhereExists(function (QueryBuilder $query) use ($assignedSiteIds, $tenantId): void {
                    $query->selectRaw('1')
                        ->from((new Site)->getTable())
                        ->where('sites.tenant_id', $tenantId)
                        ->whereColumn('sites.customer_id', 'customer_establishments.customer_id')
                        ->whereColumn('sites.establishment_id', 'customer_establishments.establishment_id')
                        ->whereIn('sites.id', $assignedSiteIds);
                });
        });
    }

    /** @return Collection<int, LegalEntity> */
    public function writableLegalEntities(User $user, int $tenantId): Collection
    {
        $this->ensureCanCreateCustomers($user, $tenantId);

        return $this->writableLegalEntitiesQuery($tenantId)->orderBy('name')->get();
    }

    /** @return Collection<int, Establishment> */
    public function writableEstablishments(
        User $user,
        int $tenantId,
        string $legalEntityId,
    ): Collection {
        $this->ensureCanCreateCustomers($user, $tenantId);
        $this->findWritableLegalEntity($tenantId, $legalEntityId);

        return Establishment::query()
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $legalEntityId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    /** @return Collection<int, Customer> */
    public function visibleCustomersForEstablishment(
        User $user,
        int $tenantId,
        string $establishmentId,
    ): Collection {
        $this->ensureCanCreateCustomers($user, $tenantId);
        $establishment = Establishment::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereKey($establishmentId)
            ->firstOrFail();
        $this->findWritableLegalEntity($tenantId, $establishment->legal_entity_id);

        return $this->visibleCustomersQuery($user, $tenantId)
            ->where('legal_entity_id', $establishment->legal_entity_id)
            ->whereIn('customers.id', $this->visibleCustomerEstablishmentsQuery($user, $tenantId)
                ->where('establishment_id', $establishmentId)
                ->select('customer_establishments.customer_id'))
            ->orderBy('name')
            ->get();
    }

    public function ensureCustomerCreatable(User $user, int $tenantId, string $legalEntityId): LegalEntity
    {
        $this->ensureCanCreateCustomers($user, $tenantId);

        $legalEntity = $this->writableLegalEntitiesQuery($tenantId)
            ->whereKey($legalEntityId)
            ->lockForUpdate()
            ->first();

        if ($legalEntity === null) {
            throw ValidationException::withMessages([
                'legal_entity_id' => [__('The selected legal entity is invalid.')],
            ]);
        }

        return $legalEntity;
    }

    public function ensureCustomerWritable(User $user, int $tenantId, Customer $customer): void
    {
        $this->ensureTenant($user, $tenantId);

        if ($customer->tenant_id !== $tenantId || ! $user->can('update', $customer)) {
            throw new AuthorizationException;
        }
    }

    public function ensureCustomerLegalEntityWritable(
        User $user,
        int $tenantId,
        Customer $customer,
        string $legalEntityId,
    ): LegalEntity {
        $this->ensureCustomerWritable($user, $tenantId, $customer);
        $legalEntity = $this->writableLegalEntitiesQuery($tenantId)
            ->whereKey($legalEntityId)
            ->lockForUpdate()
            ->first();

        if ($legalEntity === null) {
            throw ValidationException::withMessages([
                'legal_entity_id' => [__('The selected legal entity is invalid.')],
            ]);
        }

        return $legalEntity;
    }

    public function ensureCustomerEstablishmentWritable(
        User $user,
        int $tenantId,
        Customer $customer,
        Establishment $establishment,
    ): void {
        $this->ensureCustomerWritable($user, $tenantId, $customer);

        if ($establishment->tenant_id !== $tenantId
            || ! $establishment->is_active
            || $customer->legal_entity_id !== $establishment->legal_entity_id) {
            throw ValidationException::withMessages([
                'establishment_id' => [__('The selected establishment is invalid.')],
            ]);
        }
    }

    public function ensureCustomerEstablishmentWritableRecord(
        User $user,
        int $tenantId,
        CustomerEstablishment $customerEstablishment,
    ): void {
        $this->ensureTenant($user, $tenantId);

        if ($customerEstablishment->tenant_id !== $tenantId
            || ! $user->can('update', $customerEstablishment)) {
            throw new AuthorizationException;
        }
    }

    private function ensureCanCreateCustomers(User $user, int $tenantId): void
    {
        $this->ensureTenant($user, $tenantId);

        if (! $user->can('create', Customer::class)) {
            throw new AuthorizationException;
        }
    }

    private function ensureTenant(User $user, int $tenantId): void
    {
        if ($user->tenant_id !== $tenantId) {
            throw new AuthorizationException;
        }
    }

    /** @return Builder<LegalEntity> */
    private function writableLegalEntitiesQuery(int $tenantId): Builder
    {
        return LegalEntity::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true);
    }

    private function findWritableLegalEntity(int $tenantId, string $legalEntityId): LegalEntity
    {
        return $this->writableLegalEntitiesQuery($tenantId)
            ->whereKey($legalEntityId)
            ->firstOrFail();
    }

    /** @return Builder<Establishment> */
    private function visibleEmployeeEstablishmentsQuery(User $user, int $tenantId): Builder
    {
        $query = Establishment::withTrashed()
            ->where('tenant_id', $tenantId);

        if (! $user->organizationalScopes()->exists()) {
            return $query;
        }

        $assignedCustomerIds = $user->customerAssignments()
            ->where('tenant_id', $tenantId)
            ->currentlyActive()
            ->pluck('customer_id');
        $assignedSiteIds = $user->siteAssignments()
            ->where('tenant_id', $tenantId)
            ->currentlyActive()
            ->pluck('site_id');

        return $query->where(function (Builder $query) use ($assignedCustomerIds, $assignedSiteIds): void {
            $query->whereHas(
                'customerEstablishments',
                fn (Builder $query): Builder => $query->whereIn('customer_id', $assignedCustomerIds),
            )->orWhereHas(
                'sites',
                fn (Builder $query): Builder => $query->whereIn('id', $assignedSiteIds),
            );
        });
    }
}

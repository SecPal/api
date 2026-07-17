<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Customer;
use App\Models\CustomerEstablishment;
use App\Models\Employee;
use App\Models\Establishment;
use App\Models\LegalEntity;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

final class DomainAccessRepository
{
    /**
     * @param  list<int>  $viewableManagementLevels
     * @return Builder<Employee>
     */
    public function visibleEmployeesQuery(
        User $user,
        int $tenantId,
        bool $hasOrganizationalScopes,
        bool $allowsSelfAccess,
        array $viewableManagementLevels,
    ): Builder {
        $query = Employee::query()
            ->where('tenant_id', $tenantId)
            ->whereIn(
                'establishment_id',
                $this->visibleEmployeeEstablishmentsQuery($user, $tenantId, $hasOrganizationalScopes)
                    ->select('establishments.id'),
            );

        if (! $hasOrganizationalScopes) {
            return $query;
        }

        if (! $allowsSelfAccess && $viewableManagementLevels === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $query) use ($allowsSelfAccess, $user, $viewableManagementLevels): void {
            if ($allowsSelfAccess) {
                $query->orWhere('user_id', $user->id);
            }

            if ($viewableManagementLevels !== []) {
                $query->orWhereIn('management_level', $viewableManagementLevels);
            }
        });
    }

    public function employeeDomainExists(
        User $user,
        int $tenantId,
        string $legalEntityId,
        string $establishmentId,
        bool $hasOrganizationalScopes,
        bool $mustBeActive,
    ): bool {
        $query = $this->visibleEmployeeEstablishmentsQuery($user, $tenantId, $hasOrganizationalScopes)
            ->whereKey($establishmentId)
            ->where('legal_entity_id', $legalEntityId);

        if ($mustBeActive) {
            $query->whereNull('establishments.deleted_at')
                ->where('establishments.is_active', true)
                ->whereHas('legalEntity', fn (Builder $query): Builder => $query->where('is_active', true));
        }

        return $query->exists();
    }

    /** @return Builder<Customer> */
    public function visibleCustomersQuery(User $user, int $tenantId, bool $hasUnrestrictedAccess): Builder
    {
        if ($hasUnrestrictedAccess) {
            return Customer::query()->where('tenant_id', $tenantId);
        }

        return $user->accessibleCustomersQuery()->where('customers.tenant_id', $tenantId);
    }

    /** @return Builder<CustomerEstablishment> */
    public function visibleCustomerEstablishmentsQuery(
        User $user,
        int $tenantId,
        bool $hasUnrestrictedAccess,
    ): Builder {
        $query = $this->currentCustomerEstablishmentDomainsQuery($tenantId);

        if ($hasUnrestrictedAccess) {
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
                        ->whereNull('sites.deleted_at')
                        ->whereColumn('sites.customer_id', 'customer_establishments.customer_id')
                        ->whereColumn('sites.establishment_id', 'customer_establishments.establishment_id')
                        ->whereIn('sites.id', $assignedSiteIds);
                });
        });
    }

    /** @return Builder<LegalEntity> */
    public function writableLegalEntitiesQuery(int $tenantId): Builder
    {
        return LegalEntity::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true);
    }

    /** @return Builder<Establishment> */
    public function writableEstablishmentsQuery(int $tenantId, string $legalEntityId): Builder
    {
        return $this->activeEstablishmentsQuery($tenantId)
            ->where('legal_entity_id', $legalEntityId);
    }

    public function findWritableEstablishment(int $tenantId, string $establishmentId): ?Establishment
    {
        return $this->activeEstablishmentsQuery($tenantId)
            ->whereKey($establishmentId)
            ->first();
    }

    /** @return Builder<Customer> */
    public function writableCustomersForEstablishmentQuery(int $tenantId, string $establishmentId): Builder
    {
        return $this->activeCustomersQuery($tenantId)
            ->whereHas(
                'customerEstablishments',
                fn (Builder $query): Builder => $query->where('establishment_id', $establishmentId),
            );
    }

    /** @return Builder<Establishment> */
    public function writableEmployeeEstablishmentsQuery(User $user, int $tenantId): Builder
    {
        return $this->visibleEmployeeEstablishmentsQuery($user, $tenantId, true)
            ->whereIn(
                'establishments.id',
                $this->activeEstablishmentsQuery($tenantId)->select('establishments.id'),
            );
    }

    public function siteDomainIsActive(
        int $tenantId,
        string $customerId,
        string $legalEntityId,
        string $establishmentId,
    ): bool {
        return $this->activeCustomerEstablishmentDomainsQuery($tenantId)
            ->where('customer_id', $customerId)
            ->where('legal_entity_id', $legalEntityId)
            ->where('establishment_id', $establishmentId)
            ->exists();
    }

    public function customerEstablishmentDomainIsActive(
        int $tenantId,
        string $customerId,
        string $establishmentId,
    ): bool {
        return $this->activeCustomersQuery($tenantId)
            ->whereKey($customerId)
            ->whereIn(
                'customers.legal_entity_id',
                $this->activeEstablishmentsQuery($tenantId)
                    ->whereKey($establishmentId)
                    ->select('establishments.legal_entity_id'),
            )
            ->exists();
    }

    public function establishmentDomainIsActive(
        int $tenantId,
        string $legalEntityId,
        string $establishmentId,
    ): bool {
        return $this->activeEstablishmentsQuery($tenantId)
            ->where('legal_entity_id', $legalEntityId)
            ->whereKey($establishmentId)
            ->exists();
    }

    /** @return Builder<Establishment> */
    private function visibleEmployeeEstablishmentsQuery(
        User $user,
        int $tenantId,
        bool $hasOrganizationalScopes,
    ): Builder {
        $query = Establishment::withTrashed()->where('tenant_id', $tenantId);

        if (! $hasOrganizationalScopes) {
            return $query;
        }

        $assignedCustomerIds = $user->customerAssignments()
            ->where('tenant_id', $tenantId)
            ->currentlyActive()
            ->whereIn(
                'customer_id',
                $this->currentCustomersQuery($tenantId)->select('customers.id'),
            )
            ->pluck('customer_id');
        $assignedSiteIds = $user->siteAssignments()
            ->where('tenant_id', $tenantId)
            ->currentlyActive()
            ->whereHas('site', fn (Builder $query): Builder => $query->whereHas(
                'customer',
                fn (Builder $query): Builder => $query->whereHas('legalEntity'),
            ))
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

    /** @return Builder<CustomerEstablishment> */
    private function currentCustomerEstablishmentDomainsQuery(int $tenantId): Builder
    {
        return CustomerEstablishment::query()
            ->where('tenant_id', $tenantId)
            ->whereIn(
                'customer_id',
                $this->currentCustomersQuery($tenantId)->select('customers.id'),
            )
            ->whereIn(
                'establishment_id',
                $this->currentEstablishmentsQuery($tenantId)->select('establishments.id'),
            );
    }

    /** @return Builder<CustomerEstablishment> */
    private function activeCustomerEstablishmentDomainsQuery(int $tenantId): Builder
    {
        return CustomerEstablishment::query()
            ->where('tenant_id', $tenantId)
            ->whereIn(
                'customer_id',
                $this->activeCustomersQuery($tenantId)->select('customers.id'),
            )
            ->whereIn(
                'establishment_id',
                $this->activeEstablishmentsQuery($tenantId)->select('establishments.id'),
            );
    }

    /** @return Builder<Customer> */
    private function currentCustomersQuery(int $tenantId): Builder
    {
        return Customer::query()
            ->where('tenant_id', $tenantId)
            ->whereHas('legalEntity');
    }

    /** @return Builder<Customer> */
    private function activeCustomersQuery(int $tenantId): Builder
    {
        return Customer::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereHas('legalEntity', fn (Builder $query): Builder => $query->where('is_active', true));
    }

    /** @return Builder<Establishment> */
    private function currentEstablishmentsQuery(int $tenantId): Builder
    {
        return Establishment::query()
            ->where('tenant_id', $tenantId)
            ->whereHas('legalEntity');
    }

    /** @return Builder<Establishment> */
    private function activeEstablishmentsQuery(int $tenantId): Builder
    {
        return Establishment::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereHas('legalEntity', fn (Builder $query): Builder => $query->where('is_active', true));
    }
}

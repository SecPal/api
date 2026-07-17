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
use App\Models\User;
use App\Models\UserInternalOrganizationalScope;
use App\Repositories\DomainAccessRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Internal domain access boundary built on the existing tenant and RBAC state.
 */
final class DomainAccessService
{
    public function __construct(private readonly DomainAccessRepository $repository) {}

    /** @return Builder<Employee> */
    public function visibleEmployeesQuery(User $user, int $tenantId): Builder
    {
        $this->ensureTenant($user, $tenantId);

        /** @var Collection<int, UserInternalOrganizationalScope> $scopes */
        $scopes = $user->organizationalScopes()
            ->whereHas('organizationalUnit')
            ->get()
            ->filter(
                fn (UserInternalOrganizationalScope $scope): bool => $scope->hasMinimumAccessLevel('read'),
            );
        $hasOrganizationalScopes = $user->organizationalScopes()->exists();
        $viewableManagementLevels = array_values(
            array_filter(
                range(0, 255),
                fn (int $managementLevel): bool => $scopes->contains(
                    fn (UserInternalOrganizationalScope $scope): bool => $scope->canViewManagementLevel($managementLevel),
                ),
            ),
        );
        $allowsSelfAccess = $scopes->contains(
            fn (UserInternalOrganizationalScope $scope): bool => $scope->allow_self_access,
        );

        return $this->repository->visibleEmployeesQuery(
            $user,
            $tenantId,
            $hasOrganizationalScopes,
            $allowsSelfAccess,
            $viewableManagementLevels,
        );
    }

    public function employeeDomainIsAccessible(
        User $user,
        int $tenantId,
        string $legalEntityId,
        string $establishmentId,
    ): bool {
        $this->ensureTenant($user, $tenantId);

        return $this->repository->employeeDomainExists(
            $user,
            $tenantId,
            $legalEntityId,
            $establishmentId,
            $user->organizationalScopes()->exists(),
            false,
        );
    }

    public function ensureEmployeeDomainWritable(
        User $user,
        int $tenantId,
        string $legalEntityId,
        string $establishmentId,
    ): void {
        $this->ensureTenant($user, $tenantId);

        $isWritable = $this->repository->employeeDomainExists(
            $user,
            $tenantId,
            $legalEntityId,
            $establishmentId,
            $user->organizationalScopes()->exists(),
            true,
        );

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

        return $this->repository->visibleCustomersQuery(
            $user,
            $tenantId,
            $this->hasUnrestrictedCustomerReadAccess($user),
        );
    }

    /** @return Builder<CustomerEstablishment> */
    public function visibleCustomerEstablishmentsQuery(User $user, int $tenantId): Builder
    {
        $this->ensureTenant($user, $tenantId);

        return $this->repository->visibleCustomerEstablishmentsQuery(
            $user,
            $tenantId,
            $this->hasUnrestrictedCustomerReadAccess($user),
        );
    }

    /** @return Collection<int, LegalEntity> */
    public function writableLegalEntities(User $user, int $tenantId): Collection
    {
        $this->ensureCanCreateCustomers($user, $tenantId);

        return $this->repository->writableLegalEntitiesQuery($tenantId)->orderBy('name')->get();
    }

    /** @return Collection<int, Establishment> */
    public function writableEstablishments(
        User $user,
        int $tenantId,
        string $legalEntityId,
    ): Collection {
        $this->ensureCanCreateCustomers($user, $tenantId);
        $this->findWritableLegalEntity($tenantId, $legalEntityId);

        return $this->repository->writableEstablishmentsQuery($tenantId, $legalEntityId)
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
        $establishment = $this->repository->findWritableEstablishment($tenantId, $establishmentId);

        if ($establishment === null) {
            throw ValidationException::withMessages([
                'establishment_id' => [__('The selected establishment is invalid.')],
            ]);
        }

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

        $legalEntity = $this->repository->writableLegalEntitiesQuery($tenantId)
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

        if (! $user->can('customers.update') || $user->organizationalScopes()->exists()) {
            throw new AuthorizationException;
        }

        $legalEntity = $this->repository->writableLegalEntitiesQuery($tenantId)
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

    public function customerEstablishmentIsVisible(
        User $user,
        int $tenantId,
        CustomerEstablishment $customerEstablishment,
    ): bool {
        return $this->visibleCustomerEstablishmentsQuery($user, $tenantId)
            ->whereKey($customerEstablishment->id)
            ->exists();
    }

    public function siteDomainIsActive(
        int $tenantId,
        string $customerId,
        string $legalEntityId,
        string $establishmentId,
    ): bool {
        return $this->repository->siteDomainIsActive(
            $tenantId,
            $customerId,
            $legalEntityId,
            $establishmentId,
        );
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

    private function findWritableLegalEntity(int $tenantId, string $legalEntityId): LegalEntity
    {
        return $this->repository->writableLegalEntitiesQuery($tenantId)
            ->whereKey($legalEntityId)
            ->firstOrFail();
    }

    private function hasUnrestrictedCustomerReadAccess(User $user): bool
    {
        return $user->can('customers.read') && ! $user->organizationalScopes()->exists();
    }
}

<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Customer;
use App\Models\CustomerUserAccess;
use App\Models\OrganizationalUnit;
use App\Models\TenantKey;
use App\Models\User;
use App\Models\UserInternalOrganizationalScope;
use App\Policies\CustomerPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    // Set tenant context for permission system (required for role assignments)
    $this->registrar = app(PermissionRegistrar::class);
    $this->registrar->setPermissionsTeamId($this->tenant->id);

    // Run seeder to ensure predefined roles exist
    Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    $this->policy = new CustomerPolicy;

    // Create internal organizational structure
    $this->orgUnit = OrganizationalUnit::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Test Branch',
        'type' => 'branch',
    ]);

    // Create customer hierarchy: Corporate -> Regional -> Local
    $this->corporateCustomer = Customer::factory()->forTenant($this->tenant->id)->corporate()->create([
        'name' => 'Corporate HQ',
        'managed_by_organizational_unit_id' => $this->orgUnit->id,
    ]);

    $this->regionalCustomer = Customer::factory()->forTenant($this->tenant->id)->regional()->create([
        'name' => 'Regional Office',
        'managed_by_organizational_unit_id' => $this->orgUnit->id,
    ]);
    $this->regionalCustomer->setParent($this->corporateCustomer);

    $this->localCustomer = Customer::factory()->forTenant($this->tenant->id)->local()->create([
        'name' => 'Local Branch',
        'managed_by_organizational_unit_id' => $this->orgUnit->id,
    ]);
    $this->localCustomer->setParent($this->regionalCustomer);
});

afterEach(function (): void {
    // Reset tenant context
    $this->registrar->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

describe('CustomerPolicy - Customer Users (Client Role)', function (): void {
    beforeEach(function (): void {
        $this->clientUser = User::factory()->create();
        $this->clientUser->assignRole('Client');
    });

    describe('viewAny', function (): void {
        it('allows customer user with any customer access to view list', function (): void {
            CustomerUserAccess::factory()
                ->forUser($this->clientUser)
                ->forCustomer($this->localCustomer)
                ->forTenant($this->tenant->id)
                ->create(['include_descendants' => false]);

            expect($this->policy->viewAny($this->clientUser))->toBeTrue();
        });

        it('denies customer user without any access', function (): void {
            expect($this->policy->viewAny($this->clientUser))->toBeFalse();
        });
    });

    describe('view', function (): void {
        it('allows viewing directly assigned customer', function (): void {
            CustomerUserAccess::factory()
                ->forUser($this->clientUser)
                ->forCustomer($this->regionalCustomer)
                ->forTenant($this->tenant->id)
                ->create(['include_descendants' => false]);

            expect($this->policy->view($this->clientUser, $this->regionalCustomer))->toBeTrue();
        });

        it('allows viewing descendant customer with include_descendants=true', function (): void {
            CustomerUserAccess::factory()
                ->forUser($this->clientUser)
                ->forCustomer($this->corporateCustomer)
                ->forTenant($this->tenant->id)
                ->corporateWide()
                ->create();

            // Can view corporate (direct)
            expect($this->policy->view($this->clientUser, $this->corporateCustomer))->toBeTrue();
            // Can view regional (descendant)
            expect($this->policy->view($this->clientUser, $this->regionalCustomer))->toBeTrue();
            // Can view local (descendant)
            expect($this->policy->view($this->clientUser, $this->localCustomer))->toBeTrue();
        });

        it('denies viewing descendant customer with include_descendants=false', function (): void {
            CustomerUserAccess::factory()
                ->forUser($this->clientUser)
                ->forCustomer($this->corporateCustomer)
                ->forTenant($this->tenant->id)
                ->create(['include_descendants' => false]);

            // Can view corporate (direct)
            expect($this->policy->view($this->clientUser, $this->corporateCustomer))->toBeTrue();
            // Cannot view regional (descendant, but include_descendants=false)
            expect($this->policy->view($this->clientUser, $this->regionalCustomer))->toBeFalse();
        });

        it('denies viewing ancestor customer', function (): void {
            CustomerUserAccess::factory()
                ->forUser($this->clientUser)
                ->forCustomer($this->localCustomer)
                ->forTenant($this->tenant->id)
                ->create(['include_descendants' => true]);

            // Can view local (direct)
            expect($this->policy->view($this->clientUser, $this->localCustomer))->toBeTrue();
            // Cannot view regional (ancestor)
            expect($this->policy->view($this->clientUser, $this->regionalCustomer))->toBeFalse();
            // Cannot view corporate (ancestor)
            expect($this->policy->view($this->clientUser, $this->corporateCustomer))->toBeFalse();
        });

        it('denies viewing customer with no access', function (): void {
            expect($this->policy->view($this->clientUser, $this->localCustomer))->toBeFalse();
        });
    });

    describe('read-only enforcement', function (): void {
        beforeEach(function (): void {
            // Give full corporate access
            CustomerUserAccess::factory()
                ->forUser($this->clientUser)
                ->forCustomer($this->corporateCustomer)
                ->forTenant($this->tenant->id)
                ->corporateWide()
                ->create();
        });

        it('denies create permission for customer users', function (): void {
            expect($this->policy->create($this->clientUser))->toBeFalse();
        });

        it('denies update permission for customer users', function (): void {
            expect($this->policy->update($this->clientUser, $this->corporateCustomer))->toBeFalse();
        });

        it('denies delete permission for customer users', function (): void {
            expect($this->policy->delete($this->clientUser, $this->corporateCustomer))->toBeFalse();
        });

        it('denies restore permission for customer users', function (): void {
            expect($this->policy->restore($this->clientUser, $this->corporateCustomer))->toBeFalse();
        });

        it('denies forceDelete permission for customer users', function (): void {
            expect($this->policy->forceDelete($this->clientUser, $this->corporateCustomer))->toBeFalse();
        });
    });

    describe('tenant isolation', function (): void {
        it('denies access to customer from different tenant', function (): void {
            // Create second tenant
            $keys2 = TenantKey::generateEnvelopeKeys();
            $tenant2 = TenantKey::create($keys2);

            $otherTenantCustomer = Customer::factory()->forTenant($tenant2->id)->create();

            // User has access in their tenant
            CustomerUserAccess::factory()
                ->forUser($this->clientUser)
                ->forCustomer($this->corporateCustomer)
                ->forTenant($this->tenant->id)
                ->corporateWide()
                ->create();

            // But cannot see other tenant's customers
            expect($this->policy->view($this->clientUser, $otherTenantCustomer))->toBeFalse();
        });
    });

    describe('multiple access records', function (): void {
        it('aggregates access from multiple records', function (): void {
            // Create a separate customer hierarchy in the same tenant
            $separateCorporate = Customer::factory()->forTenant($this->tenant->id)->corporate()->create([
                'name' => 'Separate Corp',
            ]);
            $separateLocal = Customer::factory()->forTenant($this->tenant->id)->local()->create([
                'name' => 'Separate Local',
            ]);
            $separateLocal->setParent($separateCorporate);

            // User has access to both customer hierarchies
            CustomerUserAccess::factory()
                ->forUser($this->clientUser)
                ->forCustomer($this->localCustomer)
                ->forTenant($this->tenant->id)
                ->create(['include_descendants' => false]);

            CustomerUserAccess::factory()
                ->forUser($this->clientUser)
                ->forCustomer($separateCorporate)
                ->forTenant($this->tenant->id)
                ->create(['include_descendants' => true]);

            // Can view local from first hierarchy
            expect($this->policy->view($this->clientUser, $this->localCustomer))->toBeTrue();
            // Can view corporate from second hierarchy
            expect($this->policy->view($this->clientUser, $separateCorporate))->toBeTrue();
            // Can view local from second hierarchy (descendant)
            expect($this->policy->view($this->clientUser, $separateLocal))->toBeTrue();
            // Cannot view corporate from first hierarchy (not in any access scope)
            expect($this->policy->view($this->clientUser, $this->corporateCustomer))->toBeFalse();
        });
    });
});

describe('CustomerPolicy - Internal Employees (non-Client roles)', function (): void {
    beforeEach(function (): void {
        $this->internalUser = User::factory()->create();
        $this->internalUser->assignRole('Admin');

        // Give internal user organizational scope
        UserInternalOrganizationalScope::create([
            'user_id' => $this->internalUser->id,
            'organizational_unit_id' => $this->orgUnit->id,
            'access_level' => 'admin',
            'include_descendants' => true,
        ]);
    });

    describe('viewAny', function (): void {
        it('allows internal employees with organizational scope to view customer list', function (): void {
            expect($this->policy->viewAny($this->internalUser))->toBeTrue();
        });

        it('denies internal employees without organizational scope', function (): void {
            $userWithoutScope = User::factory()->create();
            $userWithoutScope->assignRole('Admin');

            expect($this->policy->viewAny($userWithoutScope))->toBeFalse();
        });
    });

    describe('view', function (): void {
        it('allows viewing customer managed by accessible organizational unit', function (): void {
            expect($this->policy->view($this->internalUser, $this->corporateCustomer))->toBeTrue();
        });

        it('denies viewing customer managed by inaccessible organizational unit', function (): void {
            // Create another org unit without scope
            $otherOrgUnit = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Other Branch',
                'type' => 'branch',
            ]);

            $otherCustomer = Customer::factory()->forTenant($this->tenant->id)->create([
                'managed_by_organizational_unit_id' => $otherOrgUnit->id,
            ]);

            expect($this->policy->view($this->internalUser, $otherCustomer))->toBeFalse();
        });

        it('allows viewing customer with hierarchical org unit access', function (): void {
            // Create org unit hierarchy
            $parentOrgUnit = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Parent Region',
                'type' => 'region',
            ]);
            $this->orgUnit->setParent($parentOrgUnit);

            // User has access to parent with include_descendants
            UserInternalOrganizationalScope::where('user_id', $this->internalUser->id)->delete();
            UserInternalOrganizationalScope::create([
                'user_id' => $this->internalUser->id,
                'organizational_unit_id' => $parentOrgUnit->id,
                'access_level' => 'read',
                'include_descendants' => true,
            ]);

            expect($this->policy->view($this->internalUser, $this->corporateCustomer))->toBeTrue();
        });
    });

    describe('write permissions', function (): void {
        it('allows create for internal employees with manage access', function (): void {
            expect($this->policy->create($this->internalUser))->toBeTrue();
        });

        it('allows update for internal employees with write access', function (): void {
            expect($this->policy->update($this->internalUser, $this->corporateCustomer))->toBeTrue();
        });

        it('allows delete for internal employees with admin access', function (): void {
            expect($this->policy->delete($this->internalUser, $this->corporateCustomer))->toBeTrue();
        });

        it('denies update for internal employees with read-only access', function (): void {
            $readOnlyUser = User::factory()->create();
            $readOnlyUser->assignRole('Admin');
            UserInternalOrganizationalScope::create([
                'user_id' => $readOnlyUser->id,
                'organizational_unit_id' => $this->orgUnit->id,
                'access_level' => 'read',
                'include_descendants' => true,
            ]);

            expect($this->policy->update($readOnlyUser, $this->corporateCustomer))->toBeFalse();
        });
    });
});

describe('CustomerPolicy - Customer users cannot see internal org structure', function (): void {
    it('customer user cannot access managedBy relationship data', function (): void {
        $clientUser = User::factory()->create();
        $clientUser->assignRole('Client');

        CustomerUserAccess::factory()
            ->forUser($clientUser)
            ->forCustomer($this->corporateCustomer)
            ->forTenant($this->tenant->id)
            ->corporateWide()
            ->create();

        // This test verifies the policy allows viewing the customer
        // but the actual hiding of managedBy is handled by the API resource/controller
        // The policy just ensures the customer is viewable
        expect($this->policy->view($clientUser, $this->corporateCustomer))->toBeTrue();

        // The viewManagedBy policy method should deny access
        expect($this->policy->viewManagedBy($clientUser, $this->corporateCustomer))->toBeFalse();
    });

    it('internal employee can see managedBy relationship', function (): void {
        $internalUser = User::factory()->create();
        $internalUser->assignRole('Admin');
        UserInternalOrganizationalScope::create([
            'user_id' => $internalUser->id,
            'organizational_unit_id' => $this->orgUnit->id,
            'access_level' => 'read',
        ]);

        expect($this->policy->viewManagedBy($internalUser, $this->corporateCustomer))->toBeTrue();
    });
});

<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Customer;
use App\Models\CustomerUserAccess;
use App\Models\CustomerUserObjectAccess;
use App\Models\OrganizationalUnit;
use App\Models\SecPalObject;
use App\Models\TenantKey;
use App\Models\User;
use App\Models\UserInternalOrganizationalScope;
use App\Policies\ObjectPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    // Set tenant context for permission system
    $this->registrar = app(PermissionRegistrar::class);
    $this->registrar->setPermissionsTeamId($this->tenant->id);

    // Run seeder to ensure predefined roles exist
    Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    $this->policy = new ObjectPolicy;

    // Create internal organizational structure
    $this->orgUnit = OrganizationalUnit::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Test Branch',
        'type' => 'branch',
    ]);

    // Create customer hierarchy: Corporate -> Regional -> Local
    $this->corporateCustomer = Customer::factory()->forTenant($this->tenant->id)->corporate()->create([
        'managed_by_organizational_unit_id' => $this->orgUnit->id,
    ]);

    $this->regionalCustomer = Customer::factory()->forTenant($this->tenant->id)->regional()->create();
    $this->regionalCustomer->setParent($this->corporateCustomer);

    $this->localCustomer = Customer::factory()->forTenant($this->tenant->id)->local()->create();
    $this->localCustomer->setParent($this->regionalCustomer);

    // Create objects for customers
    $this->corporateObject = SecPalObject::factory()
        ->forCustomer($this->corporateCustomer)
        ->forTenant($this->tenant->id)
        ->create();

    $this->localObject = SecPalObject::factory()
        ->forCustomer($this->localCustomer)
        ->forTenant($this->tenant->id)
        ->create();
});

afterEach(function (): void {
    $this->registrar->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

describe('ObjectPolicy - Customer Users (Client Role)', function (): void {
    beforeEach(function (): void {
        $this->clientUser = User::factory()->create();
        $this->clientUser->assignRole('Client');
    });

    describe('viewAny', function (): void {
        it('allows customer user with customer access', function (): void {
            CustomerUserAccess::factory()
                ->forUser($this->clientUser)
                ->forCustomer($this->corporateCustomer)
                ->forTenant($this->tenant->id)
                ->create();

            expect($this->policy->viewAny($this->clientUser))->toBeTrue();
        });

        it('allows customer user with object access only', function (): void {
            CustomerUserObjectAccess::factory()
                ->forUser($this->clientUser)
                ->forObject($this->corporateObject)
                ->forTenant($this->tenant->id)
                ->create();

            expect($this->policy->viewAny($this->clientUser))->toBeTrue();
        });

        it('denies customer user without any access', function (): void {
            expect($this->policy->viewAny($this->clientUser))->toBeFalse();
        });
    });

    describe('view', function (): void {
        it('allows viewing object via customer hierarchy access', function (): void {
            CustomerUserAccess::factory()
                ->forUser($this->clientUser)
                ->forCustomer($this->corporateCustomer)
                ->forTenant($this->tenant->id)
                ->corporateWide()
                ->create();

            // Can view object of corporate customer
            expect($this->policy->view($this->clientUser, $this->corporateObject))->toBeTrue();
            // Can view object of descendant customer
            expect($this->policy->view($this->clientUser, $this->localObject))->toBeTrue();
        });

        it('allows viewing object via fine-grained object access', function (): void {
            CustomerUserObjectAccess::factory()
                ->forUser($this->clientUser)
                ->forObject($this->localObject)
                ->forTenant($this->tenant->id)
                ->create();

            expect($this->policy->view($this->clientUser, $this->localObject))->toBeTrue();
        });

        it('denies viewing object without access', function (): void {
            CustomerUserAccess::factory()
                ->forUser($this->clientUser)
                ->forCustomer($this->localCustomer)
                ->forTenant($this->tenant->id)
                ->create(['include_descendants' => false]);

            // Can view local object
            expect($this->policy->view($this->clientUser, $this->localObject))->toBeTrue();
            // Cannot view corporate object (ancestor)
            expect($this->policy->view($this->clientUser, $this->corporateObject))->toBeFalse();
        });
    });

    describe('read-only enforcement', function (): void {
        beforeEach(function (): void {
            CustomerUserAccess::factory()
                ->forUser($this->clientUser)
                ->forCustomer($this->corporateCustomer)
                ->forTenant($this->tenant->id)
                ->corporateWide()
                ->create();
        });

        it('denies create permission', function (): void {
            expect($this->policy->create($this->clientUser))->toBeFalse();
        });

        it('denies update permission', function (): void {
            expect($this->policy->update($this->clientUser, $this->corporateObject))->toBeFalse();
        });

        it('denies delete permission', function (): void {
            expect($this->policy->delete($this->clientUser, $this->corporateObject))->toBeFalse();
        });
    });
});

describe('ObjectPolicy - Internal Employees', function (): void {
    beforeEach(function (): void {
        $this->internalUser = User::factory()->create();
        $this->internalUser->assignRole('Admin');

        UserInternalOrganizationalScope::create([
            'user_id' => $this->internalUser->id,
            'organizational_unit_id' => $this->orgUnit->id,
            'access_level' => 'admin',
            'include_descendants' => true,
        ]);
    });

    it('allows viewing objects of managed customers', function (): void {
        expect($this->policy->view($this->internalUser, $this->corporateObject))->toBeTrue();
    });

    it('allows creating objects with manage access', function (): void {
        expect($this->policy->create($this->internalUser))->toBeTrue();
    });

    it('allows updating objects with write access', function (): void {
        expect($this->policy->update($this->internalUser, $this->corporateObject))->toBeTrue();
    });

    it('allows deleting objects with admin access', function (): void {
        expect($this->policy->delete($this->internalUser, $this->corporateObject))->toBeTrue();
    });

    it('denies access to objects of customers managed by other org units', function (): void {
        $otherOrgUnit = OrganizationalUnit::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Other Branch',
            'type' => 'branch',
        ]);

        $otherCustomer = Customer::factory()->forTenant($this->tenant->id)->create([
            'managed_by_organizational_unit_id' => $otherOrgUnit->id,
        ]);

        $otherObject = SecPalObject::factory()
            ->forCustomer($otherCustomer)
            ->forTenant($this->tenant->id)
            ->create();

        expect($this->policy->view($this->internalUser, $otherObject))->toBeFalse();
    });
});

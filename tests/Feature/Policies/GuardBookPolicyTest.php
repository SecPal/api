<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Customer;
use App\Models\CustomerUserAccess;
use App\Models\CustomerUserObjectAccess;
use App\Models\GuardBook;
use App\Models\OrganizationalUnit;
use App\Models\SecPalObject;
use App\Models\TenantKey;
use App\Models\User;
use App\Models\UserInternalOrganizationalScope;
use App\Policies\GuardBookPolicy;
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

    $this->policy = new GuardBookPolicy;

    // Create internal organizational structure
    $this->orgUnit = OrganizationalUnit::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Test Branch',
        'type' => 'branch',
    ]);

    // Create customer
    $this->customer = Customer::factory()->forTenant($this->tenant->id)->create([
        'managed_by_organizational_unit_id' => $this->orgUnit->id,
    ]);

    // Create object
    $this->object = SecPalObject::factory()
        ->forCustomer($this->customer)
        ->forTenant($this->tenant->id)
        ->create();

    // Create guard book
    $this->guardBook = GuardBook::factory()
        ->forObject($this->object)
        ->forTenant($this->tenant->id)
        ->create();
});

afterEach(function (): void {
    $this->registrar->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

describe('GuardBookPolicy - Customer Users (Client Role)', function (): void {
    beforeEach(function (): void {
        $this->clientUser = User::factory()->create();
        $this->clientUser->assignRole('Client');
    });

    describe('viewAny', function (): void {
        it('allows customer user with customer access', function (): void {
            CustomerUserAccess::factory()
                ->forUser($this->clientUser)
                ->forCustomer($this->customer)
                ->forTenant($this->tenant->id)
                ->create();

            expect($this->policy->viewAny($this->clientUser))->toBeTrue();
        });

        it('allows customer user with object access only', function (): void {
            CustomerUserObjectAccess::factory()
                ->forUser($this->clientUser)
                ->forObject($this->object)
                ->forTenant($this->tenant->id)
                ->create();

            expect($this->policy->viewAny($this->clientUser))->toBeTrue();
        });

        it('denies customer user without any access', function (): void {
            expect($this->policy->viewAny($this->clientUser))->toBeFalse();
        });
    });

    describe('view with customer hierarchy access', function (): void {
        it('allows viewing guard book via customer hierarchy', function (): void {
            CustomerUserAccess::factory()
                ->forUser($this->clientUser)
                ->forCustomer($this->customer)
                ->forTenant($this->tenant->id)
                ->create();

            expect($this->policy->view($this->clientUser, $this->guardBook))->toBeTrue();
        });

        it('denies viewing guard book without customer access', function (): void {
            $otherCustomer = Customer::factory()->forTenant($this->tenant->id)->create();

            CustomerUserAccess::factory()
                ->forUser($this->clientUser)
                ->forCustomer($otherCustomer)
                ->forTenant($this->tenant->id)
                ->create();

            expect($this->policy->view($this->clientUser, $this->guardBook))->toBeFalse();
        });
    });

    describe('view with fine-grained object access', function (): void {
        it('allows viewing guard book if read_guard_book action is allowed', function (): void {
            CustomerUserObjectAccess::factory()
                ->forUser($this->clientUser)
                ->forObject($this->object)
                ->forTenant($this->tenant->id)
                ->create(['allowed_actions' => ['read_guard_book', 'read_reports']]);

            expect($this->policy->view($this->clientUser, $this->guardBook))->toBeTrue();
        });

        it('denies viewing guard book if read_guard_book action is not allowed', function (): void {
            CustomerUserObjectAccess::factory()
                ->forUser($this->clientUser)
                ->forObject($this->object)
                ->forTenant($this->tenant->id)
                ->create(['allowed_actions' => ['read_reports', 'view_shifts']]);

            expect($this->policy->view($this->clientUser, $this->guardBook))->toBeFalse();
        });

        it('allows viewing with default allowed_actions (includes read_guard_book)', function (): void {
            CustomerUserObjectAccess::factory()
                ->forUser($this->clientUser)
                ->forObject($this->object)
                ->forTenant($this->tenant->id)
                ->create(['allowed_actions' => CustomerUserObjectAccess::DEFAULT_ALLOWED_ACTIONS]);

            expect($this->policy->view($this->clientUser, $this->guardBook))->toBeTrue();
        });
    });

    describe('read-only enforcement', function (): void {
        beforeEach(function (): void {
            CustomerUserAccess::factory()
                ->forUser($this->clientUser)
                ->forCustomer($this->customer)
                ->forTenant($this->tenant->id)
                ->create();
        });

        it('denies create permission', function (): void {
            expect($this->policy->create($this->clientUser))->toBeFalse();
        });

        it('denies update permission', function (): void {
            expect($this->policy->update($this->clientUser, $this->guardBook))->toBeFalse();
        });

        it('denies delete permission', function (): void {
            expect($this->policy->delete($this->clientUser, $this->guardBook))->toBeFalse();
        });
    });
});

describe('GuardBookPolicy - Internal Employees', function (): void {
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

    it('allows viewing guard books of managed customers', function (): void {
        expect($this->policy->view($this->internalUser, $this->guardBook))->toBeTrue();
    });

    it('allows creating guard books with manage access', function (): void {
        expect($this->policy->create($this->internalUser))->toBeTrue();
    });

    it('allows updating guard books with write access', function (): void {
        expect($this->policy->update($this->internalUser, $this->guardBook))->toBeTrue();
    });

    it('allows deleting guard books with admin access', function (): void {
        expect($this->policy->delete($this->internalUser, $this->guardBook))->toBeTrue();
    });

    it('denies access to guard books of customers managed by other org units', function (): void {
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

        $otherGuardBook = GuardBook::factory()
            ->forObject($otherObject)
            ->forTenant($this->tenant->id)
            ->create();

        expect($this->policy->view($this->internalUser, $otherGuardBook))->toBeFalse();
    });
});

<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\OrganizationalUnit;
use App\Models\TenantKey;
use App\Models\User;
use App\Models\UserInternalOrganizationalScope;
use App\Policies\OrganizationalUnitPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * @property TenantKey $tenant
 * @property User $user
 * @property OrganizationalUnitPolicy $policy
 * @property OrganizationalUnit $company
 * @property OrganizationalUnit $region
 * @property OrganizationalUnit $branch
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    $this->user = User::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);
    $this->policy = new OrganizationalUnitPolicy;

    // Create organizational hierarchy: Company -> Region -> Branch
    $this->company = OrganizationalUnit::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Test Company',
        'type' => 'company',
    ]);

    $this->region = OrganizationalUnit::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Test Region',
        'type' => 'region',
    ]);
    $this->region->setParent($this->company);

    $this->branch = OrganizationalUnit::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Test Branch',
        'type' => 'branch',
    ]);
    $this->branch->setParent($this->region);
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

describe('OrganizationalUnitPolicy', function () {
    describe('viewAny', function () {
        it('allows users with any scope to view units list', function (): void {
            UserInternalOrganizationalScope::create([
                'user_id' => $this->user->id,
                'organizational_unit_id' => $this->branch->id,
                'access_level' => 'read',
            ]);

            expect($this->policy->viewAny($this->user))->toBeTrue();
        });

        it('denies users without any scope', function (): void {
            expect($this->policy->viewAny($this->user))->toBeFalse();
        });
    });

    describe('view', function () {
        it('allows viewing directly scoped unit with read access', function (): void {
            UserInternalOrganizationalScope::create([
                'user_id' => $this->user->id,
                'organizational_unit_id' => $this->region->id,
                'access_level' => 'read',
            ]);

            expect($this->policy->view($this->user, $this->region))->toBeTrue();
        });

        it('allows viewing descendant unit when include_descendants is true', function (): void {
            UserInternalOrganizationalScope::create([
                'user_id' => $this->user->id,
                'organizational_unit_id' => $this->region->id,
                'access_level' => 'read',
                'include_descendants' => true,
            ]);

            expect($this->policy->view($this->user, $this->branch))->toBeTrue();
        });

        it('denies viewing descendant unit when include_descendants is false', function (): void {
            UserInternalOrganizationalScope::create([
                'user_id' => $this->user->id,
                'organizational_unit_id' => $this->region->id,
                'access_level' => 'read',
                'include_descendants' => false,
            ]);

            expect($this->policy->view($this->user, $this->branch))->toBeFalse();
        });

        it('denies viewing ancestor unit', function (): void {
            UserInternalOrganizationalScope::create([
                'user_id' => $this->user->id,
                'organizational_unit_id' => $this->region->id,
                'access_level' => 'manage',
                'include_descendants' => true,
            ]);

            expect($this->policy->view($this->user, $this->company))->toBeFalse();
        });

        it('denies viewing unit with no scope', function (): void {
            expect($this->policy->view($this->user, $this->branch))->toBeFalse();
        });
    });

    describe('create', function () {
        it('allows creating child unit with manage access', function (): void {
            UserInternalOrganizationalScope::create([
                'user_id' => $this->user->id,
                'organizational_unit_id' => $this->region->id,
                'access_level' => 'manage',
            ]);

            expect($this->policy->create($this->user, $this->region))->toBeTrue();
        });

        it('denies creating child unit with write access', function (): void {
            UserInternalOrganizationalScope::create([
                'user_id' => $this->user->id,
                'organizational_unit_id' => $this->region->id,
                'access_level' => 'write',
            ]);

            expect($this->policy->create($this->user, $this->region))->toBeFalse();
        });

        it('denies creating child unit with read access', function (): void {
            UserInternalOrganizationalScope::create([
                'user_id' => $this->user->id,
                'organizational_unit_id' => $this->region->id,
                'access_level' => 'read',
            ]);

            expect($this->policy->create($this->user, $this->region))->toBeFalse();
        });
    });

    describe('update', function () {
        it('allows updating with write access', function (): void {
            UserInternalOrganizationalScope::create([
                'user_id' => $this->user->id,
                'organizational_unit_id' => $this->region->id,
                'access_level' => 'write',
            ]);

            expect($this->policy->update($this->user, $this->region))->toBeTrue();
        });

        it('allows updating with manage access', function (): void {
            UserInternalOrganizationalScope::create([
                'user_id' => $this->user->id,
                'organizational_unit_id' => $this->region->id,
                'access_level' => 'manage',
            ]);

            expect($this->policy->update($this->user, $this->region))->toBeTrue();
        });

        it('denies updating with read access', function (): void {
            UserInternalOrganizationalScope::create([
                'user_id' => $this->user->id,
                'organizational_unit_id' => $this->region->id,
                'access_level' => 'read',
            ]);

            expect($this->policy->update($this->user, $this->region))->toBeFalse();
        });

        it('allows updating descendant with hierarchical access', function (): void {
            UserInternalOrganizationalScope::create([
                'user_id' => $this->user->id,
                'organizational_unit_id' => $this->region->id,
                'access_level' => 'write',
                'include_descendants' => true,
            ]);

            expect($this->policy->update($this->user, $this->branch))->toBeTrue();
        });
    });

    describe('delete', function () {
        it('allows deleting with manage access', function (): void {
            UserInternalOrganizationalScope::create([
                'user_id' => $this->user->id,
                'organizational_unit_id' => $this->region->id,
                'access_level' => 'manage',
            ]);

            expect($this->policy->delete($this->user, $this->region))->toBeTrue();
        });

        it('denies deleting with write access', function (): void {
            UserInternalOrganizationalScope::create([
                'user_id' => $this->user->id,
                'organizational_unit_id' => $this->region->id,
                'access_level' => 'write',
            ]);

            expect($this->policy->delete($this->user, $this->region))->toBeFalse();
        });

        it('allows deleting descendant with hierarchical manage access', function (): void {
            UserInternalOrganizationalScope::create([
                'user_id' => $this->user->id,
                'organizational_unit_id' => $this->region->id,
                'access_level' => 'manage',
                'include_descendants' => true,
            ]);

            expect($this->policy->delete($this->user, $this->branch))->toBeTrue();
        });
    });

    describe('manageScopes', function () {
        it('allows managing scopes with manage access', function (): void {
            UserInternalOrganizationalScope::create([
                'user_id' => $this->user->id,
                'organizational_unit_id' => $this->region->id,
                'access_level' => 'manage',
            ]);

            expect($this->policy->manageScopes($this->user, $this->region))->toBeTrue();
        });

        it('denies managing scopes with write access', function (): void {
            UserInternalOrganizationalScope::create([
                'user_id' => $this->user->id,
                'organizational_unit_id' => $this->region->id,
                'access_level' => 'write',
            ]);

            expect($this->policy->manageScopes($this->user, $this->region))->toBeFalse();
        });

        it('allows managing scopes for descendant with hierarchical manage access', function (): void {
            UserInternalOrganizationalScope::create([
                'user_id' => $this->user->id,
                'organizational_unit_id' => $this->region->id,
                'access_level' => 'manage',
                'include_descendants' => true,
            ]);

            expect($this->policy->manageScopes($this->user, $this->branch))->toBeTrue();
        });
    });

    describe('Access Level Hierarchy', function () {
        it('respects access level hierarchy for operations', function (): void {
            // User with 'manage' access level on region
            UserInternalOrganizationalScope::create([
                'user_id' => $this->user->id,
                'organizational_unit_id' => $this->region->id,
                'access_level' => 'manage',
                'include_descendants' => true,
            ]);

            // read (1) - should pass
            expect($this->policy->view($this->user, $this->region))->toBeTrue();
            expect($this->policy->view($this->user, $this->branch))->toBeTrue();

            // write (2) - should pass (manage > write)
            expect($this->policy->update($this->user, $this->region))->toBeTrue();
            expect($this->policy->update($this->user, $this->branch))->toBeTrue();

            // manage (3) - should pass
            expect($this->policy->create($this->user, $this->region))->toBeTrue();

            // delete and manageScopes now also require manage and should pass
            expect($this->policy->delete($this->user, $this->region))->toBeTrue();
            expect($this->policy->manageScopes($this->user, $this->region))->toBeTrue();
        });
    });

    describe('Multiple Scopes', function () {
        it('uses highest access level when user has multiple scopes', function (): void {
            // User has read on company and manage on branch
            UserInternalOrganizationalScope::create([
                'user_id' => $this->user->id,
                'organizational_unit_id' => $this->company->id,
                'access_level' => 'read',
                'include_descendants' => false,
            ]);

            UserInternalOrganizationalScope::create([
                'user_id' => $this->user->id,
                'organizational_unit_id' => $this->branch->id,
                'access_level' => 'manage',
            ]);

            // Company: only read
            expect($this->policy->view($this->user, $this->company))->toBeTrue();
            expect($this->policy->update($this->user, $this->company))->toBeFalse();

            // Branch: manage
            expect($this->policy->view($this->user, $this->branch))->toBeTrue();
            expect($this->policy->delete($this->user, $this->branch))->toBeTrue();
        });
    });
});

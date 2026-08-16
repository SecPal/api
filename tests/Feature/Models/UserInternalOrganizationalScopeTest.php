<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\OrganizationalUnit;
use App\Models\TenantKey;
use App\Models\User;
use App\Models\UserInternalOrganizationalScope;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * @property TenantKey $tenant
 * @property User $user
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);
    $this->user = User::factory()->create();
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

describe('UserInternalOrganizationalScope Model', function () {
    describe('Basic CRUD Operations', function () {
        it('can create a scope assignment', function (): void {
            $unit = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Berlin Branch',
                'type' => 'branch',
            ]);

            $scope = UserInternalOrganizationalScope::create([
                'user_id' => $this->user->id,
                'organizational_unit_id' => $unit->id,
                'access_level' => 'read',
                'include_descendants' => true,
            ]);

            expect($scope)->toBeInstanceOf(UserInternalOrganizationalScope::class);
            expect($scope->id)->toBeString();
            expect($scope->user_id)->toBe($this->user->id);
            expect($scope->organizational_unit_id)->toBe($unit->id);
            expect($scope->access_level)->toBe('read');
            expect($scope->include_descendants)->toBeTrue();
        });

        it('uses UUID for primary key', function (): void {
            $unit = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Test Unit',
                'type' => 'department',
            ]);

            $scope = UserInternalOrganizationalScope::create([
                'user_id' => $this->user->id,
                'organizational_unit_id' => $unit->id,
                'access_level' => 'write',
            ]);

            // UUID v4 format
            expect($scope->id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/');
        });

        it('defaults include_descendants to true', function (): void {
            $unit = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Test Unit',
                'type' => 'department',
            ]);

            $scope = UserInternalOrganizationalScope::create([
                'user_id' => $this->user->id,
                'organizational_unit_id' => $unit->id,
                'access_level' => 'manage',
            ]);

            expect($scope->include_descendants)->toBeTrue();
        });
    });

    describe('Access Level Enum Values', function () {
        it('accepts all valid access level enum values', function (): void {
            $validLevels = ['none', 'read', 'write', 'manage'];

            foreach ($validLevels as $level) {
                $unit = OrganizationalUnit::create([
                    'tenant_id' => $this->tenant->id,
                    'name' => "Unit for {$level}",
                    'type' => 'department',
                ]);

                $scope = UserInternalOrganizationalScope::create([
                    'user_id' => $this->user->id,
                    'organizational_unit_id' => $unit->id,
                    'access_level' => $level,
                ]);

                expect($scope->access_level)->toBe($level);
            }
        });
    });

    describe('Relationships', function () {
        it('belongs to a user', function (): void {
            $unit = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Test Unit',
                'type' => 'branch',
            ]);

            $scope = UserInternalOrganizationalScope::create([
                'user_id' => $this->user->id,
                'organizational_unit_id' => $unit->id,
                'access_level' => 'read',
            ]);

            expect($scope->user)->toBeInstanceOf(User::class);
            expect($scope->user->id)->toBe($this->user->id);
        });

        it('belongs to an organizational unit', function (): void {
            $unit = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Test Unit',
                'type' => 'branch',
            ]);

            $scope = UserInternalOrganizationalScope::create([
                'user_id' => $this->user->id,
                'organizational_unit_id' => $unit->id,
                'access_level' => 'write',
            ]);

            expect($scope->organizationalUnit)->toBeInstanceOf(OrganizationalUnit::class);
            expect($scope->organizationalUnit->id)->toBe($unit->id);
        });

        it('is deleted when user is deleted (cascade)', function (): void {
            $unit = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Test Unit',
                'type' => 'branch',
            ]);

            $scope = UserInternalOrganizationalScope::create([
                'user_id' => $this->user->id,
                'organizational_unit_id' => $unit->id,
                'access_level' => 'manage',
            ]);

            $scopeId = $scope->id;
            $this->user->delete();

            expect(UserInternalOrganizationalScope::find($scopeId))->toBeNull();
        });

        it('is deleted when organizational unit is deleted (cascade)', function (): void {
            $unit = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Test Unit',
                'type' => 'branch',
            ]);

            $scope = UserInternalOrganizationalScope::create([
                'user_id' => $this->user->id,
                'organizational_unit_id' => $unit->id,
                'access_level' => 'manage',
            ]);

            $scopeId = $scope->id;
            $unit->forceDelete();

            expect(UserInternalOrganizationalScope::find($scopeId))->toBeNull();
        });
    });

    describe('User Relationship Extensions', function () {
        it('user can access organizational scopes via relationship', function (): void {
            $unit1 = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Unit 1',
                'type' => 'branch',
            ]);

            $unit2 = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Unit 2',
                'type' => 'department',
            ]);

            UserInternalOrganizationalScope::create([
                'user_id' => $this->user->id,
                'organizational_unit_id' => $unit1->id,
                'access_level' => 'read',
            ]);

            UserInternalOrganizationalScope::create([
                'user_id' => $this->user->id,
                'organizational_unit_id' => $unit2->id,
                'access_level' => 'write',
            ]);

            $scopes = $this->user->organizationalScopes;

            expect($scopes)->toHaveCount(2);
        });

        it('user can access scoped organizational units directly', function (): void {
            $unit = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Scoped Unit',
                'type' => 'region',
            ]);

            UserInternalOrganizationalScope::create([
                'user_id' => $this->user->id,
                'organizational_unit_id' => $unit->id,
                'access_level' => 'manage',
            ]);

            $scopedUnits = $this->user->scopedOrganizationalUnits;

            expect($scopedUnits)->toHaveCount(1);
            expect($scopedUnits->first()->id)->toBe($unit->id);
        });
    });

    describe('Access Level Helpers', function () {
        it('hasAccessLevel() returns true for matching level', function (): void {
            $unit = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Test Unit',
                'type' => 'branch',
            ]);

            $scope = UserInternalOrganizationalScope::create([
                'user_id' => $this->user->id,
                'organizational_unit_id' => $unit->id,
                'access_level' => 'write',
            ]);

            expect($scope->hasAccessLevel('write'))->toBeTrue();
        });

        it('hasMinimumAccessLevel() checks access hierarchy', function (): void {
            $unit = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Test Unit',
                'type' => 'branch',
            ]);

            $scope = UserInternalOrganizationalScope::create([
                'user_id' => $this->user->id,
                'organizational_unit_id' => $unit->id,
                'access_level' => 'manage',
            ]);

            // Access levels: none < read < write < manage
            expect($scope->hasMinimumAccessLevel('none'))->toBeTrue();
            expect($scope->hasMinimumAccessLevel('read'))->toBeTrue();
            expect($scope->hasMinimumAccessLevel('write'))->toBeTrue();
            expect($scope->hasMinimumAccessLevel('manage'))->toBeTrue();
            // Unknown levels must not be satisfied
            expect($scope->hasMinimumAccessLevel('legacy'))->toBeFalse();
        });
    });

    describe('Scope with Descendants', function () {
        beforeEach(function (): void {
            // Build hierarchy: Company -> Region -> Branch
            $this->company = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Company',
                'type' => 'company',
            ]);

            $this->region = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Region',
                'type' => 'region',
            ]);
            $this->region->setParent($this->company);

            $this->branch = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Branch',
                'type' => 'branch',
            ]);
            $this->branch->setParent($this->region);
        });

        it('can get all accessible units including descendants', function (): void {
            // Give user access to Region with include_descendants = true
            UserInternalOrganizationalScope::create([
                'user_id' => $this->user->id,
                'organizational_unit_id' => $this->region->id,
                'access_level' => 'read',
                'include_descendants' => true,
            ]);

            $accessibleUnits = $this->user->getAccessibleOrganizationalUnits();

            expect($accessibleUnits)->toHaveCount(2);
            expect($accessibleUnits->pluck('name')->toArray())
                ->toContain('Region')
                ->toContain('Branch');
        });

        it('excludes descendants when include_descendants is false', function (): void {
            // Give user access to Region without descendants
            UserInternalOrganizationalScope::create([
                'user_id' => $this->user->id,
                'organizational_unit_id' => $this->region->id,
                'access_level' => 'read',
                'include_descendants' => false,
            ]);

            $accessibleUnits = $this->user->getAccessibleOrganizationalUnits();

            expect($accessibleUnits)->toHaveCount(1);
            expect($accessibleUnits->first()->name)->toBe('Region');
        });

        it('combines multiple scopes correctly', function (): void {
            // User has access to Company (without descendants) and Branch (with descendants)
            UserInternalOrganizationalScope::create([
                'user_id' => $this->user->id,
                'organizational_unit_id' => $this->company->id,
                'access_level' => 'read',
                'include_descendants' => false,
            ]);

            UserInternalOrganizationalScope::create([
                'user_id' => $this->user->id,
                'organizational_unit_id' => $this->branch->id,
                'access_level' => 'write',
                'include_descendants' => true,
            ]);

            $accessibleUnits = $this->user->getAccessibleOrganizationalUnits();

            // Should have Company (explicit) and Branch (explicit)
            // Not Region (not explicitly granted, not descendant of granted)
            expect($accessibleUnits)->toHaveCount(2);
            expect($accessibleUnits->pluck('name')->toArray())
                ->toContain('Company')
                ->toContain('Branch');
        });

        it('user hasAccessToUnit() works with hierarchy', function (): void {
            UserInternalOrganizationalScope::create([
                'user_id' => $this->user->id,
                'organizational_unit_id' => $this->region->id,
                'access_level' => 'write',
                'include_descendants' => true,
            ]);

            expect($this->user->hasAccessToUnit($this->region))->toBeTrue();
            expect($this->user->hasAccessToUnit($this->branch))->toBeTrue();
            expect($this->user->hasAccessToUnit($this->company))->toBeFalse();
        });

        it('user hasAccessToUnit() respects access level', function (): void {
            UserInternalOrganizationalScope::create([
                'user_id' => $this->user->id,
                'organizational_unit_id' => $this->region->id,
                'access_level' => 'read',
                'include_descendants' => true,
            ]);

            expect($this->user->hasAccessToUnit($this->region, 'read'))->toBeTrue();
            expect($this->user->hasAccessToUnit($this->region, 'write'))->toBeFalse();
            expect($this->user->hasAccessToUnit($this->branch, 'read'))->toBeTrue();
            expect($this->user->hasAccessToUnit($this->branch, 'manage'))->toBeFalse();
        });

        it('user hasAccessToUnit() matches database and in-memory scope resolution for the same scope set', function (): void {
            UserInternalOrganizationalScope::create([
                'user_id' => $this->user->id,
                'organizational_unit_id' => $this->company->id,
                'access_level' => 'manage',
                'include_descendants' => true,
            ]);

            UserInternalOrganizationalScope::create([
                'user_id' => $this->user->id,
                'organizational_unit_id' => $this->branch->id,
                'access_level' => 'read',
                'include_descendants' => false,
            ]);

            $scopes = $this->user->organizationalScopes()->get()->values();

            expect($this->user->hasAccessToUnit($this->region, 'manage', $scopes))->toBe(
                $this->user->hasAccessToUnit($this->region, 'manage')
            )->and($this->user->hasAccessToUnit($this->branch, 'manage', $scopes))->toBe(
                $this->user->hasAccessToUnit($this->branch, 'manage')
            )->and($this->user->hasAccessToUnit($this->branch, 'read', $scopes))->toBe(
                $this->user->hasAccessToUnit($this->branch, 'read')
            )->and($this->user->hasAccessToUnit($this->region, 'manage', $scopes))->toBeTrue()
                ->and($this->user->hasAccessToUnit($this->branch, 'manage', $scopes))->toBeFalse()
                ->and($this->user->hasAccessToUnit($this->branch, 'read', $scopes))->toBeTrue();
        });

        it('user hasAccessToUnit() can evaluate a simulated in-memory scope collection', function (): void {
            UserInternalOrganizationalScope::create([
                'user_id' => $this->user->id,
                'organizational_unit_id' => $this->company->id,
                'access_level' => 'manage',
                'include_descendants' => true,
            ]);

            $persistedDirectScope = UserInternalOrganizationalScope::create([
                'user_id' => $this->user->id,
                'organizational_unit_id' => $this->branch->id,
                'access_level' => 'read',
                'include_descendants' => false,
            ]);

            $simulatedScopes = $this->user->organizationalScopes()
                ->whereKeyNot($persistedDirectScope->id)
                ->get()
                ->values();

            expect($this->user->hasAccessToUnit($this->branch, 'manage'))->toBeFalse()
                ->and($this->user->hasAccessToUnit($this->branch, 'manage', $simulatedScopes))->toBeTrue();
        });
    });

});

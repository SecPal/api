<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\OrganizationalUnit;
use App\Models\TenantKey;
use App\Models\User;
use App\Models\UserInternalOrganizationalScope;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * @property TenantKey $tenant
 * @property User $adminUser
 * @property User $regularUser
 * @property User $targetUser
 * @property OrganizationalUnit $holding
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

    $this->adminUser = User::factory()->create();
    $this->regularUser = User::factory()->create();
    $this->targetUser = User::factory()->create();

    // Create organizational hierarchy: Holding -> Company -> Region -> Branch
    $this->holding = OrganizationalUnit::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Test Holding',
        'type' => 'holding',
    ]);

    $this->company = OrganizationalUnit::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Test Company',
        'type' => 'company',
    ]);
    $this->company->setParent($this->holding);

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

    // Give admin user full access to holding (includes all descendants)
    UserInternalOrganizationalScope::create([
        'user_id' => $this->adminUser->id,
        'organizational_unit_id' => $this->holding->id,
        'access_level' => 'admin',
        'include_descendants' => true,
    ]);

    // Give regular user read access only to branch
    UserInternalOrganizationalScope::create([
        'user_id' => $this->regularUser->id,
        'organizational_unit_id' => $this->branch->id,
        'access_level' => 'read',
        'include_descendants' => true,
    ]);
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

describe('OrganizationalScopeController', function () {
    describe('index - GET /organizational-units/{unit}/scopes', function () {
        it('lists scope assignments for a unit when user has admin access', function (): void {
            // Create a scope for target user
            UserInternalOrganizationalScope::create([
                'user_id' => $this->targetUser->id,
                'organizational_unit_id' => $this->company->id,
                'access_level' => 'write',
            ]);

            $this->actingAs($this->adminUser);

            $response = $this->getJson("/v1/organizational-units/{$this->company->id}/scopes");

            $response->assertOk()
                ->assertJsonStructure([
                    'data' => [
                        '*' => [
                            'id',
                            'user_id',
                            'access_level',
                            'include_descendants',
                            'created_at',
                        ],
                    ],
                ]);
        });

        it('denies listing scopes without admin access', function (): void {
            $this->actingAs($this->regularUser);

            $response = $this->getJson("/v1/organizational-units/{$this->branch->id}/scopes");

            // Regular user only has 'read' access, needs 'admin' to manage scopes
            $response->assertForbidden();
        });

        it('requires authentication', function (): void {
            $response = $this->getJson("/v1/organizational-units/{$this->company->id}/scopes");

            $response->assertUnauthorized();
        });
    });

    describe('store - POST /organizational-units/{unit}/scopes', function () {
        it('creates a scope assignment when user has admin access', function (): void {
            $this->actingAs($this->adminUser);

            $response = $this->postJson("/v1/organizational-units/{$this->company->id}/scopes", [
                'user_id' => $this->targetUser->id,
                'access_level' => 'write',
                'include_descendants' => true,
            ]);

            $response->assertCreated()
                ->assertJsonStructure([
                    'data' => [
                        'id',
                        'user_id',
                        'access_level',
                        'include_descendants',
                    ],
                ]);

            // Verify scope was created in database
            $this->assertDatabaseHas('user_internal_organizational_scopes', [
                'user_id' => $this->targetUser->id,
                'organizational_unit_id' => $this->company->id,
                'access_level' => 'write',
            ]);
        });

        it('denies creating scope without admin access', function (): void {
            $this->actingAs($this->regularUser);

            $response = $this->postJson("/v1/organizational-units/{$this->branch->id}/scopes", [
                'user_id' => $this->targetUser->id,
                'access_level' => 'read',
            ]);

            $response->assertForbidden();
        });

        it('validates required fields', function (): void {
            $this->actingAs($this->adminUser);

            $response = $this->postJson("/v1/organizational-units/{$this->company->id}/scopes", []);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors(['user_id', 'access_level']);
        });

        it('validates access level is valid', function (): void {
            $this->actingAs($this->adminUser);

            $response = $this->postJson("/v1/organizational-units/{$this->company->id}/scopes", [
                'user_id' => $this->targetUser->id,
                'access_level' => 'invalid_level',
            ]);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors(['access_level']);
        });

        it('prevents duplicate scope assignments', function (): void {
            // Create existing scope
            UserInternalOrganizationalScope::create([
                'user_id' => $this->targetUser->id,
                'organizational_unit_id' => $this->company->id,
                'access_level' => 'read',
            ]);

            $this->actingAs($this->adminUser);

            $response = $this->postJson("/v1/organizational-units/{$this->company->id}/scopes", [
                'user_id' => $this->targetUser->id,
                'access_level' => 'write',
            ]);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors(['user_id']);
        });
    });

    describe('update - PATCH /organizational-units/{unit}/scopes/{scope}', function () {
        it('updates a scope assignment when user has admin access', function (): void {
            $scope = UserInternalOrganizationalScope::create([
                'user_id' => $this->targetUser->id,
                'organizational_unit_id' => $this->company->id,
                'access_level' => 'read',
                'include_descendants' => false,
            ]);

            $this->actingAs($this->adminUser);

            $response = $this->patchJson("/v1/organizational-units/{$this->company->id}/scopes/{$scope->id}", [
                'access_level' => 'write',
                'include_descendants' => true,
            ]);

            $response->assertOk()
                ->assertJsonPath('data.access_level', 'write')
                ->assertJsonPath('data.include_descendants', true);
        });

        it('denies updating scope without admin access', function (): void {
            $scope = UserInternalOrganizationalScope::create([
                'user_id' => $this->targetUser->id,
                'organizational_unit_id' => $this->branch->id,
                'access_level' => 'read',
            ]);

            $this->actingAs($this->regularUser);

            $response = $this->patchJson("/v1/organizational-units/{$this->branch->id}/scopes/{$scope->id}", [
                'access_level' => 'write',
            ]);

            $response->assertForbidden();
        });

        it('returns 404 for non-existent scope', function (): void {
            $this->actingAs($this->adminUser);

            $response = $this->patchJson("/v1/organizational-units/{$this->company->id}/scopes/00000000-0000-0000-0000-000000000000", [
                'access_level' => 'write',
            ]);

            $response->assertNotFound();
        });

        it('prevents a user from downgrading their own last scope-management access for a unit', function (): void {
            $selfManagingUser = User::factory()->create();
            $scope = UserInternalOrganizationalScope::create([
                'user_id' => $selfManagingUser->id,
                'organizational_unit_id' => $this->company->id,
                'access_level' => 'admin',
                'include_descendants' => false,
            ]);

            $this->actingAs($selfManagingUser);

            $response = $this->patchJson("/v1/organizational-units/{$this->company->id}/scopes/{$scope->id}", [
                'access_level' => 'manage',
            ]);

            $response->assertForbidden()
                ->assertJsonPath('message', 'You cannot remove your own last scope-management access for this organizational unit.');

            $this->assertDatabaseHas('user_internal_organizational_scopes', [
                'id' => $scope->id,
                'access_level' => 'admin',
            ]);
        });

    });

    describe('destroy - DELETE /organizational-units/{unit}/scopes/{scope}', function () {
        it('deletes a scope assignment when user has admin access', function (): void {
            $scope = UserInternalOrganizationalScope::create([
                'user_id' => $this->targetUser->id,
                'organizational_unit_id' => $this->company->id,
                'access_level' => 'write',
            ]);

            $this->actingAs($this->adminUser);

            $response = $this->deleteJson("/v1/organizational-units/{$this->company->id}/scopes/{$scope->id}");

            $response->assertNoContent();

            $this->assertDatabaseMissing('user_internal_organizational_scopes', [
                'id' => $scope->id,
            ]);
        });

        it('denies deleting scope without admin access', function (): void {
            $scope = UserInternalOrganizationalScope::create([
                'user_id' => $this->targetUser->id,
                'organizational_unit_id' => $this->branch->id,
                'access_level' => 'read',
            ]);

            $this->actingAs($this->regularUser);

            $response = $this->deleteJson("/v1/organizational-units/{$this->branch->id}/scopes/{$scope->id}");

            $response->assertForbidden();
        });

        it('returns 404 for non-existent scope', function (): void {
            $this->actingAs($this->adminUser);

            $response = $this->deleteJson("/v1/organizational-units/{$this->company->id}/scopes/00000000-0000-0000-0000-000000000000");

            $response->assertNotFound();
        });

        it('prevents a user from deleting their own last scope-management access for a unit', function (): void {
            $selfManagingUser = User::factory()->create();
            $scope = UserInternalOrganizationalScope::create([
                'user_id' => $selfManagingUser->id,
                'organizational_unit_id' => $this->company->id,
                'access_level' => 'admin',
                'include_descendants' => false,
            ]);

            $this->actingAs($selfManagingUser);

            $response = $this->deleteJson("/v1/organizational-units/{$this->company->id}/scopes/{$scope->id}");

            $response->assertForbidden()
                ->assertJsonPath('message', 'You cannot remove your own last scope-management access for this organizational unit.');

            $this->assertDatabaseHas('user_internal_organizational_scopes', [
                'id' => $scope->id,
            ]);
        });

        it('allows deleting a self scope when another admin path still exists', function (): void {
            $scope = UserInternalOrganizationalScope::create([
                'user_id' => $this->adminUser->id,
                'organizational_unit_id' => $this->company->id,
                'access_level' => 'admin',
                'include_descendants' => false,
            ]);

            $this->actingAs($this->adminUser);

            $response = $this->deleteJson("/v1/organizational-units/{$this->company->id}/scopes/{$scope->id}");

            $response->assertNoContent();

            $this->assertDatabaseMissing('user_internal_organizational_scopes', [
                'id' => $scope->id,
            ]);

            expect($this->adminUser->fresh()->hasAccessToUnit($this->company, 'admin'))->toBeTrue();
        });
    });

    describe('user scopes - GET /me/organizational-scopes', function () {
        it('returns the authenticated users organizational scopes', function (): void {
            $this->actingAs($this->adminUser);

            $response = $this->getJson('/v1/me/organizational-scopes');

            $response->assertOk()
                ->assertJsonStructure([
                    'data' => [
                        '*' => [
                            'id',
                            'organizational_unit_id',
                            'access_level',
                            'include_descendants',
                            'organizational_unit' => [
                                'id',
                                'name',
                                'type',
                            ],
                        ],
                    ],
                ]);
        });
    });

    describe('rank range validation - Guards/Leadership separation', function () {
        it('rejects scope creation with max>0 and no minimum for viewing ranks', function (): void {
            $this->actingAs($this->adminUser);

            $response = $this->postJson("/v1/organizational-units/{$this->company->id}/scopes", [
                'user_id' => $this->targetUser->id,
                'access_level' => 'read',
                'include_descendants' => true,
                'max_viewable_rank' => 5,
            ]);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors(['max_viewable_rank']);
        });

        it('rejects scope creation with min=0 and max>0 for viewing ranks', function (): void {
            $this->actingAs($this->adminUser);

            $response = $this->postJson("/v1/organizational-units/{$this->company->id}/scopes", [
                'user_id' => $this->targetUser->id,
                'access_level' => 'read',
                'include_descendants' => true,
                'min_viewable_rank' => 0,
                'max_viewable_rank' => 5,
            ]);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors(['min_viewable_rank']);
        });

        it('rejects scope creation with min=0 and max>0 for assignable ranks', function (): void {
            $this->actingAs($this->adminUser);

            $response = $this->postJson("/v1/organizational-units/{$this->company->id}/scopes", [
                'user_id' => $this->targetUser->id,
                'access_level' => 'write',
                'include_descendants' => true,
                'min_assignable_rank' => 0,
                'max_assignable_rank' => 3,
            ]);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors(['min_assignable_rank']);
        });

        it('rejects scope creation with max>0 and no minimum for assignable ranks', function (): void {
            $this->actingAs($this->adminUser);

            $response = $this->postJson("/v1/organizational-units/{$this->company->id}/scopes", [
                'user_id' => $this->targetUser->id,
                'access_level' => 'write',
                'include_descendants' => true,
                'max_assignable_rank' => 3,
            ]);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors(['max_assignable_rank']);
        });

        it('accepts scope creation with min=0 and max=0 for Guards only', function (): void {
            $this->actingAs($this->adminUser);

            $response = $this->postJson("/v1/organizational-units/{$this->company->id}/scopes", [
                'user_id' => $this->targetUser->id,
                'access_level' => 'read',
                'include_descendants' => true,
                'min_viewable_rank' => 0,
                'max_viewable_rank' => 0,
            ]);

            $response->assertCreated()
                ->assertJsonPath('data.min_viewable_rank', 0)
                ->assertJsonPath('data.max_viewable_rank', 0);
        });

        it('accepts scope creation with min=1 and max=5 for Leadership only', function (): void {
            $this->actingAs($this->adminUser);

            $response = $this->postJson("/v1/organizational-units/{$this->company->id}/scopes", [
                'user_id' => $this->targetUser->id,
                'access_level' => 'read',
                'include_descendants' => true,
                'min_viewable_rank' => 1,
                'max_viewable_rank' => 5,
            ]);

            $response->assertCreated()
                ->assertJsonPath('data.min_viewable_rank', 1)
                ->assertJsonPath('data.max_viewable_rank', 5);
        });

        it('rejects scope update with min=0 and max>0 for viewing ranks', function (): void {
            $scope = UserInternalOrganizationalScope::create([
                'user_id' => $this->targetUser->id,
                'organizational_unit_id' => $this->company->id,
                'access_level' => 'read',
                'include_descendants' => false,
            ]);

            $this->actingAs($this->adminUser);

            $response = $this->patchJson("/v1/organizational-units/{$this->company->id}/scopes/{$scope->id}", [
                'min_viewable_rank' => 0,
                'max_viewable_rank' => 10,
            ]);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors(['min_viewable_rank']);
        });

        it('rejects scope update when max_viewable_rank becomes leadership-only over an existing guards-only minimum', function (): void {
            $scope = UserInternalOrganizationalScope::create([
                'user_id' => $this->targetUser->id,
                'organizational_unit_id' => $this->company->id,
                'access_level' => 'read',
                'include_descendants' => false,
                'min_viewable_rank' => 0,
                'max_viewable_rank' => 0,
            ]);

            $this->actingAs($this->adminUser);

            $response = $this->patchJson("/v1/organizational-units/{$this->company->id}/scopes/{$scope->id}", [
                'max_viewable_rank' => 10,
            ]);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors(['max_viewable_rank']);
        });

        it('rejects scope update when min_viewable_rank becomes guards-only over an existing leadership maximum', function (): void {
            $scope = UserInternalOrganizationalScope::create([
                'user_id' => $this->targetUser->id,
                'organizational_unit_id' => $this->company->id,
                'access_level' => 'read',
                'include_descendants' => false,
                'min_viewable_rank' => 1,
                'max_viewable_rank' => 10,
            ]);

            $this->actingAs($this->adminUser);

            $response = $this->patchJson("/v1/organizational-units/{$this->company->id}/scopes/{$scope->id}", [
                'min_viewable_rank' => 0,
            ]);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors(['min_viewable_rank']);
        });

        it('rejects scope update with min=0 and max>0 for assignable ranks', function (): void {
            $scope = UserInternalOrganizationalScope::create([
                'user_id' => $this->targetUser->id,
                'organizational_unit_id' => $this->company->id,
                'access_level' => 'write',
                'include_descendants' => false,
            ]);

            $this->actingAs($this->adminUser);

            $response = $this->patchJson("/v1/organizational-units/{$this->company->id}/scopes/{$scope->id}", [
                'min_assignable_rank' => 0,
                'max_assignable_rank' => 8,
            ]);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors(['min_assignable_rank']);
        });

        it('rejects scope update when max_assignable_rank becomes leadership-only over an existing guards-only minimum', function (): void {
            $scope = UserInternalOrganizationalScope::create([
                'user_id' => $this->targetUser->id,
                'organizational_unit_id' => $this->company->id,
                'access_level' => 'write',
                'include_descendants' => false,
                'min_assignable_rank' => 0,
                'max_assignable_rank' => 0,
            ]);

            $this->actingAs($this->adminUser);

            $response = $this->patchJson("/v1/organizational-units/{$this->company->id}/scopes/{$scope->id}", [
                'max_assignable_rank' => 8,
            ]);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors(['max_assignable_rank']);
        });

        it('rejects scope update when min_assignable_rank becomes guards-only over an existing leadership maximum', function (): void {
            $scope = UserInternalOrganizationalScope::create([
                'user_id' => $this->targetUser->id,
                'organizational_unit_id' => $this->company->id,
                'access_level' => 'write',
                'include_descendants' => false,
                'min_assignable_rank' => 1,
                'max_assignable_rank' => 8,
            ]);

            $this->actingAs($this->adminUser);

            $response = $this->patchJson("/v1/organizational-units/{$this->company->id}/scopes/{$scope->id}", [
                'min_assignable_rank' => 0,
            ]);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors(['min_assignable_rank']);
        });
    });
});

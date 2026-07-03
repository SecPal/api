<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\OrganizationalUnit;
use App\Models\TenantKey;
use App\Models\User;
use App\Models\UserInternalOrganizationalScope;
use App\Services\OrganizationalUnitAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @property TenantKey $tenant
 * @property User $user
 * @property OrganizationalUnitAccessService $service
 */
beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();

    $this->tenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    $this->user = User::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);
    $this->service = app(OrganizationalUnitAccessService::class);
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('organizational unit access service grants a direct manage scope on a newly created child unit', function (): void {
    $parent = OrganizationalUnit::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Parent Unit',
        'type' => 'company',
    ]);

    $child = OrganizationalUnit::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Child Unit',
        'type' => 'department',
    ]);
    $child->setParent($parent);

    UserInternalOrganizationalScope::create([
        'user_id' => $this->user->id,
        'organizational_unit_id' => $parent->id,
        'access_level' => 'manage',
        'include_descendants' => false,
    ]);

    $this->service->grantCreatorManageScopeOnNewChildUnit($this->user, $child);

    $this->assertDatabaseHas('user_internal_organizational_scopes', [
        'user_id' => $this->user->id,
        'organizational_unit_id' => $child->id,
        'access_level' => 'manage',
        'include_descendants' => false,
    ]);
});

test('organizational unit access service preserves prior moved-unit access without inheriting stronger destination-parent privileges', function (): void {
    $sourceRoot = OrganizationalUnit::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Source Root',
        'type' => 'company',
    ]);

    $destinationRoot = OrganizationalUnit::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Destination Root',
        'type' => 'company',
    ]);

    $child = OrganizationalUnit::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Transfer Unit',
        'type' => 'department',
    ]);
    $child->setParent($sourceRoot);

    UserInternalOrganizationalScope::create([
        'user_id' => $this->user->id,
        'organizational_unit_id' => $sourceRoot->id,
        'access_level' => 'write',
        'include_descendants' => true,
    ]);

    UserInternalOrganizationalScope::create([
        'user_id' => $this->user->id,
        'organizational_unit_id' => $destinationRoot->id,
        'access_level' => 'manage',
        'include_descendants' => false,
    ]);

    $this->user->unsetRelation('organizationalScopes');

    expect($this->user->hasAccessToUnit($child, 'write'))->toBeTrue()
        ->and($this->user->hasAccessToUnit($child, 'manage'))->toBeFalse();

    $this->service->reparentUnitForActor($this->user, $child, $destinationRoot);

    $this->assertDatabaseHas('user_internal_organizational_scopes', [
        'user_id' => $this->user->id,
        'organizational_unit_id' => $child->id,
        'access_level' => 'write',
        'include_descendants' => false,
    ]);

    $this->assertDatabaseMissing('user_internal_organizational_scopes', [
        'user_id' => $this->user->id,
        'organizational_unit_id' => $child->id,
        'access_level' => 'manage',
    ]);

    $this->user->refresh();
    $child->refresh();

    expect($this->user->hasAccessToUnit($child, 'write'))->toBeTrue()
        ->and($this->user->hasAccessToUnit($child, 'manage'))->toBeFalse();
});

test('organizational unit access service pins prior moved-unit access when destination inheritance would escalate it', function (): void {
    $sourceRoot = OrganizationalUnit::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Source Root',
        'type' => 'company',
    ]);

    $destinationRoot = OrganizationalUnit::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Destination Root',
        'type' => 'company',
    ]);

    $child = OrganizationalUnit::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Transfer Unit',
        'type' => 'department',
    ]);
    $child->setParent($sourceRoot);

    UserInternalOrganizationalScope::create([
        'user_id' => $this->user->id,
        'organizational_unit_id' => $sourceRoot->id,
        'access_level' => 'write',
        'include_descendants' => true,
    ]);

    UserInternalOrganizationalScope::create([
        'user_id' => $this->user->id,
        'organizational_unit_id' => $destinationRoot->id,
        'access_level' => 'manage',
        'include_descendants' => true,
    ]);

    $this->user->unsetRelation('organizationalScopes');

    expect($this->user->hasAccessToUnit($child, 'write'))->toBeTrue()
        ->and($this->user->hasAccessToUnit($child, 'manage'))->toBeFalse();

    $this->service->reparentUnitForActor($this->user, $child, $destinationRoot);

    $this->assertDatabaseHas('user_internal_organizational_scopes', [
        'user_id' => $this->user->id,
        'organizational_unit_id' => $child->id,
        'access_level' => 'write',
        'include_descendants' => false,
    ]);

    $this->user->refresh();
    $child->refresh();

    expect($this->user->hasAccessToUnit($child, 'write'))->toBeTrue()
        ->and($this->user->hasAccessToUnit($child, 'manage'))->toBeFalse();
});

test('organizational unit access service pins prior moved-unit access when destination inheritance would downgrade it', function (): void {
    $sourceRoot = OrganizationalUnit::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Source Root',
        'type' => 'company',
    ]);

    $destinationRoot = OrganizationalUnit::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Destination Root',
        'type' => 'company',
    ]);

    $child = OrganizationalUnit::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Transfer Unit',
        'type' => 'department',
    ]);
    $child->setParent($sourceRoot);

    UserInternalOrganizationalScope::create([
        'user_id' => $this->user->id,
        'organizational_unit_id' => $sourceRoot->id,
        'access_level' => 'write',
        'include_descendants' => true,
    ]);

    UserInternalOrganizationalScope::create([
        'user_id' => $this->user->id,
        'organizational_unit_id' => $destinationRoot->id,
        'access_level' => 'read',
        'include_descendants' => true,
    ]);

    $this->user->unsetRelation('organizationalScopes');

    expect($this->user->hasAccessToUnit($child, 'write'))->toBeTrue();

    $this->service->reparentUnitForActor($this->user, $child, $destinationRoot);

    $this->assertDatabaseHas('user_internal_organizational_scopes', [
        'user_id' => $this->user->id,
        'organizational_unit_id' => $child->id,
        'access_level' => 'write',
        'include_descendants' => false,
    ]);

    $this->user->refresh();
    $child->refresh();

    expect($this->user->hasAccessToUnit($child, 'write'))->toBeTrue();
});

test('organizational unit access service preserves prior moved-unit rank limits when pinning a direct replacement scope', function (): void {
    $sourceRoot = OrganizationalUnit::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Source Root',
        'type' => 'company',
    ]);

    $destinationRoot = OrganizationalUnit::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Destination Root',
        'type' => 'company',
    ]);

    $child = OrganizationalUnit::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Transfer Unit',
        'type' => 'department',
    ]);
    $child->setParent($sourceRoot);

    UserInternalOrganizationalScope::create([
        'user_id' => $this->user->id,
        'organizational_unit_id' => $sourceRoot->id,
        'access_level' => 'write',
        'include_descendants' => true,
        'min_viewable_rank' => 1,
        'max_viewable_rank' => 3,
        'min_assignable_rank' => 1,
        'max_assignable_rank' => 2,
        'allow_self_access' => true,
    ]);

    UserInternalOrganizationalScope::create([
        'user_id' => $this->user->id,
        'organizational_unit_id' => $destinationRoot->id,
        'access_level' => 'manage',
        'include_descendants' => true,
    ]);

    $this->service->reparentUnitForActor($this->user, $child, $destinationRoot);

    $this->assertDatabaseHas('user_internal_organizational_scopes', [
        'user_id' => $this->user->id,
        'organizational_unit_id' => $child->id,
        'access_level' => 'write',
        'include_descendants' => false,
        'min_viewable_rank' => 1,
        'max_viewable_rank' => 3,
        'min_assignable_rank' => 1,
        'max_assignable_rank' => 2,
        'allow_self_access' => true,
    ]);
});

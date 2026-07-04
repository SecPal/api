<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

use App\Models\Qualification;
use App\Models\TenantKey;
use App\Models\User;
use App\Policies\QualificationPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;

/**
 * @property TenantKey $tenant
 * @property PermissionRegistrar $registrar
 * @property QualificationPolicy $policy
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    $this->registrar = app(PermissionRegistrar::class);
    $this->registrar->setPermissionsTeamId($this->tenant->id);

    Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    $this->policy = new QualificationPolicy;
});

afterEach(function (): void {
    $this->registrar->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('users with qualification.read can view any qualifications', function (): void {
    $userWithPermission = User::factory()->create();
    givePermissionWithTenant($userWithPermission, $this->tenant->id, 'qualification.read');

    expect($this->policy->viewAny($userWithPermission))->toBeTrue();
});

test('users without qualification.read cannot view qualifications', function (): void {
    $userWithoutPermission = User::factory()->create();

    expect($this->policy->viewAny($userWithoutPermission))->toBeFalse();
});

test('users with qualification.read can view individual qualifications', function (): void {
    $userWithPermission = User::factory()->create();
    givePermissionWithTenant($userWithPermission, $this->tenant->id, 'qualification.read');
    $qualification = Qualification::factory()->create(['is_system_qualification' => false]);

    expect($this->policy->view($userWithPermission, $qualification))->toBeTrue();
});

test('users without qualification.read cannot view individual qualifications', function (): void {
    $userWithoutPermission = User::factory()->create();
    $qualification = Qualification::factory()->create(['is_system_qualification' => false]);

    expect($this->policy->view($userWithoutPermission, $qualification))->toBeFalse();
});

test('users with qualification.write can create qualifications', function (): void {
    $userWithPermission = User::factory()->create();
    givePermissionWithTenant($userWithPermission, $this->tenant->id, 'qualification.write');

    expect($this->policy->create($userWithPermission))->toBeTrue();
});

test('users without qualification.write cannot create qualifications', function (): void {
    $userWithoutPermission = User::factory()->create();

    expect($this->policy->create($userWithoutPermission))->toBeFalse();
});

test('users with qualification.write can update custom qualifications', function (): void {
    $userWithPermission = User::factory()->create();
    givePermissionWithTenant($userWithPermission, $this->tenant->id, 'qualification.write');
    $qualification = Qualification::factory()->create(['is_system_qualification' => false]);

    expect($this->policy->update($userWithPermission, $qualification))->toBeTrue();
});

test('users with qualification.write cannot update system qualifications', function (): void {
    $userWithPermission = User::factory()->create();
    givePermissionWithTenant($userWithPermission, $this->tenant->id, 'qualification.write');
    $qualification = Qualification::factory()->create(['is_system_qualification' => true]);

    expect($this->policy->update($userWithPermission, $qualification))->toBeFalse();
});

test('users without qualification.write cannot update any qualifications', function (): void {
    $userWithoutPermission = User::factory()->create();
    $qualification = Qualification::factory()->create(['is_system_qualification' => false]);

    expect($this->policy->update($userWithoutPermission, $qualification))->toBeFalse();
});

test('users with qualification.write can delete custom qualifications', function (): void {
    $userWithPermission = User::factory()->create();
    givePermissionWithTenant($userWithPermission, $this->tenant->id, 'qualification.write');
    $qualification = Qualification::factory()->create(['is_system_qualification' => false]);

    expect($this->policy->delete($userWithPermission, $qualification))->toBeTrue();
});

test('users with qualification.write cannot delete system qualifications', function (): void {
    $userWithPermission = User::factory()->create();
    givePermissionWithTenant($userWithPermission, $this->tenant->id, 'qualification.write');
    $qualification = Qualification::factory()->create(['is_system_qualification' => true]);

    expect($this->policy->delete($userWithPermission, $qualification))->toBeFalse();
});

test('users without qualification.write cannot delete any qualifications', function (): void {
    $userWithoutPermission = User::factory()->create();
    $qualification = Qualification::factory()->create(['is_system_qualification' => false]);

    expect($this->policy->delete($userWithoutPermission, $qualification))->toBeFalse();
});

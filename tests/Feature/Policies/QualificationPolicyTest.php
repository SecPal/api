<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Qualification;
use App\Models\TenantKey;
use App\Models\User;
use App\Policies\QualificationPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
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

test('admin can view any qualifications', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    expect($this->policy->viewAny($admin))->toBeTrue();
});

test('manager can view any qualifications', function (): void {
    $manager = User::factory()->create();
    $manager->assignRole('Manager');

    expect($this->policy->viewAny($manager))->toBeTrue();
});

test('regular employee can view qualifications', function (): void {
    $employee = User::factory()->create();

    expect($this->policy->viewAny($employee))->toBeTrue();
});

test('everyone can view individual qualifications', function (): void {
    $user = User::factory()->create();
    $qualification = Qualification::factory()->create(['is_system' => false]);

    expect($this->policy->view($user, $qualification))->toBeTrue();
});

test('only admin can create qualifications', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $manager = User::factory()->create();
    $manager->assignRole('Manager');

    $employee = User::factory()->create();

    expect($this->policy->create($admin))->toBeTrue();
    expect($this->policy->create($manager))->toBeFalse();
    expect($this->policy->create($employee))->toBeFalse();
});

test('admin can update custom qualifications', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');
    $qualification = Qualification::factory()->create(['is_system' => false]);

    expect($this->policy->update($admin, $qualification))->toBeTrue();
});

test('admin cannot update system qualifications', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');
    $qualification = Qualification::factory()->create(['is_system' => true]);

    expect($this->policy->update($admin, $qualification))->toBeFalse();
});

test('manager cannot update any qualifications', function (): void {
    $manager = User::factory()->create();
    $manager->assignRole('Manager');
    $qualification = Qualification::factory()->create(['is_system' => false]);

    expect($this->policy->update($manager, $qualification))->toBeFalse();
});

test('admin can delete custom qualifications', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');
    $qualification = Qualification::factory()->create(['is_system' => false]);

    expect($this->policy->delete($admin, $qualification))->toBeTrue();
});

test('admin cannot delete system qualifications', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');
    $qualification = Qualification::factory()->create(['is_system' => true]);

    expect($this->policy->delete($admin, $qualification))->toBeFalse();
});

test('manager cannot delete any qualifications', function (): void {
    $manager = User::factory()->create();
    $manager->assignRole('Manager');
    $qualification = Qualification::factory()->create(['is_system' => false]);

    expect($this->policy->delete($manager, $qualification))->toBeFalse();
});

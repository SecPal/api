<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\OnboardingFormTemplate;
use App\Models\TenantKey;
use App\Models\User;
use App\Policies\OnboardingFormTemplatePolicy;
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

    $this->policy = new OnboardingFormTemplatePolicy;
});

afterEach(function (): void {
    $this->registrar->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('admin can view any onboarding form templates', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    expect($this->policy->viewAny($admin))->toBeTrue();
});

test('manager can view any onboarding form templates', function (): void {
    $manager = User::factory()->create();
    $manager->assignRole('Manager');

    expect($this->policy->viewAny($manager))->toBeTrue();
});

test('regular employee cannot view templates', function (): void {
    $employee = User::factory()->create();

    expect($this->policy->viewAny($employee))->toBeFalse();
});

test('admin and managers can view individual templates', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $manager = User::factory()->create();
    $manager->assignRole('Manager');

    $template = OnboardingFormTemplate::factory()->create(['is_system_template' => false]);

    expect($this->policy->view($admin, $template))->toBeTrue();
    expect($this->policy->view($manager, $template))->toBeTrue();
});

test('regular employee cannot view individual templates', function (): void {
    $employee = User::factory()->create();
    $template = OnboardingFormTemplate::factory()->create(['is_system_template' => false]);

    expect($this->policy->view($employee, $template))->toBeFalse();
});

test('only admin can create onboarding form templates', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $manager = User::factory()->create();
    $manager->assignRole('Manager');

    $employee = User::factory()->create();

    expect($this->policy->create($admin))->toBeTrue();
    expect($this->policy->create($manager))->toBeFalse();
    expect($this->policy->create($employee))->toBeFalse();
});

test('admin can update custom templates', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');
    $template = OnboardingFormTemplate::factory()->create(['is_system_template' => false]);

    expect($this->policy->update($admin, $template))->toBeTrue();
});

test('admin cannot update system templates', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');
    $template = OnboardingFormTemplate::factory()->create(['is_system_template' => true]);

    expect($this->policy->update($admin, $template))->toBeFalse();
});

test('manager cannot update any templates', function (): void {
    $manager = User::factory()->create();
    $manager->assignRole('Manager');
    $template = OnboardingFormTemplate::factory()->create(['is_system_template' => false]);

    expect($this->policy->update($manager, $template))->toBeFalse();
});

test('admin can delete custom templates', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');
    $template = OnboardingFormTemplate::factory()->create(['is_system_template' => false]);

    expect($this->policy->delete($admin, $template))->toBeTrue();
});

test('admin cannot delete system templates', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');
    $template = OnboardingFormTemplate::factory()->create(['is_system_template' => true]);

    expect($this->policy->delete($admin, $template))->toBeFalse();
});

test('manager cannot delete any templates', function (): void {
    $manager = User::factory()->create();
    $manager->assignRole('Manager');
    $template = OnboardingFormTemplate::factory()->create(['is_system_template' => false]);

    expect($this->policy->delete($manager, $template))->toBeFalse();
});

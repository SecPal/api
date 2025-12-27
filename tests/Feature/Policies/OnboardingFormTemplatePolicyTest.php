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

/**
 * @property TenantKey $tenant
 * @property PermissionRegistrar $registrar
 * @property OnboardingFormTemplatePolicy $policy
 */

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

test('users with onboarding.read can view any templates', function (): void {
    $userWithPermission = User::factory()->create();
    givePermissionWithTenant($userWithPermission, $this->tenant->id, 'onboarding.read');

    $userWithoutPermission = User::factory()->create();

    expect($this->policy->viewAny($userWithPermission))->toBeTrue();
    expect($this->policy->viewAny($userWithoutPermission))->toBeFalse();
});

test('users with onboarding.read can view individual templates', function (): void {
    $userWithPermission = User::factory()->create();
    givePermissionWithTenant($userWithPermission, $this->tenant->id, 'onboarding.read');

    $userWithoutPermission = User::factory()->create();
    $template = OnboardingFormTemplate::factory()->create(['is_system_template' => false]);

    expect($this->policy->view($userWithPermission, $template))->toBeTrue();
    expect($this->policy->view($userWithoutPermission, $template))->toBeFalse();
});

test('users with onboarding_template.write can create templates', function (): void {
    $userWithWrite = User::factory()->create();
    givePermissionWithTenant($userWithWrite, $this->tenant->id, 'onboarding_template.write');

    $userWithCreate = User::factory()->create();
    givePermissionWithTenant($userWithCreate, $this->tenant->id, 'onboarding_template.create');

    $userWithoutPermission = User::factory()->create();

    expect($this->policy->create($userWithWrite))->toBeTrue();
    expect($this->policy->create($userWithCreate))->toBeTrue();
    expect($this->policy->create($userWithoutPermission))->toBeFalse();
});

test('users with onboarding_template.write can update custom templates', function (): void {
    $userWithPermission = User::factory()->create();
    givePermissionWithTenant($userWithPermission, $this->tenant->id, 'onboarding_template.write');

    $template = OnboardingFormTemplate::factory()->create(['is_system_template' => false]);

    expect($this->policy->update($userWithPermission, $template))->toBeTrue();
});

test('no one can update system templates', function (): void {
    $userWithPermission = User::factory()->create();
    givePermissionWithTenant($userWithPermission, $this->tenant->id, 'onboarding_template.write');

    $template = OnboardingFormTemplate::factory()->create(['is_system_template' => true]);

    expect($this->policy->update($userWithPermission, $template))->toBeFalse();
});

test('users without onboarding_template.write cannot update templates', function (): void {
    $userWithoutPermission = User::factory()->create();
    $template = OnboardingFormTemplate::factory()->create(['is_system_template' => false]);

    expect($this->policy->update($userWithoutPermission, $template))->toBeFalse();
});

test('users with onboarding_template.write can delete custom templates', function (): void {
    $userWithPermission = User::factory()->create();
    givePermissionWithTenant($userWithPermission, $this->tenant->id, 'onboarding_template.write');

    $template = OnboardingFormTemplate::factory()->create(['is_system_template' => false]);

    expect($this->policy->delete($userWithPermission, $template))->toBeTrue();
});

test('no one can delete system templates', function (): void {
    $userWithPermission = User::factory()->create();
    givePermissionWithTenant($userWithPermission, $this->tenant->id, 'onboarding_template.write');

    $template = OnboardingFormTemplate::factory()->create(['is_system_template' => true]);

    expect($this->policy->delete($userWithPermission, $template))->toBeFalse();
});

test('users without onboarding_template.write cannot delete templates', function (): void {
    $userWithoutPermission = User::factory()->create();
    $template = OnboardingFormTemplate::factory()->create(['is_system_template' => false]);

    expect($this->policy->delete($userWithoutPermission, $template))->toBeFalse();
});

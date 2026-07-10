<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

use App\Models\Employee;
use App\Models\OrganizationalUnit;
use App\Models\TenantKey;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\OnboardingDemoUserSeeder;
use Database\Seeders\OrganizationalUnitSeeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

use function Pest\Laravel\artisan;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('OrganizationalUnitSeeder marks SecPal Holding as a legal entity and establishment', function (): void {
    artisan('db:seed', ['--class' => OrganizationalUnitSeeder::class]);

    $holding = OrganizationalUnit::query()
        ->where('name', 'SecPal Holding')
        ->firstOrFail();

    expect($holding->is_legal_entity)->toBeTrue()
        ->and($holding->is_establishment)->toBeTrue();
});

test('OrganizationalUnitSeeder repairs status flags on an existing SecPal Holding', function (): void {
    artisan('db:seed', ['--class' => OrganizationalUnitSeeder::class]);

    $holding = OrganizationalUnit::query()
        ->where('name', 'SecPal Holding')
        ->firstOrFail();

    $holding->update([
        'is_legal_entity' => false,
        'is_establishment' => false,
    ]);

    artisan('db:seed', ['--class' => OrganizationalUnitSeeder::class]);

    $holding->refresh();

    expect($holding->is_legal_entity)->toBeTrue()
        ->and($holding->is_establishment)->toBeTrue();
});

test('OnboardingDemoUserSeeder creates pre-contract employee at SecPal Holding', function (): void {
    artisan('db:seed', ['--class' => OrganizationalUnitSeeder::class]);
    artisan('db:seed', ['--class' => OnboardingDemoUserSeeder::class]);

    $user = User::query()->where('email', 'onboarding@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('John Doe')
        ->and(Hash::check('password', (string) $user->password))->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull();

    $employee = Employee::query()->where('email', 'onboarding@example.com')->first();
    expect($employee)->not->toBeNull()
        ->and($employee->status)->toBe(Employee::STATUS_PRE_CONTRACT)
        ->and($employee->position)->toBe('Sicherheitsmitarbeiter')
        ->and($employee->management_level)->toBe(0)
        ->and((string) $employee->hire_date)->toContain('2028-05-01')
        ->and((string) $employee->contract_start_date)->toContain('2028-05-01')
        ->and((string) $employee->date_of_birth)->toContain('1990-01-01')
        ->and($employee->user_id)->toBe($user->id)
        ->and($employee->organizationalUnit?->name)->toBe('SecPal Holding')
        ->and($employee->organizationalUnit?->type)->toBe('holding');
});

test('OnboardingDemoUserSeeder is idempotent', function (): void {
    artisan('db:seed', ['--class' => OrganizationalUnitSeeder::class]);
    artisan('db:seed', ['--class' => OnboardingDemoUserSeeder::class]);
    artisan('db:seed', ['--class' => OnboardingDemoUserSeeder::class]);

    expect(Employee::query()->where('email', 'onboarding@example.com')->count())->toBe(1)
        ->and(User::query()->where('email', 'onboarding@example.com')->count())->toBe(1);
});

test('DatabaseSeeder keeps child seeders inside WithoutModelEvents by default', function (): void {
    expect(class_uses_recursive(DatabaseSeeder::class))->toContain(WithoutModelEvents::class);
});

test('OnboardingDemoUserSeeder restores model events when invoked from an eventless seeder', function (): void {
    artisan('db:seed', ['--class' => OrganizationalUnitSeeder::class]);

    Model::withoutEvents(function (): void {
        artisan('db:seed', ['--class' => OnboardingDemoUserSeeder::class]);
    });

    $employee = Employee::query()
        ->where('email', 'onboarding@example.com')
        ->firstOrFail();

    expect($employee->first_name_idx)->not->toBeNull()
        ->and($employee->last_name_idx)->not->toBeNull()
        ->and($employee->date_of_birth_idx)->not->toBeNull();
});

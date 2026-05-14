<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Employee;
use App\Models\TenantKey;
use App\Models\User;
use Database\Seeders\OnboardingDemoUserSeeder;
use Database\Seeders\OrganizationalUnitSeeder;
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

<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

use App\Models\Employee;
use App\Models\EmployeeAddress;
use App\Models\OrganizationalUnit;
use App\Models\TenantKey;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('unit', 'models', 'employee_address');

beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('employee factory seeds exactly one current address by default', function (): void {
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
        ])->id,
    ]);

    $employee->load('addresses');
    expect($employee->addresses)->toHaveCount(1)
        ->and($employee->addresses->first()->resided_until)->toBeNull()
        ->and($employee->currentAddress())->not->toBeNull();
});

test('can persist a historical address row with resided_until set', function (): void {
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
        ])->id,
    ]);

    $employee->addresses()->delete();

    EmployeeAddress::factory()->create([
        'employee_id' => $employee->id,
        'tenant_id' => $employee->tenant_id,
        'street' => 'Alte Gasse',
        'city' => 'Hamburg',
        'postal_code' => '20095',
        'country' => 'DE',
        'resided_from' => '2018-01-01',
        'resided_until' => '2020-12-31',
    ]);

    EmployeeAddress::factory()->current()->create([
        'employee_id' => $employee->id,
        'tenant_id' => $employee->tenant_id,
        'street' => 'Neue Straße',
        'city' => 'Berlin',
        'postal_code' => '10115',
        'country' => 'DE',
    ]);

    $employee->refresh();
    $employee->load('addresses');
    expect($employee->addresses)->toHaveCount(2)
        ->and($employee->currentAddress()?->street)->toBe('Neue Straße');
});

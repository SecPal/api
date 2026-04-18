<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Employee;
use App\Models\OrganizationalUnit;
use App\Models\TenantKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

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

test('employees table scopes employee number uniqueness to tenant_id', function (): void {
    $indexes = Schema::getIndexes('employees');

    $hasTenantScopedUniqueConstraint = collect($indexes)->contains(function (array $index): bool {
        return $index['name'] === 'unique_tenant_employee_number'
            && $index['unique'] === true
            && $index['columns'] === ['tenant_id', 'employee_number'];
    });

    $hasGlobalEmployeeNumberUniqueConstraint = collect($indexes)->contains(function (array $index): bool {
        return $index['unique'] === true
            && $index['columns'] === ['employee_number'];
    });

    expect($hasTenantScopedUniqueConstraint)->toBeTrue()
        ->and($hasGlobalEmployeeNumberUniqueConstraint)->toBeFalse();
});

test('employees table allows duplicate employee numbers across tenants', function (): void {
    $tenantOne = TenantKey::create(TenantKey::generateEnvelopeKeys());
    $tenantTwo = TenantKey::create(TenantKey::generateEnvelopeKeys());

    $unitOne = OrganizationalUnit::factory()->create([
        'tenant_id' => $tenantOne->id,
    ]);
    $unitTwo = OrganizationalUnit::factory()->create([
        'tenant_id' => $tenantTwo->id,
    ]);

    Employee::factory()->create([
        'tenant_id' => $tenantOne->id,
        'organizational_unit_id' => $unitOne->id,
        'employee_number' => 'EMP-2026-0001',
        'email' => 'tenant-one@example.com',
    ]);

    expect(function () use ($tenantTwo, $unitTwo): void {
        Employee::factory()->create([
            'tenant_id' => $tenantTwo->id,
            'organizational_unit_id' => $unitTwo->id,
            'employee_number' => 'EMP-2026-0001',
            'email' => 'tenant-two@example.com',
        ]);
    })->not->toThrow(Exception::class);

    expect(Employee::query()->where('employee_number', 'EMP-2026-0001')->count())->toBe(2);
});

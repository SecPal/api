<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Employee;
use App\Models\OrganizationalUnit;
use App\Models\TenantKey;
use Illuminate\Database\QueryException;
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
        $columns = $index['columns'] ?? [];

        return $index['name'] === 'unique_tenant_employee_number'
            && $index['unique'] === true
            && count($columns) === 2
            && in_array('tenant_id', $columns, true)
            && in_array('employee_number', $columns, true);
    });

    $hasGlobalEmployeeNumberUniqueConstraint = collect($indexes)->contains(function (array $index): bool {
        $columns = $index['columns'] ?? [];

        return $index['unique'] === true
            && count($columns) === 1
            && in_array('employee_number', $columns, true);
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

test('employees table rejects duplicate employee numbers within the same tenant', function (): void {
    $tenant = TenantKey::create(TenantKey::generateEnvelopeKeys());

    $unit = OrganizationalUnit::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'organizational_unit_id' => $unit->id,
        'employee_number' => 'EMP-2026-0001',
        'email' => 'employee-one@example.com',
    ]);

    try {
        Employee::factory()->create([
            'tenant_id' => $tenant->id,
            'organizational_unit_id' => $unit->id,
            'employee_number' => 'EMP-2026-0001',
            'email' => 'employee-two@example.com',
        ]);
    } catch (QueryException $exception) {
        expect($exception->getCode())->toBe('23505')
            ->and($exception->getMessage())->toContain('unique_tenant_employee_number');

        return;
    }

    expect()->fail('Expected a tenant-scoped employee number unique constraint violation.');
});

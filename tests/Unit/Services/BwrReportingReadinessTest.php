<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\Employee;
use App\Models\EmployeeAddress;
use App\Models\Tenant;
use App\Models\TenantKey;
use App\Services\BwrReportingReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $this->tenant = Tenant::factory()->create();

    $this->service = app(BwrReportingReadiness::class);
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

function createBwrReportingReadyEmployee(Tenant $tenant, array $overrides = []): Employee
{
    $employee = Employee::factory()->create(array_merge([
        'tenant_id' => $tenant->id,
        'first_name' => 'Taylor',
        'last_name' => 'Export',
        'date_of_birth' => '1990-01-15',
        'gender' => 'diverse',
        'birth_name' => 'Taylor Birthname',
        'previous_names' => ['Taylor Previous'],
        'birth_city' => 'Berlin',
        'birth_country' => 'DE',
        'nationalities' => ['DE'],
        'email' => 'taylor.export@example.com',
        'intended_activities' => ['object_protection', 'event_security'],
        'id_document_type' => 'id_card',
        'id_document_number' => 'L01X00T47',
        'id_document_expiry' => now()->addYear()->toDateString(),
        'sachkunde_type' => '34a_new',
        'sachkunde_certificate' => 'IHK-123456',
        'bwr_status' => 'not_registered',
        'status' => Employee::STATUS_PRE_CONTRACT,
        'position' => 'Security Guard',
        'contract_type' => 'full_time',
        'contract_start_date' => now()->toDateString(),
        'management_level' => 0,
        'work_permit_type' => 'none',
    ], $overrides));

    $employee->addresses()->delete();

    EmployeeAddress::factory()->create([
        'employee_id' => $employee->id,
        'tenant_id' => $tenant->id,
        'street' => 'Altstrasse',
        'house_number' => '5',
        'postal_code' => '20095',
        'city' => 'Hamburg',
        'country' => 'DE',
        'resided_from' => '2021-01-01',
        'resided_until' => '2023-12-31',
    ]);

    EmployeeAddress::factory()->current()->create([
        'employee_id' => $employee->id,
        'tenant_id' => $tenant->id,
        'street' => 'Hauptstrasse',
        'house_number' => '42A',
        'postal_code' => '10115',
        'city' => 'Berlin',
        'country' => 'DE',
        'resided_from' => '2024-01-01',
        'resided_until' => null,
    ]);

    return $employee->fresh(['addresses']);
}

test('accepts an employee ready for manual BWR reporting', function (): void {
    expect($this->service->errors(createBwrReportingReadyEmployee($this->tenant)))->toBe([]);
});

test('reports stable codes for missing BWR fields and current address', function (): void {
    app()->setLocale('de');
    $employee = createBwrReportingReadyEmployee($this->tenant, [
        'gender' => null,
        'id_document_number' => null,
    ]);
    $employee->addresses()->delete();

    expect($this->service->errors($employee->fresh(['addresses'])))
        ->toBe(['gender', 'id_document_number', 'current_address_missing']);
});

test('requires valid work authorization for non exempt nationalities', function (): void {
    $employee = createBwrReportingReadyEmployee($this->tenant, [
        'nationalities' => ['TR'],
        'work_permit_type' => 'none',
        'work_permit_number' => null,
        'work_permit_expiry' => null,
    ]);

    expect($this->service->errors($employee))->toBe(['valid_work_authorization']);
});

test('rejects expired identity documents for a manual report', function (): void {
    $employee = createBwrReportingReadyEmployee($this->tenant, [
        'id_document_expiry' => now()->subDay()->toDateString(),
    ]);

    expect($this->service->errors($employee))->toBe(['id_document_expiry_expired']);
});

test('address continuity check ignores segments entirely before the reporting window', function (): void {
    // The current address covers the full window, so the older gap is irrelevant.
    $windowStart = now()->startOfDay()->subYears(5);

    $employee = createBwrReportingReadyEmployee($this->tenant);
    $employee->addresses()->delete();

    // Historical address that ends well before the 5-year window.
    EmployeeAddress::factory()->create([
        'employee_id' => $employee->id,
        'tenant_id' => $this->tenant->id,
        'street' => 'Altweg',
        'house_number' => '1',
        'postal_code' => '20095',
        'city' => 'Hamburg',
        'country' => 'DE',
        'resided_from' => $windowStart->copy()->subYears(5)->toDateString(),
        'resided_until' => $windowStart->copy()->subYears(1)->toDateString(),
    ]);

    // Current address that starts AFTER the historical one ends (gap of 6 months,
    // entirely before the window start) but covers the full 5-year window.
    EmployeeAddress::factory()->current()->create([
        'employee_id' => $employee->id,
        'tenant_id' => $this->tenant->id,
        'street' => 'Hauptstrasse',
        'house_number' => '42',
        'postal_code' => '10115',
        'city' => 'Berlin',
        'country' => 'DE',
        'resided_from' => $windowStart->copy()->subMonths(6)->toDateString(),
        'resided_until' => null,
    ]);

    $fresh = $employee->fresh(['addresses']);
    // Must succeed — the full reporting window is covered by the current address.
    expect($this->service->errors($fresh))->toBe([]);
});

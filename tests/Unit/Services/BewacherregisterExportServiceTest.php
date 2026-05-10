<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\Employee;
use App\Models\EmployeeAddress;
use App\Models\OrganizationalUnit;
use App\Models\TenantKey;
use App\Services\BewacherregisterExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    $this->organizationalUnit = OrganizationalUnit::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    Storage::fake('local');

    $this->service = app(BewacherregisterExportService::class);
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

function createBewacherregisterReadyEmployee(TenantKey $tenant, OrganizationalUnit $organizationalUnit, array $overrides = []): Employee
{
    $employee = Employee::factory()->create(array_merge([
        'tenant_id' => $tenant->id,
        'organizational_unit_id' => $organizationalUnit->id,
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

test('exports a BWR-ready employee to CSV storage', function (): void {
    $employee = createBewacherregisterReadyEmployee($this->tenant, $this->organizationalUnit);

    $export = $this->service->exportCsv($employee, 'HR Operations');

    expect($export['disk'])->toBe('local')
        ->and($export['file_name'])->toEndWith('.csv')
        ->and($export['path'])->toStartWith('bwr_exports/'.$employee->id.'/');

    Storage::disk('local')->assertExists($export['path']);

    $csv = Storage::disk('local')->get($export['path']);

    expect($csv)->toContain('last_name;first_name;birth_name')
        ->and($csv)->toContain('Export;Taylor;"Taylor Birthname"')
        ->and($csv)->toContain('object_protection, event_security')
        ->and($csv)->toContain('HR Operations')
        ->and($export)->toHaveKey('file_size_bytes')
        ->and($export['file_size_bytes'])->toBe(strlen($csv));
});

test('exports a BWR-ready employee to XML storage', function (): void {
    $employee = createBewacherregisterReadyEmployee($this->tenant, $this->organizationalUnit);

    $export = $this->service->exportXml($employee, 'HR Operations');

    expect($export['disk'])->toBe('local')
        ->and($export['file_name'])->toEndWith('.xml')
        ->and($export['path'])->toStartWith('bwr_exports/'.$employee->id.'/');

    Storage::disk('local')->assertExists($export['path']);

    $xml = Storage::disk('local')->get($export['path']);

    expect($xml)->toContain('<?xml version="1.0" encoding="UTF-8"?>')
        ->and($xml)->toContain('<bewacherregisterExport>')
        ->and($xml)->toContain('<last_name>Export</last_name>')
        ->and($xml)->toContain('<first_name>Taylor</first_name>')
        ->and($xml)->toContain('<exported_by>HR Operations</exported_by>')
        ->and($export)->toHaveKey('file_size_bytes')
        ->and($export['file_size_bytes'])->toBe(strlen($xml));
});

test('export throws when required BWR fields are missing', function (): void {
    $employee = createBewacherregisterReadyEmployee($this->tenant, $this->organizationalUnit);
    $employee->addresses()->delete();
    $employee->forceFill([
        'gender' => null,
        'id_document_number' => null,
    ])->save();

    $expectedMessage = implode(', ', [
        __('bwr_export.missing_fields.gender'),
        __('bwr_export.missing_fields.id_document_number'),
        __('bwr_export.missing_fields.current_address_missing'),
    ]);

    expect(fn () => $this->service->exportCsv($employee->fresh(['addresses']), 'HR Operations'))
        ->toThrow(RuntimeException::class, $expectedMessage);
});

test('export requires valid work authorization for non exempt nationalities', function (): void {
    $employee = createBewacherregisterReadyEmployee($this->tenant, $this->organizationalUnit, [
        'nationalities' => ['TR'],
        'work_permit_type' => 'none',
        'work_permit_number' => null,
        'work_permit_expiry' => null,
    ]);

    expect(fn () => $this->service->exportCsv($employee, 'HR Operations'))
        ->toThrow(RuntimeException::class, __('bwr_export.missing_fields.valid_work_authorization'));
});

test('export preserves seven digit BWR ids including leading zeroes', function (): void {
    $employee = createBewacherregisterReadyEmployee($this->tenant, $this->organizationalUnit, [
        'bwr_id' => '0001234',
    ]);

    $export = $this->service->exportCsv($employee, 'HR Operations');
    $csv = Storage::disk('local')->get($export['path']);

    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $csv);
    rewind($stream);

    $rows = [];

    while (($row = fgetcsv($stream, separator: ';')) !== false) {
        if ($row === [null]) {
            continue;
        }

        $rows[] = $row;
    }

    fclose($stream);

    $header = $rows[0] ?? [];
    $dataRow = $rows[1] ?? [];

    $bwrIdColumnIndex = array_search('bwr_id', $header, true);

    expect($bwrIdColumnIndex)->not->toBeFalse()
        ->and($dataRow[$bwrIdColumnIndex])->toBe('0001234');
});

test('export throws when id_document_expiry is in the past', function (): void {
    $employee = createBewacherregisterReadyEmployee($this->tenant, $this->organizationalUnit, [
        'id_document_expiry' => now()->subDay()->toDateString(),
    ]);

    expect(fn () => $this->service->exportCsv($employee, 'HR Operations'))
        ->toThrow(RuntimeException::class, __('bwr_export.missing_fields.id_document_expiry_expired'));
});

test('address continuity check ignores segments entirely before the export window', function (): void {
    // Employee has two historical address rows with a gap between them, but both rows
    // end before the 5-year export window starts. The current address covers the full
    // window. The algorithm must not report a false-positive gap.
    $windowStart = now()->startOfDay()->subYears(5);

    $employee = createBewacherregisterReadyEmployee($this->tenant, $this->organizationalUnit);
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
    // Must succeed — the full export window is covered by the current address.
    $result = $this->service->exportCsv($fresh, 'HR Operations');
    expect($result)->toHaveKey('path');
});

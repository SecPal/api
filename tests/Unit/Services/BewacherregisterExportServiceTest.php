<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\Employee;
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

function createBwrReadyEmployee(TenantKey $tenant, OrganizationalUnit $organizationalUnit, array $overrides = []): Employee
{
    return Employee::factory()->create(array_merge([
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
        'address_street' => 'Hauptstrasse',
        'address_house_number' => '42A',
        'address_postal_code' => '10115',
        'address_city' => 'Berlin',
        'address_country' => 'DE',
        'address_history' => [[
            'from' => '2021-01-01',
            'to' => '2023-12-31',
            'street' => 'Altstrasse',
            'house_number' => '5',
            'postal_code' => '20095',
            'city' => 'Hamburg',
            'country' => 'DE',
            'state' => null,
        ]],
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
}

test('exports a BWR-ready employee to CSV storage', function (): void {
    $employee = makeBewacherregisterExportReadyEmployee($this->tenant, $this->organizationalUnit);

    $export = $this->service->exportCsv($employee, 'HR Admin');

    expect($export['disk'])->toBe('local')
        ->and($export['file_name'])->toEndWith('.csv')
        ->and($export['path'])->toStartWith('bwr_exports/'.$employee->id.'/');

    Storage::disk('local')->assertExists($export['path']);

    $csv = Storage::disk('local')->get($export['path']);

    expect($csv)->toContain('last_name;first_name;birth_name')
        ->and($csv)->toContain('Export;Taylor;"Taylor Birthname"')
        ->and($csv)->toContain('object_protection, event_security')
        ->and($csv)->toContain('HR Admin')
        ->and($export)->toHaveKey('file_size_bytes')
        ->and($export['file_size_bytes'])->toBe(strlen($csv));
});

test('exports a BWR-ready employee to XML storage', function (): void {
    $employee = makeBewacherregisterExportReadyEmployee($this->tenant, $this->organizationalUnit);

    $export = $this->service->exportXml($employee, 'HR Admin');

    expect($export['disk'])->toBe('local')
        ->and($export['file_name'])->toEndWith('.xml')
        ->and($export['path'])->toStartWith('bwr_exports/'.$employee->id.'/');

    Storage::disk('local')->assertExists($export['path']);

    $xml = Storage::disk('local')->get($export['path']);

    expect($xml)->toContain('<?xml version="1.0" encoding="UTF-8"?>')
        ->and($xml)->toContain('<bewacherregisterExport>')
        ->and($xml)->toContain('<last_name>Export</last_name>')
        ->and($xml)->toContain('<first_name>Taylor</first_name>')
        ->and($xml)->toContain('<exported_by>HR Admin</exported_by>')
        ->and($export)->toHaveKey('file_size_bytes')
        ->and($export['file_size_bytes'])->toBe(strlen($xml));
});

test('export throws when required BWR fields are missing', function (): void {
    $employee = makeBewacherregisterExportReadyEmployee($this->tenant, $this->organizationalUnit, [
        'gender' => null,
        'address_history' => null,
        'id_document_number' => null,
    ]);

    expect(fn () => $this->service->exportCsv($employee, 'HR Admin'))
        ->toThrow(RuntimeException::class, 'gender, address_history, id_document_number');
});

test('export requires valid work authorization for non exempt nationalities', function (): void {
    $employee = makeBewacherregisterExportReadyEmployee($this->tenant, $this->organizationalUnit, [
        'nationalities' => ['TR'],
        'work_permit_type' => 'none',
        'work_permit_number' => null,
        'work_permit_expiry' => null,
    ]);

    expect(fn () => $this->service->exportCsv($employee, 'HR Admin'))
        ->toThrow(RuntimeException::class, 'valid_work_authorization');
});

test('export preserves seven digit BWR ids including leading zeroes', function (): void {
    $employee = makeBewacherregisterExportReadyEmployee($this->tenant, $this->organizationalUnit, [
        'bwr_id' => '0001234',
    ]);

    $export = $this->service->exportCsv($employee, 'HR Admin');
    $csv = Storage::disk('local')->get($export['path']);

    $stream = fopen('php://temp', 'r+');

    expect($stream)->not->toBeFalse();

    fwrite($stream, $csv);
    rewind($stream);

    $rows = [];

    while (($row = fgetcsv($stream, separator: ';')) !== false) {
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
    $employee = makeBewacherregisterExportReadyEmployee($this->tenant, $this->organizationalUnit, [
        'id_document_expiry' => now()->subDay()->toDateString(),
    ]);

    expect(fn () => $this->service->exportCsv($employee, 'HR Admin'))
        ->toThrow(RuntimeException::class, 'id_document_expiry_expired');
});

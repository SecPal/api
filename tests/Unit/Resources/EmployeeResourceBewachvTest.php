<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class)->group('bewachv', 'resource', 'unit');

function employeeResourceRequest(bool $canReadSensitive = false): Request
{
    $request = Request::create('/v1/employees/test', 'GET');

    $request->setUserResolver(static fn (): object => new class($canReadSensitive)
    {
        public function __construct(private readonly bool $canReadSensitive) {}

        public function can(string $permission): bool
        {
            return $permission === 'employees.read_sensitive' && $this->canReadSensitive;
        }
    });

    return $request;
}

test('EmployeeResource includes all BewachV fields', function () {
    $employee = Employee::factory()->withCompleteBewachvData()->create();
    $resource = new EmployeeResource($employee);
    $array = $resource->toArray(employeeResourceRequest(true));

    // BWR tracking
    expect($array)->toHaveKey('bwr_id')
        ->and($array)->toHaveKey('bwr_status')
        ->and($array)->toHaveKey('bwr_registered_at')
        ->and($array)->toHaveKey('bwr_submission_date')
        ->and($array)->toHaveKey('bwr_notes');

    // Retention
    expect($array)->toHaveKey('employment_end_date')
        ->and($array)->toHaveKey('retention_period_end');

    // Identity
    expect($array)->toHaveKey('gender')
        ->and($array)->toHaveKey('birth_name')
        ->and($array)->toHaveKey('previous_names')
        ->and($array)->toHaveKey('birth_city')
        ->and($array)->toHaveKey('birth_country')
        ->and($array)->toHaveKey('birth_state');

    // Nationalities
    expect($array)->toHaveKey('nationalities');

    // Structured address + computed property
    expect($array)->toHaveKey('address_street')
        ->and($array)->toHaveKey('address_house_number')
        ->and($array)->toHaveKey('address_postal_code')
        ->and($array)->toHaveKey('address_city')
        ->and($array)->toHaveKey('address_supplement')
        ->and($array)->toHaveKey('address_country')
        ->and($array)->toHaveKey('address_state')
        ->and($array)->toHaveKey('structured_address');

    // Address history
    expect($array)->toHaveKey('address_history');

    // Intended activities
    expect($array)->toHaveKey('intended_activities');

    // ID document
    expect($array)->toHaveKey('id_document_type')
        ->and($array)->toHaveKey('id_document_number')
        ->and($array)->toHaveKey('id_document_expiry')
        ->and($array)->toHaveKey('id_document_copy_path')
        ->and($array)->toHaveKey('id_document_copy_deleted_at');

    // Sachkunde
    expect($array)->toHaveKey('sachkunde_ihk_number')
        ->and($array)->toHaveKey('sachkunde_exam_date')
        ->and($array)->toHaveKey('sachkunde_issued_date');
});

test('EmployeeResource formats dates consistently', function () {
    $employee = Employee::factory()->withCompleteBewachvData()->create([
        'bwr_registered_at' => '2024-06-15',
        'sachkunde_exam_date' => '2023-11-20',
        'id_document_expiry' => '2030-12-31',
    ]);

    $resource = new EmployeeResource($employee);
    $array = $resource->toArray(employeeResourceRequest(true));

    // Dates should be serialized as strings or Carbon instances
    expect($array['bwr_registered_at'])->not->toBeNull()
        ->and($array['sachkunde_exam_date'])->not->toBeNull()
        ->and($array['id_document_expiry'])->not->toBeNull();
});

test('EmployeeResource handles null BewachV fields gracefully', function () {
    $employee = Employee::factory()->create();

    $resource = new EmployeeResource($employee);
    $array = $resource->toArray(request());

    // Some fields may have default values from factory, check they exist
    expect($array)->toHaveKey('bwr_id')
        ->and($array)->toHaveKey('gender')
        ->and($array)->toHaveKey('birth_country')
        ->and($array)->toHaveKey('nationalities')
        ->and($array)->toHaveKey('address_history');
});

test('EmployeeResource omits regulated identifiers without employees.read_sensitive', function () {
    $employee = Employee::factory()->withCompleteBewachvData()->create();

    $resource = new EmployeeResource($employee);
    $array = $resource->resolve(employeeResourceRequest(false));

    expect($array)->not->toHaveKeys([
        'tax_id',
        'social_security_number',
        'id_document_number',
        'health_insurance_number',
        'work_permit_number',
        'residence_permit_number',
        'sachkunde_ihk_number',
    ]);
});

test('EmployeeResource includes computed structured_address property', function () {
    $employee = Employee::factory()->create([
        'address_street' => 'Hauptstraße',
        'address_house_number' => '42',
        'address_postal_code' => '10115',
        'address_city' => 'Berlin',
        'address_country' => 'DE',
    ]);

    $resource = new EmployeeResource($employee);
    $array = $resource->toArray(request());

    expect($array['structured_address'])->toBeString()
        ->and($array['structured_address'])->toContain('Hauptstraße')
        ->and($array['structured_address'])->toContain('42')
        ->and($array['structured_address'])->toContain('10115')
        ->and($array['structured_address'])->toContain('Berlin')
        ->and($array['structured_address'])->toContain('DE');
});

test('EmployeeResource preserves BWR-ID leading zeros', function () {
    $employee = Employee::factory()->create(['bwr_id' => '0001234']);

    $resource = new EmployeeResource($employee);
    $array = $resource->toArray(request());

    expect($array['bwr_id'])->toBe('0001234')
        ->and(strlen($array['bwr_id']))->toBe(7);
});

test('EmployeeResource does not expose encrypted field names', function () {
    $employee = Employee::factory()->withCompleteBewachvData()->create();
    $resource = new EmployeeResource($employee);
    $array = $resource->toArray(employeeResourceRequest(true));

    // Encrypted fields should not have _enc suffix in API
    expect($array)->not->toHaveKey('birth_name_enc')
        ->and($array)->not->toHaveKey('address_street_enc')
        ->and($array)->not->toHaveKey('id_document_number_enc');

    // But decrypted versions should exist
    expect($array)->toHaveKey('birth_name')
        ->and($array)->toHaveKey('address_street')
        ->and($array)->toHaveKey('id_document_number');
});

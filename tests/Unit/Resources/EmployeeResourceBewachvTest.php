<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Models\EmployeeAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class)->group('bewachv', 'resource', 'unit');

function employeeResourceRequest(bool $canReadSensitive = false, bool $canReadSalary = false): Request
{
    $request = Request::create('/v1/employees/test', 'GET');

    $request->setUserResolver(static fn (): object => new class($canReadSensitive, $canReadSalary)
    {
        public function __construct(
            private readonly bool $canReadSensitive,
            private readonly bool $canReadSalary,
        ) {}

        public function can(string $permission): bool
        {
            return match ($permission) {
                'employees.read_sensitive' => $this->canReadSensitive,
                'employees.read_salary' => $this->canReadSalary,
                default => false,
            };
        }
    });

    return $request;
}

test('EmployeeResource includes all BewachV fields', function () {
    $employee = Employee::factory()->withCompleteBewachvData()->create();
    $employee->load('addresses');
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
        ->and($array)->toHaveKey('birth_country');

    // Nationalities
    expect($array)->toHaveKey('nationalities');

    expect($array)->toHaveKey('addresses')
        ->and($array)->toHaveKey('current_address')
        ->and($array)->toHaveKey('structured_address');

    expect($array)->toHaveKey('emergency_contacts');

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

    // Work permit compliance
    expect($array)->toHaveKey('work_permit_type')
        ->and($array)->toHaveKey('work_permit_number')
        ->and($array)->toHaveKey('work_permit_expiry')
        ->and($array)->toHaveKey('work_permit_issued_by')
        ->and($array)->toHaveKey('work_permit_copy_path')
        ->and($array)->toHaveKey('work_permit_copy_deleted_at')
        ->and($array)->toHaveKey('requires_work_permit')
        ->and($array)->toHaveKey('has_valid_work_authorization')
        ->and($array)->toHaveKey('expiring_documents');

    // Certification tracking
    expect($array)->toHaveKey('firearms_license_number')
        ->and($array)->toHaveKey('firearms_license_expiry')
        ->and($array)->toHaveKey('firearms_license_issued_by')
        ->and($array)->toHaveKey('first_aid_cert_number')
        ->and($array)->toHaveKey('first_aid_cert_date')
        ->and($array)->toHaveKey('first_aid_cert_expiry')
        ->and($array)->toHaveKey('fire_safety_cert_date')
        ->and($array)->toHaveKey('fire_safety_cert_expiry')
        ->and($array)->toHaveKey('evacuation_cert_date')
        ->and($array)->toHaveKey('evacuation_cert_expiry')
        ->and($array)->toHaveKey('additional_certifications');
});

test('EmployeeResource serializes emergency contacts', function () {
    $emergencyContacts = [
        [
            'name' => 'Max Mustermann',
            'relationship' => 'Partner',
            'phone' => '+49 151 1234567',
            'email' => 'max.mustermann@secpal.dev',
            'notes' => 'Primärer Notfallkontakt',
        ],
    ];

    $employee = Employee::factory()->create([
        'emergency_contacts' => $emergencyContacts,
    ]);

    $resource = new EmployeeResource($employee);
    $array = $resource->toArray(employeeResourceRequest(true));

    expect($array['emergency_contacts'])->toBeArray()
        ->and($array['emergency_contacts'])->toEqual($emergencyContacts);
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
    $employee->load('addresses');

    $resource = new EmployeeResource($employee);
    $array = $resource->toArray(request());

    // Some fields may have default values from factory, check they exist
    expect($array)->toHaveKey('bwr_id')
        ->and($array)->toHaveKey('gender')
        ->and($array)->toHaveKey('birth_country')
        ->and($array)->toHaveKey('nationalities')
        ->and($array)->toHaveKey('addresses');
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

test('EmployeeResource omits salary fields without employees.read_salary', function (): void {
    $employee = Employee::factory()->create([
        'hourly_rate' => '24.50',
    ]);

    $resource = new EmployeeResource($employee);
    $array = $resource->resolve(employeeResourceRequest(true, false));

    expect($array)->not->toHaveKey('hourly_rate');
});

test('EmployeeResource includes work authorization compliance context', function () {
    $employee = Employee::factory()->withNonEuWorkPermit()->create([
        'work_permit_expiry' => now()->addDays(5)->toDateString(),
    ]);

    $resource = new EmployeeResource($employee);
    $array = $resource->resolve(employeeResourceRequest(true));

    expect($array['requires_work_permit'])->toBeTrue()
        ->and($array['has_valid_work_authorization'])->toBeTrue()
        ->and($array['work_permit_number'])->toBe($employee->work_permit_number)
        ->and($array['expiring_documents'])->toBeArray()
        ->and(collect($array['expiring_documents'])->pluck('type')->all())->toContain('work_permit');
});

test('EmployeeResource includes certification expiry context', function () {
    $employee = Employee::factory()->withComplianceCertifications()->create([
        'firearms_license_expiry' => now()->addDays(5)->toDateString(),
        'additional_certifications' => [
            [
                'name' => 'Site Access Badge',
                'number' => 'BADGE-11',
                'issued_date' => now()->subMonth()->toDateString(),
                'expiry_date' => now()->addDays(3)->toDateString(),
                'issuer' => 'Customer Security',
            ],
        ],
    ]);

    $resource = new EmployeeResource($employee);
    $array = $resource->resolve(employeeResourceRequest(true));

    expect($array['firearms_license_number'])->toBe($employee->firearms_license_number)
        ->and($array['additional_certifications'])->toBeArray()
        ->and(collect($array['expiring_documents'])->pluck('type')->all())->toContain('firearms_license', 'additional_certification');
});

test('EmployeeResource includes computed structured_address property', function () {
    $employee = Employee::factory()->create();
    $employee->addresses()->delete();

    EmployeeAddress::factory()->current()->create([
        'employee_id' => $employee->id,
        'tenant_id' => $employee->tenant_id,
        'street' => 'Hauptstraße',
        'house_number' => '42',
        'postal_code' => '10115',
        'city' => 'Berlin',
        'country' => 'DE',
    ]);

    $employee->load('addresses');

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
    $employee->load('addresses');
    $resource = new EmployeeResource($employee);
    $array = $resource->toArray(employeeResourceRequest(true));

    // Encrypted fields should not have _enc suffix in API
    expect($array)->not->toHaveKey('birth_name_enc')
        ->and($array)->not->toHaveKey('id_document_number_enc');

    // But decrypted versions should exist
    expect($array)->toHaveKey('birth_name')
        ->and($array)->toHaveKey('addresses')
        ->and($array)->toHaveKey('id_document_number');
});

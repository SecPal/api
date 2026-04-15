<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Models\Employee;
use App\Models\OrganizationalUnit;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class)->group('bewachv', 'validation', 'feature');

beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();

    $this->tenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    $this->user = User::factory()->create();
    $this->organizationalUnit = OrganizationalUnit::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

function validStoreEmployeeData(object $testCase, array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => 'test@example.com',
        'date_of_birth' => '1990-01-01',
        'position' => 'Security Guard',
        'status' => 'active',
        'contract_start_date' => '2025-01-01',
        'contract_type' => 'full_time',
        'organizational_unit_id' => $testCase->organizationalUnit->id,
        'tenant_id' => $testCase->tenant->id,
        'management_level' => 0,
    ], $overrides);
}

function makeStoreEmployeeValidator(object $testCase, array $data): Illuminate\Contracts\Validation\Validator
{
    $request = StoreEmployeeRequest::create('/v1/employees', 'POST', $data);
    $request->setUserResolver(fn (): User => $testCase->user);

    return Validator::make(
        $request->all(),
        $request->rules(),
        $request->messages()
    );
}

function makeUpdateEmployeeValidator(object $testCase, Employee $employee, array $data): Illuminate\Contracts\Validation\Validator
{
    $request = UpdateEmployeeRequest::create("/v1/employees/{$employee->id}", 'PATCH', $data);
    $request->setUserResolver(fn (): User => $testCase->user);
    $request->setRouteResolver(function () use ($employee) {
        return new class($employee)
        {
            public function __construct(private Employee $employee) {}

            public function parameter($key)
            {
                return $key === 'employee' ? $this->employee : null;
            }
        };
    });

    return Validator::make(
        $request->all(),
        $request->rules(),
        $request->messages()
    );
}

test('BWR-ID must be exactly 7 digits', function () {
    $baseData = validStoreEmployeeData($this);

    // Test too short
    $validator = makeStoreEmployeeValidator($this, array_merge($baseData, ['bwr_id' => '12345']));
    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('bwr_id'))->toBeTrue()
        ->and($validator->errors()->first('bwr_id'))->toContain('7 Ziffern');

    // Test too long
    $validator = makeStoreEmployeeValidator($this, array_merge($baseData, ['bwr_id' => '12345678']));
    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('bwr_id'))->toBeTrue();

    // Test with letters
    $validator = makeStoreEmployeeValidator($this, array_merge($baseData, ['bwr_id' => '123456A']));
    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('bwr_id'))->toBeTrue()
        ->and($validator->errors()->first('bwr_id'))->toContain('Ziffern');

    // Test valid with leading zeros
    $validator = makeStoreEmployeeValidator($this, array_merge($baseData, ['bwr_id' => '0001234']));
    expect($validator->passes())->toBeTrue();
});

test('BWR-ID must be unique', function () {
    Employee::factory()->create([
        'bwr_id' => '1234567',
    ]);

    $validator = makeStoreEmployeeValidator($this, validStoreEmployeeData($this, [
        'email' => 'test2@example.com',
        'bwr_id' => '1234567',
    ]));

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('bwr_id'))->toBeTrue()
        ->and($validator->errors()->first('bwr_id'))->toContain('bereits');
});

test('gender is required when BWR status is pending or active', function () {
    $baseData = validStoreEmployeeData($this);

    // Test with pending status - should fail without gender
    $validator = makeStoreEmployeeValidator($this, array_merge($baseData, ['bwr_status' => 'pending']));
    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('gender'))->toBeTrue();

    // Test with active status - should fail without gender
    $validator = makeStoreEmployeeValidator($this, array_merge($baseData, ['bwr_status' => 'active', 'email' => 'test2@example.com']));
    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('gender'))->toBeTrue();

    // Test with not_registered status - should pass without gender
    $validator = makeStoreEmployeeValidator($this, array_merge($baseData, ['bwr_status' => 'not_registered', 'email' => 'test3@example.com']));
    expect($validator->errors()->has('gender'))->toBeFalse();
});

test('structured address fields are required when BWR status is pending or active', function () {
    $baseData = validStoreEmployeeData($this);

    // Test with pending status - should fail without address
    $validator = makeStoreEmployeeValidator($this, array_merge($baseData, ['bwr_status' => 'pending']));
    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('address_street'))->toBeTrue()
        ->and($validator->errors()->has('address_postal_code'))->toBeTrue()
        ->and($validator->errors()->has('address_city'))->toBeTrue();

    // Test with active status - should fail without address
    $validator = makeStoreEmployeeValidator($this, array_merge($baseData, ['bwr_status' => 'active', 'email' => 'test2@example.com']));
    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('address_street'))->toBeTrue()
        ->and($validator->errors()->has('address_postal_code'))->toBeTrue()
        ->and($validator->errors()->has('address_city'))->toBeTrue();

    // Test with not_registered status - should pass without address
    $validator = makeStoreEmployeeValidator($this, array_merge($baseData, ['bwr_status' => 'not_registered', 'email' => 'test3@example.com']));
    expect($validator->errors()->has('address_street'))->toBeFalse()
        ->and($validator->errors()->has('address_postal_code'))->toBeFalse()
        ->and($validator->errors()->has('address_city'))->toBeFalse();
});

test('country and state codes must be ISO 3166-1 alpha-2 format', function () {
    $baseData = validStoreEmployeeData($this);

    // Test birth_country with invalid format
    $validator = makeStoreEmployeeValidator($this, array_merge($baseData, ['birth_country' => 'DEU']));
    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('birth_country'))->toBeTrue()
        ->and($validator->errors()->first('birth_country'))->toContain('2 Buchstaben');

    // Test birth_country with lowercase
    $validator = makeStoreEmployeeValidator($this, array_merge($baseData, ['birth_country' => 'de', 'email' => 'test2@example.com']));
    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('birth_country'))->toBeTrue();

    // Test address_country with valid format
    $validator = makeStoreEmployeeValidator($this, array_merge($baseData, ['address_country' => 'DE', 'email' => 'test3@example.com']));
    expect($validator->errors()->has('address_country'))->toBeFalse();
});

test('nationalities array items must be valid ISO codes', function () {
    $request = new StoreEmployeeRequest;
    $baseData = [
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => 'test@example.com',
    ];

    // Test with invalid nationality code
    $validator = Validator::make(
        array_merge($baseData, ['nationalities' => ['DEU']]),
        $request->rules(),
        $request->messages()
    );
    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('nationalities.0'))->toBeTrue();

    // Test with valid nationality codes
    $validator = Validator::make(
        array_merge($baseData, ['nationalities' => ['DE', 'FR'], 'email' => 'test2@example.com']),
        $request->rules(),
        $request->messages()
    );
    expect($validator->errors()->has('nationalities'))->toBeFalse()
        ->and($validator->errors()->has('nationalities.0'))->toBeFalse()
        ->and($validator->errors()->has('nationalities.1'))->toBeFalse();
});

test('work permit type is required for non exempt nationalities', function () {
    $validator = makeStoreEmployeeValidator($this, validStoreEmployeeData($this, [
        'email' => 'turkish.employee@example.com',
        'nationalities' => ['TR'],
    ]));

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('work_permit_type'))->toBeTrue();
});

test('temporary non exempt work permits require number issuing authority and future expiry', function () {
    $validator = makeStoreEmployeeValidator($this, validStoreEmployeeData($this, [
        'email' => 'temporary.employee@example.com',
        'nationalities' => ['TR'],
        'work_permit_type' => 'temporary',
    ]));

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('work_permit_number'))->toBeTrue()
        ->and($validator->errors()->has('work_permit_issued_by'))->toBeTrue()
        ->and($validator->errors()->has('work_permit_expiry'))->toBeTrue();

    $expiredValidator = makeStoreEmployeeValidator($this, validStoreEmployeeData($this, [
        'email' => 'expired.employee@example.com',
        'nationalities' => ['TR'],
        'work_permit_type' => 'temporary',
        'work_permit_number' => 'WP-123456',
        'work_permit_issued_by' => 'Auslaenderbehoerde Berlin',
        'work_permit_expiry' => now()->subDay()->toDateString(),
    ]));

    expect($expiredValidator->fails())->toBeTrue()
        ->and($expiredValidator->errors()->has('work_permit_expiry'))->toBeTrue();

    $validValidator = makeStoreEmployeeValidator($this, validStoreEmployeeData($this, [
        'email' => 'valid.employee@example.com',
        'nationalities' => ['TR'],
        'work_permit_type' => 'temporary',
        'work_permit_number' => 'WP-654321',
        'work_permit_issued_by' => 'Auslaenderbehoerde Berlin',
        'work_permit_expiry' => now()->addMonths(6)->toDateString(),
    ]));

    expect($validValidator->passes())->toBeTrue();
});

test('permanent non exempt work permits do not require an expiry date', function () {
    $validator = makeStoreEmployeeValidator($this, validStoreEmployeeData($this, [
        'email' => 'permanent.employee@example.com',
        'nationalities' => ['TR'],
        'work_permit_type' => 'permanent',
        'work_permit_number' => 'WP-777777',
        'work_permit_issued_by' => 'Auslaenderbehoerde Berlin',
    ]));

    expect($validator->passes())->toBeTrue();
});

test('eea and swiss nationalities are exempt from work permit requirement', function () {
    $swissValidator = makeStoreEmployeeValidator($this, validStoreEmployeeData($this, [
        'email' => 'swiss.employee@example.com',
        'nationalities' => ['CH'],
    ]));

    $norwegianValidator = makeStoreEmployeeValidator($this, validStoreEmployeeData($this, [
        'email' => 'norwegian.employee@example.com',
        'nationalities' => ['NO'],
    ]));

    expect($swissValidator->errors()->has('work_permit_type'))->toBeFalse()
        ->and($norwegianValidator->errors()->has('work_permit_type'))->toBeFalse();
});

test('UpdateEmployeeRequest enforces work permit rules when patching employee into non exempt nationality', function () {
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->organizationalUnit->id,
        'nationalities' => ['DE'],
        'work_permit_type' => 'none',
    ]);

    $validator = makeUpdateEmployeeValidator($this, $employee, [
        'nationalities' => ['TR'],
    ]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('work_permit_type'))->toBeTrue();

    $validValidator = makeUpdateEmployeeValidator($this, $employee, [
        'nationalities' => ['TR'],
        'work_permit_type' => 'permanent',
        'work_permit_number' => 'WP-987654',
        'work_permit_issued_by' => 'Auslaenderbehoerde Berlin',
    ]);

    expect($validValidator->passes())->toBeTrue();
});

test('UpdateEmployeeRequest rejects direct bwr transition fields', function () {
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->organizationalUnit->id,
        'bwr_status' => 'pending',
    ]);

    $validator = makeUpdateEmployeeValidator($this, $employee, [
        'bwr_status' => 'active',
        'bwr_id' => '1234567',
        'bwr_notes' => 'Attempted bypass',
        'bwr_registered_at' => now()->toDateString(),
    ]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('bwr_status'))->toBeTrue()
        ->and($validator->errors()->first('bwr_status'))->toContain('BWR fields must be changed via the dedicated BWR status endpoint.')
        ->and($validator->errors()->has('bwr_id'))->toBeTrue()
        ->and($validator->errors()->has('bwr_notes'))->toBeTrue()
        ->and($validator->errors()->has('bwr_registered_at'))->toBeTrue();
});

test('certification expiry dates must be after their issue dates', function () {
    $validator = makeStoreEmployeeValidator($this, validStoreEmployeeData($this, [
        'email' => 'certifications@example.com',
        'first_aid_cert_date' => '2026-04-10',
        'first_aid_cert_expiry' => '2026-04-09',
        'fire_safety_cert_date' => '2026-04-10',
        'fire_safety_cert_expiry' => '2026-04-08',
        'evacuation_cert_date' => '2026-04-10',
        'evacuation_cert_expiry' => '2026-04-07',
    ]));

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('first_aid_cert_expiry'))->toBeTrue()
        ->and($validator->errors()->has('fire_safety_cert_expiry'))->toBeTrue()
        ->and($validator->errors()->has('evacuation_cert_expiry'))->toBeTrue();
});

test('additional certifications require structured nested data', function () {
    $validator = makeStoreEmployeeValidator($this, validStoreEmployeeData($this, [
        'email' => 'additional-certs@example.com',
        'additional_certifications' => [
            [
                'name' => 'Site Access Badge',
                'issued_date' => '2026-04-10',
                'expiry_date' => '2026-04-09',
            ],
            [
                'number' => 'MISSING-NAME',
            ],
        ],
    ]));

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('additional_certifications.0.expiry_date'))->toBeTrue()
        ->and($validator->errors()->has('additional_certifications.1.name'))->toBeTrue();

    $validValidator = makeStoreEmployeeValidator($this, validStoreEmployeeData($this, [
        'email' => 'additional-certs-valid@example.com',
        'additional_certifications' => [
            [
                'name' => 'Site Access Badge',
                'number' => 'BADGE-123',
                'issued_date' => '2026-04-01',
                'expiry_date' => '2026-05-01',
                'issuer' => 'Customer Security',
            ],
        ],
    ]));

    expect($validValidator->passes())->toBeTrue();
});

test('update request validates additional certifications and firearms expiry fields', function () {
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->organizationalUnit->id,
    ]);

    $validator = makeUpdateEmployeeValidator($this, $employee, [
        'firearms_license_expiry' => '2026-04-01',
        'additional_certifications' => [
            [
                'name' => 'Weapons Safe Handling',
                'issued_date' => '2026-04-10',
                'expiry_date' => '2026-04-09',
            ],
        ],
    ]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('additional_certifications.0.expiry_date'))->toBeTrue();
});

test('validation messages are in German', function () {
    $request = new StoreEmployeeRequest;

    $validator = Validator::make(
        ['bwr_id' => '123'],
        $request->rules(),
        $request->messages()
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('bwr_id'))->toContain('Bewacher-ID')
        ->and($validator->errors()->first('bwr_id'))->toContain('7 Ziffern');
});

test('UpdateEmployeeRequest uses sometimes validation for PATCH semantics', function () {
    $employee = Employee::factory()->create([
        'bwr_id' => '1234567',
    ]);

    $request = new UpdateEmployeeRequest;
    $request->setRouteResolver(function () use ($employee) {
        return new class($employee)
        {
            public function __construct(private Employee $employee) {}

            public function parameter($key)
            {
                return $key === 'employee' ? $this->employee : null;
            }
        };
    });

    // Test partial update without bwr_id should pass
    $validator = Validator::make(
        ['gender' => 'male'],
        $request->rules(),
        $request->messages()
    );
    expect($validator->passes())->toBeTrue();

    // Test BWR-ID validation still applies when provided
    $validator = Validator::make(
        ['bwr_id' => '123'],
        $request->rules(),
        $request->messages()
    );
    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('bwr_id'))->toBeTrue();
});

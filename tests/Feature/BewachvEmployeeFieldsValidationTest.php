<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class)->group('bewachv', 'validation', 'feature');

test('BWR-ID must be exactly 7 digits', function () {
    $request = new StoreEmployeeRequest;
    $baseData = [
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => 'test@example.com',
        'status' => 'active',
        'contract_type' => 'full_time',
        'management_level' => 0,
    ];

    // Test too short
    $validator = Validator::make(
        array_merge($baseData, ['bwr_id' => '12345']),
        $request->rules(),
        $request->messages()
    );
    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('bwr_id'))->toBeTrue()
        ->and($validator->errors()->first('bwr_id'))->toContain('7 Ziffern');

    // Test too long
    $validator = Validator::make(
        array_merge($baseData, ['bwr_id' => '12345678']),
        $request->rules(),
        $request->messages()
    );
    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('bwr_id'))->toBeTrue();

    // Test with letters
    $validator = Validator::make(
        array_merge($baseData, ['bwr_id' => '123456A']),
        $request->rules(),
        $request->messages()
    );
    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('bwr_id'))->toBeTrue()
        ->and($validator->errors()->first('bwr_id'))->toContain('Ziffern');

    // Test valid with leading zeros
    $validator = Validator::make(
        array_merge($baseData, ['bwr_id' => '0001234']),
        $request->rules(),
        $request->messages()
    );
    expect($validator->passes())->toBeTrue();
});

test('BWR-ID must be unique', function () {
    Employee::factory()->create([
        'bwr_id' => '1234567',
    ]);

    $request = new StoreEmployeeRequest;
    $validator = Validator::make(
        [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test2@example.com',
            'bwr_id' => '1234567',
        ],
        $request->rules(),
        $request->messages()
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('bwr_id'))->toBeTrue()
        ->and($validator->errors()->first('bwr_id'))->toContain('bereits');
});

test('gender is required when BWR status is pending or active', function () {
    $request = new StoreEmployeeRequest;
    $baseData = [
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => 'test@example.com',
    ];

    // Test with pending status - should fail without gender
    $validator = Validator::make(
        array_merge($baseData, ['bwr_status' => 'pending']),
        $request->rules(),
        $request->messages()
    );
    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('gender'))->toBeTrue();

    // Test with active status - should fail without gender
    $validator = Validator::make(
        array_merge($baseData, ['bwr_status' => 'active', 'email' => 'test2@example.com']),
        $request->rules(),
        $request->messages()
    );
    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('gender'))->toBeTrue();

    // Test with not_registered status - should pass without gender
    $validator = Validator::make(
        array_merge($baseData, ['bwr_status' => 'not_registered', 'email' => 'test3@example.com']),
        $request->rules(),
        $request->messages()
    );
    expect($validator->errors()->has('gender'))->toBeFalse();
});

test('structured address fields are required when BWR status is pending or active', function () {
    $request = new StoreEmployeeRequest;
    $baseData = [
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => 'test@example.com',
    ];

    // Test with pending status - should fail without address
    $validator = Validator::make(
        array_merge($baseData, ['bwr_status' => 'pending']),
        $request->rules(),
        $request->messages()
    );
    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('address_street'))->toBeTrue()
        ->and($validator->errors()->has('address_postal_code'))->toBeTrue()
        ->and($validator->errors()->has('address_city'))->toBeTrue();

    // Test with active status - should fail without address
    $validator = Validator::make(
        array_merge($baseData, ['bwr_status' => 'active', 'email' => 'test2@example.com']),
        $request->rules(),
        $request->messages()
    );
    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('address_street'))->toBeTrue()
        ->and($validator->errors()->has('address_postal_code'))->toBeTrue()
        ->and($validator->errors()->has('address_city'))->toBeTrue();

    // Test with not_registered status - should pass without address
    $validator = Validator::make(
        array_merge($baseData, ['bwr_status' => 'not_registered', 'email' => 'test3@example.com']),
        $request->rules(),
        $request->messages()
    );
    expect($validator->errors()->has('address_street'))->toBeFalse()
        ->and($validator->errors()->has('address_postal_code'))->toBeFalse()
        ->and($validator->errors()->has('address_city'))->toBeFalse();
});

test('country and state codes must be ISO 3166-1 alpha-2 format', function () {
    $request = new StoreEmployeeRequest;
    $baseData = [
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => 'test@example.com',
    ];

    // Test birth_country with invalid format
    $validator = Validator::make(
        array_merge($baseData, ['birth_country' => 'DEU']),
        $request->rules(),
        $request->messages()
    );
    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('birth_country'))->toBeTrue()
        ->and($validator->errors()->first('birth_country'))->toContain('2 Buchstaben');

    // Test birth_country with lowercase
    $validator = Validator::make(
        array_merge($baseData, ['birth_country' => 'de', 'email' => 'test2@example.com']),
        $request->rules(),
        $request->messages()
    );
    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('birth_country'))->toBeTrue();

    // Test address_country with valid format
    $validator = Validator::make(
        array_merge($baseData, ['address_country' => 'DE', 'email' => 'test3@example.com']),
        $request->rules(),
        $request->messages()
    );
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

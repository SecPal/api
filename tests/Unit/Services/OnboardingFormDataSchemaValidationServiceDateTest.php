<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\OnboardingFormTemplate;
use App\Services\OnboardingFormDataSchemaValidationService;
use Illuminate\Validation\ValidationException;

uses()->group('unit', 'services');

/** Minimal residential_address_history form_schema that accepts any object. */
function residentialAddressHistorySchema(): array
{
    return [
        'type' => 'object',
        'title' => 'Residential Address History',
        'properties' => [
            'current_address' => ['type' => 'object'],
            'previous_addresses' => ['type' => 'array'],
        ],
    ];
}

/** Minimal valid current_address payload. */
function validCurrentAddress(string $residedFrom): array
{
    return [
        'street' => 'Musterstraße',
        'house_number' => '1',
        'postal_code' => '10115',
        'city' => 'Berlin',
        'country' => 'DE',
        'resided_from' => $residedFrom,
    ];
}

/** Minimal valid previous_address payload. */
function validPreviousAddress(string $residedFrom, string $residedUntil): array
{
    return [
        'street' => 'Alte Gasse',
        'house_number' => '5',
        'postal_code' => '20095',
        'city' => 'Hamburg',
        'country' => 'DE',
        'resided_from' => $residedFrom,
        'resided_until' => $residedUntil,
    ];
}

beforeEach(function () {
    $this->service = new OnboardingFormDataSchemaValidationService;
    $this->template = OnboardingFormTemplate::factory()->make([
        'template_key' => 'residential_address_history',
        'form_schema' => residentialAddressHistorySchema(),
        'is_required' => true,
    ]);
});

test('valid YYYY-MM-DD dates for resided_from and resided_until pass validation', function () {
    $this->service->assertMatchesTemplate(
        $this->template,
        [
            'current_address' => validCurrentAddress('2022-03-15'),
            'previous_addresses' => [
                validPreviousAddress('2018-01-01', '2022-03-14'),
            ],
        ],
        forSubmittedStatus: true,
    );
})->throwsNoExceptions();

test('impossible month in resided_from is rejected', function () {
    $this->service->assertMatchesTemplate(
        $this->template,
        ['current_address' => validCurrentAddress('2026-99-01'), 'previous_addresses' => []],
        forSubmittedStatus: true,
    );
})->throws(ValidationException::class);

test('impossible day in resided_from is rejected', function () {
    $this->service->assertMatchesTemplate(
        $this->template,
        ['current_address' => validCurrentAddress('2026-02-30'), 'previous_addresses' => []],
        forSubmittedStatus: true,
    );
})->throws(ValidationException::class);

test('month 13 in resided_from is rejected', function () {
    $this->service->assertMatchesTemplate(
        $this->template,
        ['current_address' => validCurrentAddress('2026-13-01'), 'previous_addresses' => []],
        forSubmittedStatus: true,
    );
})->throws(ValidationException::class);

test('impossible day in resided_until is rejected', function () {
    $this->service->assertMatchesTemplate(
        $this->template,
        [
            'current_address' => validCurrentAddress('2022-01-01'),
            'previous_addresses' => [
                validPreviousAddress('2018-01-01', '2018-11-31'),
            ],
        ],
        forSubmittedStatus: true,
    );
})->throws(ValidationException::class);

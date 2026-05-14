<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Employee;
use App\Models\OnboardingFormSubmission;
use App\Models\OnboardingFormTemplate;
use App\Models\TenantKey;
use App\Services\OnboardingResidentialAddressHistorySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('unit', 'services');

beforeEach(function () {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);
});

afterEach(function () {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('it syncs approved residential address history submissions into employee addresses', function () {
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $template = OnboardingFormTemplate::query()
        ->where('template_key', 'residential_address_history')
        ->whereNull('tenant_id')
        ->firstOrFail();

    $submission = OnboardingFormSubmission::factory()->approved()->create([
        'employee_id' => $employee->id,
        'form_template_id' => $template->id,
        'form_data' => [
            'current_address' => [
                'street' => 'Neue Straße',
                'house_number' => '10',
                'postal_code' => '10115',
                'city' => 'Berlin',
                'supplement' => '3. OG',
                'country' => 'DE',
                'resided_from' => '2024-01-01',
            ],
            'previous_addresses' => [
                [
                    'street' => 'Alte Straße',
                    'house_number' => '5',
                    'postal_code' => '04109',
                    'city' => 'Leipzig',
                    'supplement' => '',
                    'country' => 'DE',
                    'resided_from' => '2022-01-01',
                    'resided_until' => '2023-12-31',
                ],
            ],
        ],
    ]);

    $submission->load(['employee', 'formTemplate']);

    app(OnboardingResidentialAddressHistorySyncService::class)
        ->syncFromApprovedSubmission($submission);

    $employee->load('addresses');

    expect($employee->addresses)->toHaveCount(2);
    expect($employee->currentAddress())->not->toBeNull()
        ->and($employee->currentAddress()?->street)->toBe('Neue Straße')
        ->and($employee->currentAddress()?->house_number)->toBe('10')
        ->and($employee->currentAddress()?->resided_from?->toDateString())->toBe('2024-01-01');

    $previous = $employee->addresses->firstWhere('resided_until', '!=', null);
    expect($previous)->not->toBeNull()
        ->and($previous?->street)->toBe('Alte Straße')
        ->and($previous?->resided_until?->toDateString())->toBe('2023-12-31');
});

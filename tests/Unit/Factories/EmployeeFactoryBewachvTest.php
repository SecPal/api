<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('bewachv', 'factory', 'unit');

test('withBwrRegistration factory state creates valid BWR data', function () {
    $employee = Employee::factory()->withBwrRegistration()->create();

    expect($employee->bwr_id)->toMatch('/^[0-9]{7}$/')
        ->and($employee->bwr_status)->toBeIn(['not_registered', 'pending', 'active', 'suspended', 'revoked'])
        ->and($employee->gender)->toBeIn(['male', 'female', 'diverse'])
        ->and($employee->address_street)->not->toBeNull()
        ->and($employee->address_postal_code)->not->toBeNull()
        ->and($employee->address_city)->not->toBeNull();
});

test('withBwrRegistration preserves leading zeros in BWR-ID', function () {
    $employee = Employee::factory()->withBwrRegistration()->create();

    // BWR-ID should be exactly 7 characters (may start with 0)
    expect(strlen($employee->bwr_id))->toBe(7)
        ->and($employee->bwr_id)->toBeString();
});

test('withCompleteBewachvData includes all BewachV fields', function () {
    $employee = Employee::factory()->withCompleteBewachvData()->create();

    // BWR tracking
    expect($employee->bwr_id)->not->toBeNull()
        ->and($employee->bwr_status)->not->toBeNull()
        ->and($employee->bwr_registered_at)->not->toBeNull();

    // Identity
    expect($employee->gender)->not->toBeNull()
        ->and($employee->birth_city)->not->toBeNull()
        ->and($employee->birth_country)->not->toBeNull();

    // Nationalities (dual citizenship)
    expect($employee->nationalities)->toBeArray()
        ->and(count($employee->nationalities))->toBeGreaterThanOrEqual(1); // At least 1, sometimes 2

    // Address
    expect($employee->address_street)->not->toBeNull()
        ->and($employee->address_postal_code)->not->toBeNull()
        ->and($employee->address_city)->not->toBeNull();

    // Address history
    expect($employee->address_history)->toBeArray();

    // Intended activities
    expect($employee->intended_activities)->toBeArray();
    // ID document
    expect($employee->id_document_type)->not->toBeNull()
        ->and($employee->id_document_number)->not->toBeNull()
        ->and($employee->id_document_expiry)->not->toBeNull();

    // Sachkunde
    expect($employee->sachkunde_ihk_number)->not->toBeNull()
        ->and($employee->sachkunde_exam_date)->not->toBeNull()
        ->and($employee->sachkunde_issued_date)->not->toBeNull();
});

test('withDualCitizenship creates two nationalities', function () {
    $employee = Employee::factory()->withDualCitizenship()->create();

    expect($employee->nationalities)->toBeArray()
        ->and(count($employee->nationalities))->toBe(2)
        ->and($employee->nationalities)->each(fn ($nationality) => $nationality->toMatch('/^[A-Z]{2}$/'));
});

test('withAddressHistory creates historical addresses', function () {
    $employee = Employee::factory()->withAddressHistory()->create();

    expect($employee->address_history)->toBeArray()
        ->and(count($employee->address_history))->toBeGreaterThan(0);

    foreach ($employee->address_history as $address) {
        expect($address)->toHaveKeys(['from', 'to', 'street', 'city', 'postal_code', 'country'])
            ->and($address['from'])->toBeString()
            ->and($address['to'])->toBeString()
            ->and($address['street'])->toBeString()
            ->and($address['city'])->toBeString()
            ->and($address['postal_code'])->toBeString()
            ->and($address['country'])->toMatch('/^[A-Z]{2}$/');
    }
});

test('withNonEuWorkPermit creates permit compliant non EU employee data', function () {
    $employee = Employee::factory()->withNonEuWorkPermit()->create();

    expect($employee->requiresWorkPermit())->toBeTrue()
        ->and($employee->work_permit_type)->toBeIn(['temporary', 'permanent', 'blue_card', 'seasonal', 'student'])
        ->and($employee->work_permit_number)->not->toBeNull()
        ->and($employee->work_permit_issued_by)->not->toBeNull()
        ->and($employee->hasValidWorkAuthorization())->toBeTrue();
});

test('withExpiringWorkPermit creates a permit that expires within 30 days', function () {
    $employee = Employee::factory()->withExpiringWorkPermit()->create();

    expect($employee->work_permit_expiry)->not->toBeNull()
        ->and($employee->work_permit_expiry->isBefore(now()->addDays(31)))->toBeTrue()
        ->and($employee->expiring_documents->pluck('type')->all())->toContain('work_permit');
});

test('terminated factory state sets employment end date', function () {
    $employee = Employee::factory()->terminated()->create();

    expect($employee->status)->toBe('terminated')
        ->and($employee->last_working_day)->not->toBeNull()
        ->and($employee->last_working_day)->toBeInstanceOf(Carbon\Carbon::class);
});

test('factory creates unique BWR-IDs', function () {
    $employees = Employee::factory()->withBwrRegistration()->count(5)->create();

    $bwrIds = $employees->pluck('bwr_id')->toArray();
    $uniqueIds = array_unique($bwrIds);

    expect(count($uniqueIds))->toBe(5);
});

test('factory respects ISO 3166-1 alpha-2 for country codes', function () {
    $employee = Employee::factory()->withCompleteBewachvData()->create();

    expect($employee->birth_country)->toMatch('/^[A-Z]{2}$/')
        ->and($employee->address_country)->toMatch('/^[A-Z]{2}$/');

    foreach ($employee->nationalities as $nationality) {
        expect($nationality)->toMatch('/^[A-Z]{2}$/');
    }
});

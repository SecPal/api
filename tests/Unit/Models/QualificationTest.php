<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

use App\Models\Employee;
use App\Models\Qualification;
use App\Models\TenantKey;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @property TenantKey $tenant
 */
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

test('qualification can be created with factory', function () {
    $qualification = Qualification::factory()->create(['tenant_id' => $this->tenant->id]);

    expect($qualification->id)->not->toBeNull()
        ->and($qualification->tenant_id)->toBe($this->tenant->id)
        ->and($qualification->name)->not->toBeNull();
});

test('qualification has tenant relationship', function () {
    $qualification = Qualification::factory()->create(['tenant_id' => $this->tenant->id]);

    expect($qualification->tenant)->toBeInstanceOf(TenantKey::class)
        ->and($qualification->tenant->id)->toBe($this->tenant->id);
});

test('qualification has employees relationship', function () {
    $qualification = Qualification::factory()->create(['tenant_id' => $this->tenant->id]);
    $employee = Employee::factory()->create(['tenant_id' => $this->tenant->id]);

    $qualification->employees()->attach($employee->id, [
        'id' => (string) Illuminate\Support\Str::uuid(),
        'obtained_date' => now(),
    ]);

    expect($qualification->employees)->toHaveCount(1)
        ->and($qualification->employees->first())->toBeInstanceOf(Employee::class);
});

test('system qualification has null tenant id', function () {
    $systemQual = Qualification::factory()->system()->create();

    expect($systemQual->tenant_id)->toBeNull()
        ->and($systemQual->is_system_qualification)->toBeTrue();
});

test('bewachv qualification has correct category', function () {
    $bewachvQual = Qualification::factory()->bewachv()->create(['tenant_id' => $this->tenant->id]);

    expect($bewachvQual->category)->toBe('bewachv_34a')
        ->and($bewachvQual->requires_renewal)->toBeTrue();
});

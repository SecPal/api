<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @property TenantKey $tenant
 */
beforeEach(function () {
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);
});

afterEach(function () {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('employee document can be created with factory', function () {
    $employee = Employee::factory()->create(['tenant_id' => $this->tenant->id]);
    $document = EmployeeDocument::factory()->create(['employee_id' => $employee->id]);

    expect($document->id)->not->toBeNull()
        ->and($document->employee_id)->toBe($employee->id)
        ->and($document->document_type)->not->toBeNull();
});

test('employee document has employee relationship', function () {
    $employee = Employee::factory()->create(['tenant_id' => $this->tenant->id]);
    $document = EmployeeDocument::factory()->create(['employee_id' => $employee->id]);

    expect($document->employee)->toBeInstanceOf(Employee::class)
        ->and($document->employee->id)->toBe($employee->id);
});

test('employee document has uploader relationship', function () {
    $employee = Employee::factory()->create(['tenant_id' => $this->tenant->id]);
    $uploader = User::factory()->create();
    $document = EmployeeDocument::factory()->create([
        'employee_id' => $employee->id,
        'uploaded_by' => $uploader->id,
    ]);

    expect($document->uploader)->toBeInstanceOf(User::class)
        ->and($document->uploader->id)->toBe($uploader->id);
});

test('employee document visibility flag works', function () {
    $employee = Employee::factory()->create(['tenant_id' => $this->tenant->id]);
    $visibleDoc = EmployeeDocument::factory()->create([
        'employee_id' => $employee->id,
        'visible_to_employee' => true,
    ]);
    $hiddenDoc = EmployeeDocument::factory()->create([
        'employee_id' => $employee->id,
        'visible_to_employee' => false,
    ]);

    expect($visibleDoc->visible_to_employee)->toBeTrue()
        ->and($hiddenDoc->visible_to_employee)->toBeFalse();
});

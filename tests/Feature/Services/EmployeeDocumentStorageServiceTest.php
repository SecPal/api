<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Exceptions\EmployeeDocumentFileNotFoundException;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\OrganizationalUnit;
use App\Models\TenantKey;
use App\Services\EmployeeDocumentStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * @property TenantKey $tenant
 * @property Employee $employee
 * @property EmployeeDocumentStorageService $service
 */
beforeEach(function (): void {
    Storage::fake('local');

    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();

    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    $orgUnit = OrganizationalUnit::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $this->employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $orgUnit->id,
    ]);

    $this->service = new EmployeeDocumentStorageService;
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('service stores employee document with encryption', function (): void {
    $file = UploadedFile::fake()->createWithContent('employee-document.pdf', 'confidential content');

    $storedFile = $this->service->store($file, $this->employee);

    expect($storedFile['file_name'])->toBe('employee-document.pdf');
    expect($storedFile['mime_type'])->toBe('application/pdf');
    expect($storedFile['file_size'])->toBeGreaterThan(0);

    Storage::disk('local')->assertExists($storedFile['file_path']);

    $storedBlob = Storage::disk('local')->get($storedFile['file_path']);
    expect($storedBlob)->not->toContain('confidential content');
});

test('service does not persist blob when mime type cannot be determined', function (): void {
    $file = \Mockery::mock(UploadedFile::class);
    $file->shouldReceive('getRealPath')->once()->andReturn(__FILE__);
    $file->shouldReceive('getMimeType')->once()->andReturn(null);
    $file->shouldNotReceive('getSize');

    expect(fn () => $this->service->store($file, $this->employee))
        ->toThrow(\RuntimeException::class, 'Failed to determine employee document MIME type');

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

test('service throws dedicated not found exception for missing stored blob', function (): void {
    $document = EmployeeDocument::factory()->create([
        'employee_id' => $this->employee->id,
        'file_path' => 'employees/missing/documents/missing.enc',
    ]);

    expect(fn () => $this->service->retrieve($document))
        ->toThrow(EmployeeDocumentFileNotFoundException::class, 'Employee document not found in storage');
});

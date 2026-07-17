<?php

/*
 * SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution
 */

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\OrganizationalUnit;
use App\Models\Permission;
use App\Models\TenantKey;
use App\Models\User;
use App\Services\EmployeeDocumentStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

function fakePdfUpload(string $name = 'document.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, implode("\n", [
        '%PDF-1.4',
        '1 0 obj',
        '<< /Type /Catalog >>',
        'endobj',
        'trailer',
        '<<>>',
        '%%EOF',
    ]));
}

/**
 * @property TenantKey $tenant
 * @property User $user
 * @property string $token
 * @property Employee $employee
 */
beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    // Set tenant context for permission system
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($this->tenant->id);

    // Run seeder to ensure predefined roles exist
    Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('test-device')->plainTextToken;

    $this->employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    Storage::fake('local');
});

afterEach(function (): void {
    // Reset tenant context
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

describe('GET /v1/employees/{employee}/documents', function () {
    test('returns 401 when not authenticated', function (): void {
        $response = $this->getJson("/v1/employees/{$this->employee->id}/documents");
        $response->assertStatus(401);
    });

    test('returns 403 when user lacks employee_document.read permission', function (): void {
        $response = $this->withToken($this->token)
            ->getJson("/v1/employees/{$this->employee->id}/documents");

        $response->assertStatus(403);
    });

    test('returns all documents for HR with valid permission', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee_document.read');

        EmployeeDocument::factory()->create([
            'employee_id' => $this->employee->id,
            'visible_to_employee' => true,
        ]);

        EmployeeDocument::factory()->create([
            'employee_id' => $this->employee->id,
            'visible_to_employee' => false,
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/v1/employees/{$this->employee->id}/documents");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'employee_id', 'document_type', 'file_name', 'visible_to_employee'],
                ],
            ]);

        expect($response->json('data'))->toHaveCount(2);
    });

    test('returns preserved documents when the uploader user was deleted', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee_document.read');

        $document = EmployeeDocument::factory()->create([
            'employee_id' => $this->employee->id,
            'uploaded_by' => null,
            'visible_to_employee' => true,
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/v1/employees/{$this->employee->id}/documents");

        $response->assertOk()
            ->assertJsonPath('data.0.id', $document->id)
            ->assertJsonPath('data.0.uploader', null);
    });

    test('filters documents by visible_to_employee for employee viewing own documents', function (): void {
        // Make this user the employee's user account
        $this->employee->update(['user_id' => $this->user->id]);

        EmployeeDocument::factory()->create([
            'employee_id' => $this->employee->id,
            'visible_to_employee' => true,
        ]);

        EmployeeDocument::factory()->create([
            'employee_id' => $this->employee->id,
            'visible_to_employee' => false,
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/v1/employees/{$this->employee->id}/documents");

        $response->assertStatus(200);
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data')[0]['visible_to_employee'])->toBe(true);
    });

    test('manager with organizational scope cannot list documents of employee outside scope', function (): void {
        $unitA = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant->id]);
        $unitB = OrganizationalUnit::factory()->create(['tenant_id' => $this->tenant->id]);

        // Create manager with scope for unitA only
        $manager = User::factory()->create();
        $managerToken = $manager->createToken('test-device')->plainTextToken;
        $manager->assignRole('Manager');
        $manager->organizationalScopes()->create([
            'organizational_unit_id' => $unitA->id,
            'access_level' => 'read',
            'include_descendants' => false,
        ]);

        givePermissionWithTenant($manager, $this->tenant->id, 'employee_document.read');

        // Employee in unitA (accessible)
        $employeeA = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        // Employee in unitB (not accessible)
        $employeeB = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        EmployeeDocument::factory()->create(['employee_id' => $employeeA->id]);
        EmployeeDocument::factory()->create(['employee_id' => $employeeB->id]);

        // OU scopes cannot grant access to domain employees after the breaking change.
        $responseA = $this->withToken($managerToken)->getJson("/v1/employees/{$employeeA->id}/documents");
        $responseA->assertStatus(403);

        // Manager cannot access documents of employeeB (outside scope)
        $responseB = $this->withToken($managerToken)->getJson("/v1/employees/{$employeeB->id}/documents");
        $responseB->assertStatus(403);
    });
});

describe('POST /v1/employees/{employee}/documents', function () {
    test('returns 401 when not authenticated', function (): void {
        $file = UploadedFile::fake()->create('contract.pdf', 1024);

        $response = $this->postJson("/v1/employees/{$this->employee->id}/documents", [
            'file' => $file,
            'document_type' => 'contract',
            'visible_to_employee' => true,
        ]);

        $response->assertStatus(401);
    });

    test('returns 403 when user lacks employee_document.write permission', function (): void {
        $file = UploadedFile::fake()->create('contract.pdf', 1024);

        $response = $this->withToken($this->token)
            ->postJson("/v1/employees/{$this->employee->id}/documents", [
                'file' => $file,
                'title' => 'Test Document',
                'document_type' => 'contract',
                'visible_to_employee' => true,
            ]);

        $response->assertStatus(403);
    });

    test('returns 422 when required fields are missing', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee_document.write');

        $response = $this->withToken($this->token)
            ->postJson("/v1/employees/{$this->employee->id}/documents", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file', 'document_type', 'visible_to_employee']);
    });

    test('uploads document with valid data', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee_document.write');

        $file = fakePdfUpload('contract.pdf');

        $response = $this->withToken($this->token)
            ->postJson("/v1/employees/{$this->employee->id}/documents", [
                'file' => $file,
                'title' => 'Employment Contract',
                'document_type' => 'contract',
                'description' => 'Employment contract 2025',
                'visible_to_employee' => true,
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'employee_id',
                    'document_type',
                    'file_name',
                    'download_url',
                    'mime_type',
                    'file_size',
                    'visible_to_employee',
                ],
            ]);

        expect($response->json('data.document_type'))->toBe('contract');
        expect($response->json('data.visible_to_employee'))->toBe(true);
        expect(array_key_exists('file_path', $response->json('data')))->toBeFalse();

        $document = EmployeeDocument::query()->findOrFail($response->json('data.id'));
        Storage::disk('local')->assertExists($document->file_path);

        $storedBlob = Storage::disk('local')->get($document->file_path);
        expect($storedBlob)->not->toBe('Employment contract payload');

        $decodedBlob = json_decode($storedBlob, true);
        expect($decodedBlob)->toBeArray();
        expect($decodedBlob)->toHaveKeys(['ciphertext', 'nonce']);
    });

    test('sanitizes stored document filename metadata', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee_document.write');

        $file = fakePdfUpload('employee";notes.pdf');

        $response = $this->withToken($this->token)
            ->postJson("/v1/employees/{$this->employee->id}/documents", [
                'file' => $file,
                'title' => 'Employee Notes',
                'document_type' => 'contract',
                'visible_to_employee' => true,
            ]);

        $response->assertStatus(201);

        $storedFileName = (string) $response->json('data.file_name');

        expect($storedFileName)
            ->not->toContain('"')
            ->not->toContain(';');
    });

    test('returns 422 when file exceeds 10MB limit', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee_document.write');

        $file = UploadedFile::fake()->create('large.pdf', 11000); // 11MB

        $response = $this->withToken($this->token)
            ->postJson("/v1/employees/{$this->employee->id}/documents", [
                'file' => $file,
                'title' => 'Disguised Contract',
                'document_type' => 'contract',
                'visible_to_employee' => true,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    });

    test('returns 422 when file type is invalid', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee_document.write');

        $file = UploadedFile::fake()->create('document.txt', 1024);

        $response = $this->withToken($this->token)
            ->postJson("/v1/employees/{$this->employee->id}/documents", [
                'file' => $file,
                'title' => 'Disguised Contract',
                'document_type' => 'contract',
                'visible_to_employee' => true,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    });

    test('returns 422 when file content does not match an allowed MIME type', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee_document.write');

        $path = tempnam(sys_get_temp_dir(), 'pdf');
        expect($path)->not->toBeFalse();

        $bytesWritten = file_put_contents($path, 'plain text disguised as a pdf');
        expect($bytesWritten)->not->toBeFalse();

        try {
            $file = new UploadedFile($path, 'contract.pdf', 'application/pdf', null, true);

            $response = $this->withToken($this->token)
                ->postJson("/v1/employees/{$this->employee->id}/documents", [
                    'file' => $file,
                    'title' => 'Disguised Contract',
                    'document_type' => 'contract',
                    'visible_to_employee' => true,
                ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['file']);
        } finally {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    });
});

describe('GET /v1/employees/{employee}/documents/{document}', function () {
    test('returns 401 when not authenticated', function (): void {
        $document = EmployeeDocument::factory()->create([
            'employee_id' => $this->employee->id,
        ]);

        $response = $this->getJson("/v1/employees/{$this->employee->id}/documents/{$document->id}");
        $response->assertStatus(401);
    });

    test('returns 403 when user lacks employee_document.read permission', function (): void {
        $document = EmployeeDocument::factory()->create([
            'employee_id' => $this->employee->id,
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/v1/employees/{$this->employee->id}/documents/{$document->id}");

        $response->assertStatus(403);
    });

    test('returns document details with valid permission', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee_document.read');

        $document = EmployeeDocument::factory()->create([
            'employee_id' => $this->employee->id,
            'document_type' => 'contract',
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/v1/employees/{$this->employee->id}/documents/{$document->id}");

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'id' => $document->id,
                    'document_type' => 'contract',
                ],
            ]);
    });

    test('returns 404 when document belongs to a different employee route', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee_document.read');

        $otherEmployee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $foreignDocument = EmployeeDocument::factory()->create([
            'employee_id' => $otherEmployee->id,
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/v1/employees/{$this->employee->id}/documents/{$foreignDocument->id}");

        $response->assertStatus(404);
    });
});

describe('GET /v1/employees/{employee}/documents/{document}/download', function () {
    test('returns 401 when not authenticated', function (): void {
        $document = EmployeeDocument::factory()->create([
            'employee_id' => $this->employee->id,
            'file_path' => 'employees/1/documents/test.pdf',
        ]);

        Storage::disk('local')->put($document->file_path, 'test content');

        $response = $this->getJson("/v1/employees/{$this->employee->id}/documents/{$document->id}/download");
        $response->assertStatus(401);
    });

    test('returns 403 when user lacks employee_document.read permission', function (): void {
        $document = EmployeeDocument::factory()->create([
            'employee_id' => $this->employee->id,
            'file_path' => 'employees/1/documents/test.pdf',
        ]);

        Storage::disk('local')->put($document->file_path, 'test content');

        $response = $this->withToken($this->token)
            ->getJson("/v1/employees/{$this->employee->id}/documents/{$document->id}/download");

        $response->assertStatus(403);
    });

    test('downloads document file with valid permission', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee_document.read');

        $storageService = app(EmployeeDocumentStorageService::class);
        $storedFile = $storageService->store(
            fakePdfUpload('test.pdf'),
            $this->employee
        );

        $document = EmployeeDocument::factory()->create([
            'employee_id' => $this->employee->id,
            'uploaded_by' => $this->user->id,
            'file_path' => $storedFile['file_path'],
            'file_name' => $storedFile['file_name'],
            'mime_type' => $storedFile['mime_type'],
            'file_size' => $storedFile['file_size'],
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/v1/employees/{$this->employee->id}/documents/{$document->id}/download");

        $response->assertStatus(200);
        expect($response->headers->get('content-type'))->toBe('application/pdf');
        expect($response->headers->get('content-disposition'))->toContain('test.pdf');
        expect($response->getContent())->toContain('%PDF-1.4');
    });

    test('sanitizes filename in content disposition header', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee_document.read');

        $storedFile = app(EmployeeDocumentStorageService::class)->store(
            fakePdfUpload('download.pdf'),
            $this->employee
        );

        $document = EmployeeDocument::factory()->create([
            'employee_id' => $this->employee->id,
            'uploaded_by' => $this->user->id,
            'file_path' => $storedFile['file_path'],
            'file_name' => "report\r\nInjected-Header: value\";2025.pdf",
            'mime_type' => 'application/pdf',
            'file_size' => $storedFile['file_size'],
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/v1/employees/{$this->employee->id}/documents/{$document->id}/download");

        $response->assertStatus(200);

        $contentDisposition = (string) $response->headers->get('content-disposition');

        expect($contentDisposition)
            ->toContain('attachment; filename="reportInjected-Header: value__2025.pdf"')
            ->not->toContain("\r")
            ->not->toContain("\n");
    });

    test('returns 404 when document file does not exist', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee_document.read');

        $document = EmployeeDocument::factory()->create([
            'employee_id' => $this->employee->id,
            'file_path' => 'employees/1/documents/missing.pdf',
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/v1/employees/{$this->employee->id}/documents/{$document->id}/download");

        $response->assertStatus(404);
    });

    test('returns 500 when encrypted document blob is invalid', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee_document.read');

        $document = EmployeeDocument::factory()->create([
            'employee_id' => $this->employee->id,
            'uploaded_by' => $this->user->id,
            'file_path' => 'employees/1/documents/invalid.enc',
            'file_name' => 'invalid.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 123,
        ]);

        Storage::disk('local')->put($document->file_path, '{"ciphertext":"broken"}');

        $response = $this->withToken($this->token)
            ->getJson("/v1/employees/{$this->employee->id}/documents/{$document->id}/download");

        $response->assertStatus(500);
    });
});

describe('DELETE /v1/employees/{employee}/documents/{document}', function () {
    test('returns 401 when not authenticated', function (): void {
        $document = EmployeeDocument::factory()->create([
            'employee_id' => $this->employee->id,
        ]);

        $response = $this->deleteJson("/v1/employees/{$this->employee->id}/documents/{$document->id}");
        $response->assertStatus(401);
    });

    test('returns 403 when user lacks employee_document.write permission', function (): void {
        $document = EmployeeDocument::factory()->create([
            'employee_id' => $this->employee->id,
        ]);

        $response = $this->withToken($this->token)
            ->deleteJson("/v1/employees/{$this->employee->id}/documents/{$document->id}");

        $response->assertStatus(403);
    });

    test('deletes document and file with valid permission', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee_document.write');

        $storageService = app(EmployeeDocumentStorageService::class);
        $storedFile = $storageService->store(
            fakePdfUpload('delete.pdf'),
            $this->employee
        );

        $document = EmployeeDocument::factory()->create([
            'employee_id' => $this->employee->id,
            'uploaded_by' => $this->user->id,
            'file_path' => $storedFile['file_path'],
            'file_name' => $storedFile['file_name'],
            'mime_type' => $storedFile['mime_type'],
            'file_size' => $storedFile['file_size'],
        ]);

        $response = $this->withToken($this->token)
            ->deleteJson("/v1/employees/{$this->employee->id}/documents/{$document->id}");

        $response->assertNoContent();
        Storage::disk('local')->assertMissing($document->file_path);
        expect(EmployeeDocument::withTrashed()->find($document->id)->deleted_at)->not->toBeNull();
    });
});

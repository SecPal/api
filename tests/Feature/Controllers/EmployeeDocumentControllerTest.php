<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\OrganizationalUnit;
use App\Models\Permission;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('test-device')->plainTextToken;

    Permission::create(['name' => 'employee_document.read', 'guard_name' => 'sanctum']);
    Permission::create(['name' => 'employee_document.write', 'guard_name' => 'sanctum']);

    $organizationalUnit = OrganizationalUnit::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $this->employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $organizationalUnit->id,
    ]);

    Storage::fake('local');
});

afterEach(function (): void {
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

    test('filters documents by visible_to_employee for employee viewing own documents', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee_document.read');

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

        $file = UploadedFile::fake()->create('contract.pdf', 1024, 'application/pdf');

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
                    'file_path',
                    'mime_type',
                    'file_size',
                    'visible_to_employee',
                ],
            ]);

        expect($response->json('data.document_type'))->toBe('contract');
        expect($response->json('data.visible_to_employee'))->toBe(true);

        Storage::disk('local')->assertExists($response->json('data.file_path'));
    });

    test('returns 422 when file exceeds 10MB limit', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee_document.write');

        $file = UploadedFile::fake()->create('large.pdf', 11000); // 11MB

        $response = $this->withToken($this->token)
            ->postJson("/v1/employees/{$this->employee->id}/documents", [
                'file' => $file,
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
                'document_type' => 'contract',
                'visible_to_employee' => true,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
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

        $document = EmployeeDocument::factory()->create([
            'employee_id' => $this->employee->id,
            'file_path' => 'employees/1/documents/test.pdf',
            'file_name' => 'test.pdf',
            'mime_type' => 'application/pdf',
        ]);

        Storage::disk('local')->put($document->file_path, 'PDF content');

        $response = $this->withToken($this->token)
            ->getJson("/v1/employees/{$this->employee->id}/documents/{$document->id}/download");

        $response->assertStatus(200);
        expect($response->headers->get('content-type'))->toBe('application/pdf');
        expect($response->headers->get('content-disposition'))->toContain('test.pdf');
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

        $document = EmployeeDocument::factory()->create([
            'employee_id' => $this->employee->id,
            'file_path' => 'employees/1/documents/delete.pdf',
        ]);

        Storage::disk('local')->put($document->file_path, 'content');

        $response = $this->withToken($this->token)
            ->deleteJson("/v1/employees/{$this->employee->id}/documents/{$document->id}");

        $response->assertStatus(204);
        Storage::disk('local')->assertMissing($document->file_path);
        expect(EmployeeDocument::withTrashed()->find($document->id)->deleted_at)->not->toBeNull();
    });
});

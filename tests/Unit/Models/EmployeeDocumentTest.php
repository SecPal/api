<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Unit\Models;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected TenantKey $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        TenantKey::setKekPath(getTestKekPath());
        TenantKey::generateKek();
        $keys = TenantKey::generateEnvelopeKeys();
        $this->tenant = TenantKey::create($keys);
    }

    protected function tearDown(): void
    {
        cleanupTestKekFile();
        TenantKey::setKekPath(null);
        parent::tearDown();
    }

    public function test_employee_document_can_be_created_with_factory(): void
    {
        $employee = Employee::factory()->create(['tenant_id' => $this->tenant->id]);
        $document = EmployeeDocument::factory()->create(['employee_id' => $employee->id]);

        $this->assertNotNull($document->id);
        $this->assertSame($employee->id, $document->employee_id);
        $this->assertNotNull($document->document_type);
    }

    public function test_employee_document_has_employee_relationship(): void
    {
        $employee = Employee::factory()->create(['tenant_id' => $this->tenant->id]);
        $document = EmployeeDocument::factory()->create(['employee_id' => $employee->id]);

        $this->assertInstanceOf(Employee::class, $document->employee);
        $this->assertSame($employee->id, $document->employee->id);
    }

    public function test_employee_document_has_uploader_relationship(): void
    {
        $employee = Employee::factory()->create(['tenant_id' => $this->tenant->id]);
        $uploader = User::factory()->create();
        $document = EmployeeDocument::factory()->create([
            'employee_id' => $employee->id,
            'uploaded_by' => $uploader->id,
        ]);

        $this->assertInstanceOf(User::class, $document->uploader);
        $this->assertSame($uploader->id, $document->uploader->id);
    }

    public function test_employee_document_visibility_flag_works(): void
    {
        $employee = Employee::factory()->create(['tenant_id' => $this->tenant->id]);
        $visibleDoc = EmployeeDocument::factory()->create([
            'employee_id' => $employee->id,
            'visible_to_employee' => true,
        ]);
        $hiddenDoc = EmployeeDocument::factory()->create([
            'employee_id' => $employee->id,
            'visible_to_employee' => false,
        ]);

        $this->assertTrue($visibleDoc->visible_to_employee);
        $this->assertFalse($hiddenDoc->visible_to_employee);
    }
}

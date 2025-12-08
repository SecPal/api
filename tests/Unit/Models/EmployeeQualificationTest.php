<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Unit\Models;

use App\Models\Employee;
use App\Models\EmployeeQualification;
use App\Models\Qualification;
use App\Models\TenantKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeQualificationTest extends TestCase
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

    public function test_employee_qualification_can_be_created(): void
    {
        $employee = Employee::factory()->create(['tenant_id' => $this->tenant->id]);
        $qualification = Qualification::factory()->create(['tenant_id' => $this->tenant->id]);

        $employeeQual = EmployeeQualification::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'employee_id' => $employee->id,
            'qualification_id' => $qualification->id,
            'obtained_date' => now(),
            'status' => 'valid',
        ]);

        $this->assertNotNull($employeeQual->id);
        $this->assertSame($employee->id, $employeeQual->employee_id);
        $this->assertSame($qualification->id, $employeeQual->qualification_id);
    }

    public function test_employee_qualification_has_employee_relationship(): void
    {
        $employee = Employee::factory()->create(['tenant_id' => $this->tenant->id]);
        $qualification = Qualification::factory()->create(['tenant_id' => $this->tenant->id]);

        $employeeQual = EmployeeQualification::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'employee_id' => $employee->id,
            'qualification_id' => $qualification->id,
            'obtained_date' => now(),
            'status' => 'valid',
        ]);

        $this->assertInstanceOf(Employee::class, $employeeQual->employee);
        $this->assertSame($employee->id, $employeeQual->employee->id);
    }

    public function test_employee_qualification_has_qualification_relationship(): void
    {
        $employee = Employee::factory()->create(['tenant_id' => $this->tenant->id]);
        $qualification = Qualification::factory()->create(['tenant_id' => $this->tenant->id]);

        $employeeQual = EmployeeQualification::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'employee_id' => $employee->id,
            'qualification_id' => $qualification->id,
            'obtained_date' => now(),
            'status' => 'valid',
        ]);

        $this->assertInstanceOf(Qualification::class, $employeeQual->qualification);
        $this->assertSame($qualification->id, $employeeQual->qualification->id);
    }

    public function test_employee_qualification_casts_dates_correctly(): void
    {
        $employee = Employee::factory()->create(['tenant_id' => $this->tenant->id]);
        $qualification = Qualification::factory()->create(['tenant_id' => $this->tenant->id]);

        $employeeQual = EmployeeQualification::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'employee_id' => $employee->id,
            'qualification_id' => $qualification->id,
            'obtained_date' => '2023-01-15',
            'expiry_date' => '2025-01-15',
            'status' => 'valid',
        ]);

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $employeeQual->obtained_date);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $employeeQual->expiry_date);
    }
}

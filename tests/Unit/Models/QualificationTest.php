<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Unit\Models;

use App\Models\Employee;
use App\Models\Qualification;
use App\Models\TenantKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QualificationTest extends TestCase
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

    public function test_qualification_can_be_created_with_factory(): void
    {
        $qualification = Qualification::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->assertNotNull($qualification->id);
        $this->assertSame($this->tenant->id, $qualification->tenant_id);
        $this->assertNotNull($qualification->name);
    }

    public function test_qualification_has_tenant_relationship(): void
    {
        $qualification = Qualification::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->assertInstanceOf(TenantKey::class, $qualification->tenant);
        $this->assertSame($this->tenant->id, $qualification->tenant->id);
    }

    public function test_qualification_has_employees_relationship(): void
    {
        $qualification = Qualification::factory()->create(['tenant_id' => $this->tenant->id]);
        $employee = Employee::factory()->create(['tenant_id' => $this->tenant->id]);

        $qualification->employees()->attach($employee->id, [
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'obtained_date' => now(),
        ]);

        $this->assertCount(1, $qualification->employees);
        $this->assertInstanceOf(Employee::class, $qualification->employees->first());
    }

    public function test_system_qualification_has_null_tenant_id(): void
    {
        $systemQual = Qualification::factory()->system()->create();

        $this->assertNull($systemQual->tenant_id);
        $this->assertTrue($systemQual->is_system_qualification);
    }

    public function test_bewachv_qualification_has_correct_category(): void
    {
        $bewachvQual = Qualification::factory()->bewachv()->create(['tenant_id' => $this->tenant->id]);

        $this->assertSame('bewachv_34a', $bewachvQual->category);
        $this->assertTrue($bewachvQual->requires_renewal);
    }
}

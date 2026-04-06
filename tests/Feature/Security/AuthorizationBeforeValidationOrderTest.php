<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\Activity;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\OrganizationalUnit;
use App\Models\Site;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();

    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($this->tenant->id);

    Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    $this->user = User::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);
    $this->token = $this->user->createToken('test-device')->plainTextToken;

    $this->organizationalUnit = OrganizationalUnit::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $this->customer = Customer::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $this->site = Site::factory()->create([
        'tenant_id' => $this->tenant->id,
        'customer_id' => $this->customer->id,
        'organizational_unit_id' => $this->organizationalUnit->id,
    ]);

    $this->employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->organizationalUnit->id,
    ]);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('site creation returns 403 before validating foreign tenant references', function (): void {
    $otherTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    $foreignCustomer = Customer::factory()->create(['tenant_id' => $otherTenant->id]);
    $foreignUnit = OrganizationalUnit::factory()->create(['tenant_id' => $otherTenant->id]);

    $response = $this->withToken($this->token)->postJson('/v1/sites', [
        'name' => 'Unauthorized Site',
        'type' => 'permanent',
        'customer_id' => $foreignCustomer->id,
        'organizational_unit_id' => $foreignUnit->id,
        'address' => [
            'street' => 'Test Street 1',
            'city' => 'Berlin',
            'postal_code' => '10115',
            'country' => 'DE',
        ],
    ]);

    $response->assertForbidden();
});

test('site update returns 403 before validating foreign tenant references', function (): void {
    $otherTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    $foreignCustomer = Customer::factory()->create(['tenant_id' => $otherTenant->id]);
    $foreignUnit = OrganizationalUnit::factory()->create(['tenant_id' => $otherTenant->id]);

    $response = $this->withToken($this->token)->patchJson("/v1/sites/{$this->site->id}", [
        'customer_id' => $foreignCustomer->id,
        'organizational_unit_id' => $foreignUnit->id,
        'valid_until' => now()->subDay()->toDateString(),
    ]);

    $response->assertForbidden();
});

test('customer assignment creation returns 403 before validating target user', function (): void {
    $otherTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    $foreignUser = User::factory()->create(['tenant_id' => $otherTenant->id]);

    $response = $this->withToken($this->token)
        ->postJson("/v1/customers/{$this->customer->id}/assignments", [
            'user_id' => $foreignUser->id,
            'role' => 'Key Account Manager',
            'valid_from' => now()->toDateString(),
            'valid_until' => now()->subDay()->toDateString(),
        ]);

    $response->assertForbidden();
});

test('site assignment creation returns 403 before validating target user', function (): void {
    $otherTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    $foreignUser = User::factory()->create(['tenant_id' => $otherTenant->id]);

    $response = $this->withToken($this->token)
        ->postJson("/v1/sites/{$this->site->id}/assignments", [
            'user_id' => $foreignUser->id,
            'role' => 'Site Manager',
            'valid_from' => now()->toDateString(),
            'valid_until' => now()->subDay()->toDateString(),
        ]);

    $response->assertForbidden();
});

test('employee creation returns 403 before validating organizational unit access', function (): void {
    $otherTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    $foreignUnit = OrganizationalUnit::factory()->create(['tenant_id' => $otherTenant->id]);

    $response = $this->withToken($this->token)->postJson('/v1/employees', [
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'john.doe@example.com',
        'date_of_birth' => '1990-01-15',
        'position' => 'Security Guard',
        'status' => Employee::STATUS_PRE_CONTRACT,
        'contract_type' => 'full_time',
        'contract_start_date' => now()->toDateString(),
        'management_level' => 0,
        'organizational_unit_id' => $foreignUnit->id,
    ]);

    $response->assertForbidden();
});

test('employee update returns 403 before validating organizational unit access', function (): void {
    $otherTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    $foreignUnit = OrganizationalUnit::factory()->create(['tenant_id' => $otherTenant->id]);

    $response = $this->withToken($this->token)->patchJson("/v1/employees/{$this->employee->id}", [
        'organizational_unit_id' => $foreignUnit->id,
    ]);

    $response->assertForbidden();
});

test('activity log index returns 403 before validating filters for unauthorized users', function (): void {
    Activity::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->organizationalUnit->id,
    ]);

    $response = $this->withToken($this->token)
        ->getJson("/v1/activity-logs?organizational_unit_id={$this->organizationalUnit->id}");

    $response->assertForbidden();
});

test('employee document upload returns 403 before payload validation for unauthorized users', function (): void {
    $file = UploadedFile::fake()->create('contract.pdf', 11000);

    $response = $this->withToken($this->token)
        ->postJson("/v1/employees/{$this->employee->id}/documents", [
            'file' => $file,
            'document_type' => 'contract',
            'visible_to_employee' => true,
        ]);

    $response->assertForbidden();
});

test('organizational unit creation returns 403 before validating parent hierarchy for unauthorized users', function (): void {
    $this->user->organizationalScopes()->delete();

    $response = $this->withToken($this->token)->postJson('/v1/organizational-units', [
        'name' => 'Unauthorized Child Unit',
        'type' => 'department',
        'parent_id' => $this->organizationalUnit->id,
    ]);

    $response->assertForbidden();
});

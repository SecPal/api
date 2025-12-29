<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Activity;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Site;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * @property TenantKey $tenant
 * @property User $user
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = TenantKey::factory()->create();
    $this->user = User::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);
    $this->actingAs($this->user);

    // Set tenant context
    request()->merge([
        'organizational_unit_id' => null,
    ]);
});

test('employee creation triggers activity log with security level 1', function (): void {
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'email' => 'john.doe@example.com',
    ]);

    $activity = Activity::where('log_name', 'employee_changes')
        ->where('description', 'created')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->subject_type)->toBe(Employee::class)
        ->and($activity->subject_id)->toBe($employee->id)
        ->and($activity->tenant_id)->toBe($this->tenant->id)
        ->and(Activity::getSecurityLevel($activity->log_name))->toBe(2); // Level 2: DSGVO-relevant
});

test('employee update only logs dirty fields', function (): void {
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'email' => 'old@example.com',
        'phone' => '+49 30 123456',
    ]);

    // Clear creation log
    Activity::where('log_name', 'employee_changes')->delete();

    // Update only email
    $employee->update(['email' => 'new@example.com']);

    // Email/phone are logged via GDPR observer (changed_fields), not Spatie auto-logging (attributes)
    // Find the GDPR activity log (has 'changed_fields' key)
    $activity = Activity::where('log_name', 'employee_changes')
        ->where('description', 'Sensitive data changed (GDPR-compliant: no values stored)')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->properties)->toHaveKey('changed_fields')
        ->and($activity->properties['changed_fields'])->toContain('email')
        ->and($activity->properties['changed_fields'])->not->toContain('phone');
});

test('employee deletion triggers soft delete activity log', function (): void {
    $employee = Employee::factory()->create(['tenant_id' => $this->tenant->id]);

    // Clear creation log
    Activity::where('log_name', 'employee_changes')->delete();

    $employee->delete();

    $activity = Activity::where('log_name', 'employee_changes')
        ->where('description', 'deleted')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->subject_id)->toBe($employee->id)
        ->and(Activity::getSecurityLevel($activity->log_name))->toBe(2); // Level 2: DSGVO-relevant
});

test('customer creation triggers activity log with security level 2', function (): void {
    $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);

    $activity = Activity::where('log_name', 'customer_changes')
        ->where('description', 'created')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->subject_type)->toBe(Customer::class)
        ->and($activity->subject_id)->toBe($customer->id)
        ->and($activity->tenant_id)->toBe($this->tenant->id)
        ->and(Activity::getSecurityLevel($activity->log_name))->toBe(2);
});

test('customer update logs changed fields', function (): void {
    $customer = Customer::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Old Name',
    ]);

    // Clear creation log
    Activity::where('log_name', 'customer_changes')->delete();

    $customer->update(['name' => 'New Name', 'is_active' => false]);

    $activity = Activity::where('log_name', 'customer_changes')
        ->where('description', 'updated')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->properties['attributes'])->toHaveKey('name')
        ->and($activity->properties['attributes'])->toHaveKey('is_active')
        ->and($activity->properties['attributes']['name'])->toBe('New Name');
});

test('site creation triggers activity log with security level 2', function (): void {
    $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
    $site = Site::factory()->create([
        'tenant_id' => $this->tenant->id,
        'customer_id' => $customer->id,
    ]);

    $activity = Activity::where('log_name', 'site_management')
        ->where('description', 'created')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->subject_type)->toBe(Site::class)
        ->and($activity->subject_id)->toBe($site->id)
        ->and($activity->tenant_id)->toBe($this->tenant->id)
        ->and(Activity::getSecurityLevel($activity->log_name))->toBe(2);
});

test('site update logs location and status changes', function (): void {
    $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
    $site = Site::factory()->create([
        'tenant_id' => $this->tenant->id,
        'customer_id' => $customer->id,
        'is_active' => true,
    ]);

    // Clear creation log
    Activity::where('log_name', 'site_management')->delete();

    $site->update([
        'is_active' => false,
        'address' => ['street' => 'New Street 123', 'city' => 'Berlin'],
    ]);

    $activity = Activity::where('log_name', 'site_management')
        ->where('description', 'updated')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->properties['attributes'])->toHaveKey('is_active')
        ->and($activity->properties['attributes'])->toHaveKey('address')
        ->and($activity->properties['attributes']['is_active'])->toBeFalse();
});

test('activity logs are tenant-isolated', function (): void {
    $tenant2 = TenantKey::factory()->create();

    $employee1 = Employee::factory()->create(['tenant_id' => $this->tenant->id]);
    $employee2 = Employee::factory()->create(['tenant_id' => $tenant2->id]);

    $activitiesTenant1 = Activity::where('tenant_id', $this->tenant->id)->count();
    $activitiesTenant2 = Activity::where('tenant_id', $tenant2->id)->count();

    expect($activitiesTenant1)->toBeGreaterThan(0)
        ->and($activitiesTenant2)->toBeGreaterThan(0)
        ->and(Activity::where('tenant_id', $this->tenant->id)->where('subject_id', $employee2->id)->count())->toBe(0);
});

test('empty updates do not create activity logs', function (): void {
    $employee = Employee::factory()->create(['tenant_id' => $this->tenant->id]);

    // Clear creation log
    $initialCount = Activity::where('log_name', 'employee_changes')->count();
    Activity::where('log_name', 'employee_changes')->delete();

    // Touch without changing anything
    $employee->save();

    $newCount = Activity::where('log_name', 'employee_changes')->count();

    expect($newCount)->toBe(0);
});

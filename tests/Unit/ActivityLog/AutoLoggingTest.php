<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

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

test('employee creation triggers activity log with 3-year retention', function (): void {
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'email' => 'john.doe@example.com',
    ]);

    $activity = Activity::where('log_name', 'employee_changes')
        ->where('description', 'created')
        ->where('subject_type', Employee::class)
        ->where('subject_id', $employee->id)
        ->where('tenant_id', $this->tenant->id)
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->subject_type)->toBe(Employee::class)
        ->and($activity->subject_id)->toBe($employee->id)
        ->and($activity->tenant_id)->toBe($this->tenant->id)
        ->and(Activity::getRetentionYearsForLogType($activity->log_name))->toBeGreaterThanOrEqual(3); // Level 2: DSGVO-relevant
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
        ->and(Activity::getRetentionYearsForLogType($activity->log_name))->toBeGreaterThanOrEqual(3); // Level 2: DSGVO-relevant
});

test('customer creation triggers activity log with 8-year retention', function (): void {
    $customer = Customer::factory()->create([
        'tenant_id' => $this->tenant->id,
        'customer_number' => 'KD-2026-1213',
        'name' => 'Observability Customer',
    ]);

    $activity = Activity::where('log_name', 'customer_changes')
        ->where('description', 'created')
        ->where('subject_type', Customer::class)
        ->where('subject_id', $customer->id)
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->description)->toBe('created')
        ->and($activity->subject_type)->toBe(Customer::class)
        ->and($activity->subject_id)->toBe($customer->id)
        ->and($activity->tenant_id)->toBe($this->tenant->id)
        ->and($activity->properties['subject_name'])->toBe('Observability Customer')
        ->and($activity->properties['subject_identifier'])->toBe('KD-2026-1213')
        ->and(Activity::getRetentionYearsForLogType($activity->log_name))->toBeGreaterThanOrEqual(3);
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
        ->and($activity->attribute_changes['attributes'])->toHaveKey('name')
        ->and($activity->attribute_changes['attributes'])->toHaveKey('is_active')
        ->and($activity->attribute_changes['attributes']['name'])->toBe('New Name');
});

test('site creation triggers activity log with 8-year retention', function (): void {
    $customer = Customer::factory()->create([
        'tenant_id' => $this->tenant->id,
        'customer_number' => 'KD-2026-1200',
        'name' => 'Parent Customer',
    ]);
    $site = Site::factory()->create([
        'tenant_id' => $this->tenant->id,
        'customer_id' => $customer->id,
        'site_number' => 'OBJ-2026-1213',
        'name' => 'Observability Site',
    ]);

    $activity = Activity::where('log_name', 'site_management')
        ->where('description', 'created')
        ->where('subject_type', Site::class)
        ->where('subject_id', $site->id)
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->description)->toBe('created')
        ->and($activity->subject_type)->toBe(Site::class)
        ->and($activity->subject_id)->toBe($site->id)
        ->and($activity->tenant_id)->toBe($this->tenant->id)
        ->and($activity->properties['subject_name'])->toBe('Observability Site')
        ->and($activity->properties['subject_identifier'])->toBe('OBJ-2026-1213')
        ->and(Activity::getRetentionYearsForLogType($activity->log_name))->toBeGreaterThanOrEqual(3);
});

test('customer and site deletion logs keep searchable identifiers', function (): void {
    $customer = Customer::factory()->create([
        'tenant_id' => $this->tenant->id,
        'customer_number' => 'KD-2026-1300',
        'name' => 'Deleted Customer',
    ]);
    $site = Site::factory()->create([
        'tenant_id' => $this->tenant->id,
        'customer_id' => $customer->id,
        'site_number' => 'OBJ-2026-1300',
        'name' => 'Deleted Site',
    ]);

    Activity::whereIn('log_name', ['customer_changes', 'site_management'])->delete();

    $site->delete();
    $customer->delete();

    $siteActivity = Activity::where('log_name', 'site_management')
        ->where('subject_type', Site::class)
        ->where('subject_id', $site->id)
        ->first();
    $customerActivity = Activity::where('log_name', 'customer_changes')
        ->where('subject_type', Customer::class)
        ->where('subject_id', $customer->id)
        ->first();

    expect($siteActivity)->not->toBeNull()
        ->and($siteActivity->description)->toBe('deleted')
        ->and($siteActivity->properties['subject_name'])->toBe('Deleted Site')
        ->and($siteActivity->properties['subject_identifier'])->toBe('OBJ-2026-1300')
        ->and($siteActivity->attribute_changes['old']['name'])->toBe('Deleted Site');

    expect($customerActivity)->not->toBeNull()
        ->and($customerActivity->description)->toBe('deleted')
        ->and($customerActivity->properties['subject_name'])->toBe('Deleted Customer')
        ->and($customerActivity->properties['subject_identifier'])->toBe('KD-2026-1300')
        ->and($customerActivity->attribute_changes['old']['name'])->toBe('Deleted Customer');
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
        ->and($activity->attribute_changes['attributes'])->toHaveKey('is_active')
        ->and($activity->attribute_changes['attributes'])->toHaveKey('address')
        ->and($activity->attribute_changes['attributes']['is_active'])->toBeFalse();
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

test('employee update with sensitive and non-sensitive fields creates properly chained logs', function (): void {
    // This test specifically addresses the race condition bug where:
    // - Spatie LogsActivity creates log for non-sensitive fields (e.g., management_level)
    // - Employee.booted() creates GDPR log for sensitive fields (e.g., email)
    // - Both logs are created in the same request
    // - Without proper synchronization, the second log could get NULL previous_hash
    //
    // Expected behavior:
    // - Both logs should be properly chained
    // - GDPR log should link to Spatie log via previous_hash

    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'email' => 'original@example.com',
        'management_level' => 5,
    ]);

    // Clear creation log
    Activity::where('log_name', 'employee_changes')->delete();

    // Update BOTH sensitive (email) AND non-sensitive (management_level) fields
    // This triggers both Spatie LogsActivity and Employee GDPR observer
    $employee->update([
        'email' => 'updated@example.com',
        'management_level' => 6,
    ]);

    // Fetch the two logs created
    $activities = Activity::where('log_name', 'employee_changes')
        ->orderBy('id')
        ->get();

    expect($activities)->toHaveCount(2);

    // First log: Spatie's "updated" log for management_level
    $spatieLog = $activities[0];
    expect($spatieLog->description)->toBe('updated')
        ->and($spatieLog->attribute_changes)->toHaveKey('attributes')
        ->and($spatieLog->attribute_changes['attributes'])->toHaveKey('management_level')
        ->and($spatieLog->event_hash)->not->toBeNull('Spatie log should have event_hash');

    // Second log: GDPR log for email change
    $gdprLog = $activities[1];
    expect($gdprLog->description)->toContain('Sensitive data changed')
        ->and($gdprLog->properties)->toHaveKey('changed_fields')
        ->and($gdprLog->properties['changed_fields'])->toContain('email')
        ->and($gdprLog->event_hash)->not->toBeNull('GDPR log should have event_hash');

    // CRITICAL: GDPR log must link to Spatie log (no NULL previous_hash race condition!)
    expect($gdprLog->previous_hash)->not->toBeNull('GDPR log must link to previous log')
        ->and($gdprLog->previous_hash)->toBe($spatieLog->event_hash, 'GDPR log must link to Spatie log');

    // Verify hash chain integrity
    expect($spatieLog->verifyChain())->toBeTrue('Spatie log hash chain should be valid')
        ->and($gdprLog->verifyChain())->toBeTrue('GDPR log hash chain should be valid')
        ->and($gdprLog->verifyChainLink())->toBeTrue('GDPR log chain link should be valid');
});

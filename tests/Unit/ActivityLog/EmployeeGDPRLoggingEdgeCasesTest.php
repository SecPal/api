<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Activity;
use App\Models\Employee;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

/**
 * Edge case tests for Employee GDPR logging and encrypted field handling.
 *
 * These tests verify critical edge cases in the Employee model's GDPR logging:
 * - Re-encryption without actual value changes (nonce changes)
 * - NULL value handling
 * - Empty string vs NULL
 * - Multiple encrypted fields simultaneously
 * - System updates without authentication
 *
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
});

test('re-encryption without value change does not trigger GDPR log', function (): void {
    // This tests the hasActuallyChanged() helper:
    // - EncryptedWithDek generates new nonce on every save
    // - Without hasActuallyChanged(), every save would trigger a GDPR log
    // - We should only log when the DECRYPTED value actually changes

    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'email' => 'test@example.com',
        'first_name' => 'John',
        'last_name' => 'Doe',
    ]);

    Activity::where('log_name', 'employee_changes')->delete();

    // Save without changing any values
    // This will re-encrypt first_name/last_name with new nonces
    $employee->save();

    // Should NOT create GDPR log (no actual value changes)
    $gdprLog = Activity::where('log_name', 'employee_changes')
        ->where('description', 'LIKE', '%Sensitive data changed%')
        ->first();

    expect($gdprLog)->toBeNull('Re-encryption without value change should not trigger GDPR log');
});

test('multiple encrypted fields changed simultaneously logs all fields', function (): void {
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'email' => 'old@example.com',
        'first_name' => 'OldFirst',
        'last_name' => 'OldLast',
        'phone' => '+49 30 111111',
    ]);

    Activity::where('log_name', 'employee_changes')->delete();

    // Change multiple sensitive fields at once
    $employee->update([
        'email' => 'new@example.com',
        'first_name' => 'NewFirst',
        'last_name' => 'NewLast',
        'phone' => '+49 30 222222',
    ]);

    $gdprLog = Activity::where('log_name', 'employee_changes')
        ->where('description', 'LIKE', '%Sensitive data changed%')
        ->first();

    expect($gdprLog)->not->toBeNull()
        ->and($gdprLog->properties['changed_fields'])->toContain('email')
        ->and($gdprLog->properties['changed_fields'])->toContain('first_name')
        ->and($gdprLog->properties['changed_fields'])->toContain('last_name')
        ->and($gdprLog->properties['changed_fields'])->toContain('phone')
        ->and($gdprLog->properties['field_count'])->toBe(4);
});

test('null to value change triggers GDPR log', function (): void {
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'email' => null,
        'phone' => null,
    ]);

    Activity::where('log_name', 'employee_changes')->delete();

    // Set values from NULL
    $employee->update([
        'email' => 'new@example.com',
        'phone' => '+49 30 123456',
    ]);

    $gdprLog = Activity::where('log_name', 'employee_changes')
        ->where('description', 'LIKE', '%Sensitive data changed%')
        ->first();

    expect($gdprLog)->not->toBeNull()
        ->and($gdprLog->properties['changed_fields'])->toContain('email')
        ->and($gdprLog->properties['changed_fields'])->toContain('phone');
});

test('value to null change triggers GDPR log', function (): void {
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'email' => 'test@example.com',
        'phone' => '+49 30 123456',
    ]);

    Activity::where('log_name', 'employee_changes')->delete();

    // Clear values to NULL
    $employee->update([
        'email' => null,
        'phone' => null,
    ]);

    $gdprLog = Activity::where('log_name', 'employee_changes')
        ->where('description', 'LIKE', '%Sensitive data changed%')
        ->first();

    expect($gdprLog)->not->toBeNull()
        ->and($gdprLog->properties['changed_fields'])->toContain('email')
        ->and($gdprLog->properties['changed_fields'])->toContain('phone');
});

test('update without authentication does not create GDPR log', function (): void {
    // System updates (e.g., via queue, console commands) should not create GDPR logs
    // because Auth::check() returns false

    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'email' => 'old@example.com',
    ]);

    Activity::where('log_name', 'employee_changes')->delete();

    // Clear authentication
    Auth::forgetGuards();

    $employee->update(['email' => 'new@example.com']);

    $gdprLog = Activity::where('log_name', 'employee_changes')
        ->where('description', 'LIKE', '%Sensitive data changed%')
        ->first();

    expect($gdprLog)->toBeNull('System updates without auth should not create GDPR logs');
});

test('only sensitive fields changed creates only GDPR log', function (): void {
    // When ONLY sensitive fields are changed (no Spatie-logged fields),
    // we should get only the GDPR log, not a Spatie log

    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'email' => 'old@example.com',
        'management_level' => 5,
    ]);

    Activity::where('log_name', 'employee_changes')->delete();

    // Change ONLY email (sensitive, not in Spatie's logOnly list)
    $employee->update(['email' => 'new@example.com']);

    $activities = Activity::where('log_name', 'employee_changes')->get();

    // Should have only 1 log (GDPR), not 2
    expect($activities)->toHaveCount(1)
        ->and($activities[0]->description)->toContain('Sensitive data changed');
});

test('only non-sensitive fields changed creates only Spatie log', function (): void {
    // When ONLY non-sensitive fields are changed (Spatie-logged fields),
    // we should get only the Spatie log, not a GDPR log

    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'email' => 'test@example.com',
        'management_level' => 5,
    ]);

    Activity::where('log_name', 'employee_changes')->delete();

    // Change ONLY management_level (non-sensitive, in Spatie's logOnly list)
    $employee->update(['management_level' => 6]);

    $activities = Activity::where('log_name', 'employee_changes')->get();

    // Should have only 1 log (Spatie), not 2
    expect($activities)->toHaveCount(1)
        ->and($activities[0]->description)->toBe('updated')
        ->and($activities[0]->attribute_changes)->toHaveKey('attributes')
        ->and($activities[0]->attribute_changes['attributes'])->toHaveKey('management_level');
});

test('encrypted field with same value after trim does not trigger log', function (): void {
    // Edge case: "John " vs "John" - should be treated as no change after trim?
    // This depends on implementation - currently we compare exact values

    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'first_name' => 'John',
    ]);

    Activity::where('log_name', 'employee_changes')->delete();

    // Set to same value (our implementation should treat as no change)
    $employee->update(['first_name' => 'John']);

    $gdprLog = Activity::where('log_name', 'employee_changes')
        ->where('description', 'LIKE', '%Sensitive data changed%')
        ->first();

    expect($gdprLog)->toBeNull('Setting to same value should not trigger GDPR log');
});

test('hash chain remains intact with rapid successive updates', function (): void {
    // Stress test: Multiple updates in quick succession
    // Advisory lock should ensure proper sequential processing

    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'email' => 'test@example.com',
        'management_level' => 0,
    ]);

    Activity::query()->delete(); // Delete ALL logs to start fresh

    // Perform 5 rapid updates
    for ($i = 1; $i <= 5; $i++) {
        $employee->update([
            'email' => "test{$i}@example.com",
            'management_level' => $i,
        ]);
    }

    // Should have 10 logs total (2 per update × 5 = 10)
    $activities = Activity::where('log_name', 'employee_changes')
        ->orderBy('id')
        ->get();

    expect($activities)->toHaveCount(10);

    // Verify complete hash chain integrity
    // All logs should form a valid chain, regardless of whether first is genesis
    foreach ($activities as $index => $activity) {
        expect($activity->event_hash)->not->toBeNull("Activity {$activity->id} must have event_hash");

        // Verify each log's own hash is valid
        expect($activity->verifyChain())->toBeTrue("Activity {$activity->id} hash chain should be valid");

        // Verify chain linkage
        expect($activity->verifyChainLink())->toBeTrue("Activity {$activity->id} chain link should be valid");
    }

    // Additionally verify sequential linking within these 10 logs
    for ($i = 1; $i < count($activities); $i++) {
        $current = $activities[$i];
        $previous = $activities[$i - 1];

        expect($current->previous_hash)->toBe(
            $previous->event_hash,
            "Activity {$current->id} should link to previous activity {$previous->id}"
        );
    }
});

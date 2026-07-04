<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

use App\Models\Activity;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * @property TenantKey $tenant
 * @property User $user
 */
uses(RefreshDatabase::class);

/**
 * Activity Log Integration Tests.
 *
 * Tests Activity model integration with direct model usage.
 * Note: Tests use Activity::create() directly instead of activity() helper
 * to avoid UUID polymorphic morph binding issues in the test environment.
 */
beforeEach(function () {
    $this->tenant = TenantKey::factory()->create();
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
});

// ============================================================================
// Direct Model Usage (Hash Chain & Auto-Injection)
// ============================================================================

test('activity log creates proper hash chain with direct model usage', function () {
    // Create first activity
    $log1 = Activity::create([
        'log_name' => 'default',
        'description' => 'First log',
        'properties' => ['key' => 'value'],
        'tenant_id' => $this->user->tenant_id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'TestBrowser',
    ]);

    // Refresh model after dispatchSync() updated DB
    $log1->refresh();

    expect($log1->exists)->toBeTrue()
        ->and($log1->description)->toBe('First log')
        ->and($log1->tenant_id)->toBe($this->tenant->id)
        ->and($log1->previous_hash)->toBeNull()
        ->and($log1->event_hash)->not->toBeNull();

    // Create second activity - should link to first
    $log2 = Activity::create([
        'log_name' => 'default',
        'description' => 'Second log',
        'properties' => [],
        'tenant_id' => $this->user->tenant_id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'TestBrowser',
    ]);

    // Refresh model after dispatchSync() updated DB
    $log2->refresh();

    expect($log2->previous_hash)->toBe($log1->event_hash)
        ->and($log2->event_hash)->not->toBe($log1->event_hash);
});

test('activity log with named log type gets correct retention period', function () {
    $log = Activity::create([
        'log_name' => 'authentication',
        'description' => 'User login',
        'tenant_id' => $this->user->tenant_id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'TestBrowser',
    ]);

    expect(Activity::getRetentionYearsForLogType('authentication'))->toBe(3)
        ->and($log->exists)->toBeTrue()
        ->and($log->log_name)->toBe('authentication');
});

// ============================================================================
// Multi-Tenant Isolation
// ============================================================================

test('activity logs are isolated by tenant', function () {
    $tenant2 = TenantKey::factory()->create();
    $user2 = User::factory()->create(['tenant_id' => $tenant2->id]);

    Activity::create([
        'log_name' => 'default',
        'description' => 'Tenant 1 log',
        'tenant_id' => $this->user->tenant_id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'TestBrowser',
    ]);

    Activity::create([
        'log_name' => 'default',
        'description' => 'Tenant 2 log',
        'tenant_id' => $user2->tenant_id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'TestBrowser',
    ]);

    $tenant1Logs = Activity::where('tenant_id', $this->tenant->id)->get();
    $tenant2Logs = Activity::where('tenant_id', $tenant2->id)->get();

    expect($tenant1Logs)->toHaveCount(1)
        ->and($tenant2Logs)->toHaveCount(1)
        ->and($tenant1Logs->first()->description)->toBe('Tenant 1 log')
        ->and($tenant2Logs->first()->description)->toBe('Tenant 2 log');
});

test('hash chains are isolated by tenant', function () {
    $tenant2 = TenantKey::factory()->create();
    $user2 = User::factory()->create(['tenant_id' => $tenant2->id]);

    // Tenant 1: Create 2 logs
    Activity::create([
        'log_name' => 'default',
        'description' => 'Tenant 1 - Log 1',
        'tenant_id' => $this->user->tenant_id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'TestBrowser',
    ]);

    Activity::create([
        'log_name' => 'default',
        'description' => 'Tenant 1 - Log 2',
        'tenant_id' => $this->user->tenant_id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'TestBrowser',
    ]);

    // Tenant 2: Create 1 log (should be genesis despite Tenant 1 logs existing)
    Activity::create([
        'log_name' => 'default',
        'description' => 'Tenant 2 - Log 1',
        'tenant_id' => $user2->tenant_id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'TestBrowser',
    ]);

    $tenant1Logs = Activity::where('tenant_id', $this->tenant->id)->orderBy('id')->get();
    $tenant2Logs = Activity::where('tenant_id', $tenant2->id)->get();

    // Tenant 1: Second log should link to first
    expect($tenant1Logs[0]->previous_hash)->toBeNull()
        ->and($tenant1Logs[1]->previous_hash)->toBe($tenant1Logs[0]->event_hash);

    // Tenant 2: First log should be genesis (null previous_hash)
    expect($tenant2Logs[0]->previous_hash)->toBeNull();
});

// ============================================================================
// Soft Delete Behavior
// ============================================================================

test('soft deleted logs maintain chain for subsequent logs', function () {
    $log1 = Activity::create([
        'log_name' => 'default',
        'description' => 'Log 1',
        'tenant_id' => $this->user->tenant_id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'TestBrowser',
    ]);

    $log1->delete();

    $log2 = Activity::create([
        'log_name' => 'default',
        'description' => 'Log 2',
        'tenant_id' => $this->user->tenant_id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'TestBrowser',
    ]);

    // Log 2 should still link to deleted Log 1's hash
    expect($log2->previous_hash)->toBe($log1->event_hash);
});

test('deleted activities are permanently removed (GDPR Art. 17)', function () {
    $log1 = Activity::create([
        'log_name' => 'default',
        'description' => 'Log 1',
        'tenant_id' => $this->user->tenant_id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'TestBrowser',
    ]);

    $log1->delete();

    Activity::create([
        'log_name' => 'default',
        'description' => 'Log 2',
        'tenant_id' => $this->user->tenant_id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'TestBrowser',
    ]);

    // Hard Delete: Deleted activities are gone permanently
    expect(Activity::all())->toHaveCount(1)
        ->and(Activity::find($log1->id))->toBeNull();
});

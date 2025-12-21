<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use App\Models\Activity;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    // Create tenant for tests
    $this->tenant = TenantKey::factory()->create();
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
});

// ============================================================================
// Hash Chain Tests
// ============================================================================

test('genesis log has null previous hash', function () {
    $this->actingAs($this->user);

    $log = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'default',
        'description' => 'First log',
    ]);

    expect($log->previous_hash)->toBeNull()
        ->and($log->event_hash)->not->toBeNull()
        ->and($log->event_hash)->toHaveLength(64); // SHA256 = 64 hex chars
});

test('second log links to first via hash chain', function () {
    $this->actingAs($this->user);

    $log1 = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'default',
        'description' => 'First log',
    ]);

    $log2 = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'default',
        'description' => 'Second log',
    ]);

    expect($log2->previous_hash)->toBe($log1->event_hash)
        ->and($log2->event_hash)->not->toBe($log1->event_hash);
});

test('hash chain is deterministic', function () {
    $this->actingAs($this->user);

    $log = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'authentication',
        'description' => 'User login',
        'properties' => ['ip' => '192.168.1.1'],
    ]);

    // Recalculate hash manually
    $logData = json_encode([
        'tenant_id' => $log->tenant_id,
        'log_name' => $log->log_name,
        'description' => $log->description,
        'subject_type' => $log->subject_type,
        'subject_id' => $log->subject_id,
        'causer_type' => $log->causer_type,
        'causer_id' => $log->causer_id,
        'properties' => $log->properties,
    ]);

    $expectedHash = hash('sha256', ($log->previous_hash ?? '').$logData);

    expect($log->event_hash)->toBe($expectedHash);
});

test('hash chain respects tenant isolation', function () {
    $tenant2 = TenantKey::factory()->create();
    $user2 = User::factory()->create(['tenant_id' => $tenant2->id]);

    // Create log for tenant 1
    $this->actingAs($this->user);
    $log1 = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'default',
        'description' => 'Tenant 1 log',
    ]);

    // Create log for tenant 2 (should be genesis)
    $this->actingAs($user2);
    $log2 = Activity::create([
        'tenant_id' => $tenant2->id,
        'log_name' => 'default',
        'description' => 'Tenant 2 log',
    ]);

    expect($log1->previous_hash)->toBeNull() // Tenant 1 genesis
        ->and($log2->previous_hash)->toBeNull() // Tenant 2 genesis
        ->and($log2->event_hash)->not->toBe($log1->event_hash);
});

// ============================================================================
// Auto-Injection Tests
// ============================================================================

test('tenant id auto-injected from authenticated user', function () {
    $this->actingAs($this->user);

    $log = Activity::create([
        'log_name' => 'default',
        'description' => 'Test log',
    ]);

    expect($log->tenant_id)->toBe($this->tenant->id);
});

test('ip address auto-captured from request', function () {
    $this->actingAs($this->user);

    $log = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'authentication',
        'description' => 'User login',
    ]);

    expect($log->ip_address)->not->toBeNull();
});

test('user agent auto-captured from request', function () {
    $this->actingAs($this->user);

    $log = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'authentication',
        'description' => 'User login',
    ]);

    expect($log->user_agent)->not->toBeNull();
});

// ============================================================================
// Security Level Tests
// ============================================================================

test('security level determination works correctly', function () {
    expect(Activity::getSecurityLevel('default'))->toBe(1)
        ->and(Activity::getSecurityLevel('employee_changes'))->toBe(1)
        ->and(Activity::getSecurityLevel('shift_management'))->toBe(1)
        ->and(Activity::getSecurityLevel('authentication'))->toBe(2)
        ->and(Activity::getSecurityLevel('security'))->toBe(2)
        ->and(Activity::getSecurityLevel('rbac_changes'))->toBe(2)
        ->and(Activity::getSecurityLevel('hr_access'))->toBe(3)
        ->and(Activity::getSecurityLevel('contract_change'))->toBe(3)
        ->and(Activity::getSecurityLevel('guard_book_event'))->toBe(3);
});

test('unknown log types default to level 1', function () {
    expect(Activity::getSecurityLevel('unknown_log_type'))->toBe(1);
});

test('deprecated emergency access log type returns level 3', function () {
    expect(Activity::getSecurityLevel('emergency_access'))->toBe(3);
});

// ============================================================================
// Chain Verification Tests
// ============================================================================

test('genesis log chain verification passes', function () {
    $this->actingAs($this->user);

    $log = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'default',
        'description' => 'Genesis log',
    ]);

    expect($log->verifyChain())->toBeTrue();
});

test('valid chain verification passes', function () {
    $this->actingAs($this->user);

    $log1 = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'default',
        'description' => 'First log',
    ]);

    $log2 = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'default',
        'description' => 'Second log',
    ]);

    expect($log1->verifyChain())->toBeTrue()
        ->and($log2->verifyChain())->toBeTrue();
});

test('tampered event hash fails chain verification', function () {
    $this->actingAs($this->user);

    $log1 = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'default',
        'description' => 'First log',
    ]);

    $log2 = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'default',
        'description' => 'Second log',
    ]);

    // Tamper with log2's event_hash (breaks chain integrity)
    DB::table('activity_log')
        ->where('id', $log2->id)
        ->update(['event_hash' => 'TAMPERED_HASH_VALUE_AAAAAAA']);

    $log2->refresh();

    expect($log2->verifyChain())->toBeFalse();
});

test('orphaned genesis log verification passes', function () {
    $this->actingAs($this->user);

    $log1 = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'default',
        'description' => 'First log',
    ]);

    $log2 = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'default',
        'description' => 'Second log',
    ]);

    // Delete log1 and mark log2 as orphaned genesis
    $log1->forceDelete();
    $log2->update([
        'is_orphaned_genesis' => true,
        'orphaned_reason' => 'Previous log deleted due to retention policy',
        'orphaned_at' => now(),
    ]);

    expect($log2->verifyChain())->toBeTrue();
});

// ============================================================================
// Relationships Tests
// ============================================================================

test('activity belongs to tenant', function () {
    $this->actingAs($this->user);

    $log = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'default',
        'description' => 'Test log',
    ]);

    expect($log->tenant)->toBeInstanceOf(TenantKey::class)
        ->and($log->tenant->id)->toBe($this->tenant->id);
});

// ============================================================================
// Soft Delete Tests
// ============================================================================

test('soft deleted logs still maintain chain integrity', function () {
    $this->actingAs($this->user);

    $log1 = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'default',
        'description' => 'First log',
    ]);

    $log2 = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'default',
        'description' => 'Second log',
    ]);

    $log3 = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'default',
        'description' => 'Third log',
    ]);

    // Soft delete log2
    $log2->delete();

    // log3 should still verify (finds soft-deleted log2)
    expect($log3->verifyChain())->toBeTrue();
});

// ============================================================================
// Merkle Proof Tests (Stub Verification)
// ============================================================================

test('merkle proof returns false without data', function () {
    $this->actingAs($this->user);

    $log = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'authentication',
        'description' => 'Test log',
    ]);

    expect($log->verifyMerkleProof())->toBeFalse();
});

test('merkle proof placeholder returns true with data', function () {
    $this->actingAs($this->user);

    $log = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'authentication',
        'description' => 'Test log',
        'merkle_root' => hash('sha256', 'root'),
        'merkle_proof' => [['hash' => hash('sha256', 'sibling'), 'position' => 'left']],
    ]);

    // Placeholder implementation returns true
    expect($log->verifyMerkleProof())->toBeTrue();
});

// ============================================================================
// OpenTimestamp Tests (Stub Verification)
// ============================================================================

test('opentimestamp returns false without data', function () {
    $this->actingAs($this->user);

    $log = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'hr_access',
        'description' => 'Test log',
    ]);

    expect($log->verifyOpenTimestamp())->toBeFalse();
});

test('opentimestamp placeholder returns true with data', function () {
    $this->actingAs($this->user);

    $log = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'hr_access',
        'description' => 'Test log',
        'ots_proof' => 'STUB_PROOF',
        'ots_confirmed_at' => now(),
    ]);

    // Placeholder implementation returns true
    expect($log->verifyOpenTimestamp())->toBeTrue();
});

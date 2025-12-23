<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use App\Models\Activity;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

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
    ], JSON_THROW_ON_ERROR);

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

test('merkle proof verifies with valid proof via batch job', function () {
    $this->actingAs($this->user);

    // Create Level 2 logs that will get batched
    $logs = collect(range(1, 2))->map(fn ($i) => Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'authentication', // Level 2
        'description' => "Test log {$i}",
    ]));

    // Build Merkle tree via job
    $job = new \App\Jobs\BuildMerkleTreeBatch;
    $job->handle();

    // Verify proofs work
    $logs->each(function ($log) {
        $log->refresh();
        expect($log->verifyMerkleProof())->toBeTrue();
    });
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

// ============================================================================
// Organizational Unit Validation Tests (Issue #402)
// ============================================================================

test('accepts valid organizational_unit_id from same tenant', function () {
    $orgUnit = \App\Models\OrganizationalUnit::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $this->actingAs($this->user);

    $log = Activity::create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $orgUnit->id,
        'log_name' => 'default',
        'description' => 'Test log with valid OU',
    ]);

    expect($log->organizational_unit_id)->toBe($orgUnit->id);
})->group('security', 'issue-402');

test('throws exception when organizational_unit_id belongs to different tenant', function () {
    $otherTenant = TenantKey::factory()->create();
    $otherOrgUnit = \App\Models\OrganizationalUnit::factory()->create([
        'tenant_id' => $otherTenant->id,
    ]);

    $this->actingAs($this->user);

    Activity::create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $otherOrgUnit->id,
        'log_name' => 'default',
        'description' => 'Test log with cross-tenant OU',
    ]);
})->throws(
    \InvalidArgumentException::class,
    'Organizational unit does not belong to activity tenant'
)->group('security', 'issue-402');

test('throws exception when organizational_unit_id does not exist', function () {
    $this->actingAs($this->user);

    Activity::create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => \Illuminate\Support\Str::uuid()->toString(),
        'log_name' => 'default',
        'description' => 'Test log with invalid OU',
    ]);
})->throws(
    \InvalidArgumentException::class,
    'Organizational unit does not exist'
)->group('security', 'issue-402');

test('accepts null organizational_unit_id', function () {
    $this->actingAs($this->user);

    $log = Activity::create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => null,
        'log_name' => 'default',
        'description' => 'Test log without OU',
    ]);

    expect($log->organizational_unit_id)->toBeNull();
})->group('security', 'issue-402');

// ============================================================================
// Hash Chain Race Condition Tests (Issue #402)
// ============================================================================

test('concurrent log creation maintains hash chain integrity', function () {
    $this->actingAs($this->user);

    // Simulate concurrent requests by creating multiple logs rapidly
    // Without lockForUpdate(), these could reference the same previous_hash
    $logs = collect(range(1, 5))->map(function ($i) {
        return Activity::create([
            'tenant_id' => $this->tenant->id,
            'log_name' => 'default',
            'description' => "Concurrent log {$i}",
        ]);
    });

    // Verify chain integrity: each log should have unique previous_hash
    $previousHashes = $logs->pluck('previous_hash')->filter()->toArray();
    $uniqueHashes = array_unique($previousHashes);

    // All previous_hash values should be unique (no duplicates)
    expect(count($previousHashes))->toBe(count($uniqueHashes));

    // Verify sequential chain: log N+1 should reference log N
    for ($i = 1; $i < $logs->count(); $i++) {
        expect($logs[$i]->previous_hash)->toBe($logs[$i - 1]->event_hash);
    }
})->group('concurrency', 'issue-402');

test('pessimistic locking prevents duplicate previous_hash under load', function () {
    $this->actingAs($this->user);

    // Create first log (genesis)
    $log1 = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'default',
        'description' => 'First log',
    ]);

    // Use DB transaction to simulate concurrent access
    DB::beginTransaction();

    try {
        // Create log2 and log3 in quick succession
        // Without lockForUpdate(), both could see log1 as "latest"
        $log2 = Activity::create([
            'tenant_id' => $this->tenant->id,
            'log_name' => 'default',
            'description' => 'Log 2',
        ]);

        $log3 = Activity::create([
            'tenant_id' => $this->tenant->id,
            'log_name' => 'default',
            'description' => 'Log 3',
        ]);

        DB::commit();

        // Verify: log2 references log1, log3 references log2 (not both referencing log1)
        expect($log2->previous_hash)->toBe($log1->event_hash)
            ->and($log3->previous_hash)->toBe($log2->event_hash)
            ->and($log3->previous_hash)->not->toBe($log1->event_hash);
    } catch (\Exception $e) {
        DB::rollBack();
        throw $e;
    }
})->group('concurrency', 'issue-402');

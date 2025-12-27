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

/**
 * @property TenantKey $tenant
 * @property User $user
 */
uses(RefreshDatabase::class);

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

    // Refresh model after dispatchSync() updated DB directly
    $log->refresh();

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

    // Refresh both models after dispatchSync() updated DB
    $log1->refresh();
    $log2->refresh();

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

    // Refresh model after dispatchSync() updated DB
    $log->refresh();

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

    // Refresh both models after dispatchSync() updated DB
    $log1->refresh();
    $log2->refresh();

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

test('opentimestamp verification works with valid proof', function () {
    $this->actingAs($this->user);

    // Create a valid-looking OTS proof (simplified for testing)
    // Real OTS proofs have complex binary structure with Bitcoin attestation
    $merkleRoot = hash('sha256', 'test-root');
    $validProof = hex2bin('0588960d73d7190103'.bin2hex($merkleRoot));

    $log = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'hr_access',
        'description' => 'Test log',
        'merkle_root' => $merkleRoot,
        'ots_proof' => $validProof,
        'ots_confirmed_at' => now(),
    ]);

    // Mock ProcessExecutor to simulate successful CLI verification
    $mockExecutor = Mockery::mock(\App\Contracts\ProcessExecutor::class);
    $mockExecutor->shouldReceive('commandExists')
        ->with('ots')
        ->once()
        ->andReturn(true);

    $mockExecutor->shouldReceive('execute')
        ->once()
        ->withArgs(function ($command, $stdin, $timeout) use ($merkleRoot, $validProof) {
            return $command === ['ots', 'verify', '--digest', $merkleRoot]
                && $stdin === $validProof
                && $timeout === 10;
        })
        ->andReturn([
            'exitCode' => 0,
            'stdout' => 'Success! Bitcoin attests data existed as of 2025-12-24',
            'stderr' => '',
        ]);

    app()->instance(\App\Contracts\ProcessExecutor::class, $mockExecutor);

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

    try {
        Activity::create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => $otherOrgUnit->id,
            'log_name' => 'default',
            'description' => 'Test log with cross-tenant OU',
        ]);
        $this->fail('Expected InvalidArgumentException was not thrown');
    } catch (\InvalidArgumentException $e) {
        expect($e->getMessage())->toMatch(
            "/Organizational unit '.*' belongs to tenant '.*' but activity log belongs to tenant '.*'/"
        );
        // Verify specific IDs are in the message
        expect($e->getMessage())->toContain($otherOrgUnit->id)
            ->toContain($otherTenant->id)
            ->toContain($this->tenant->id);
    }
})->group('security', 'issue-402');

test('throws exception when organizational_unit_id does not exist', function () {
    $this->actingAs($this->user);

    $nonExistentOuId = \Illuminate\Support\Str::uuid()->toString();

    expect(fn () => Activity::create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $nonExistentOuId,
        'log_name' => 'default',
        'description' => 'Test log with invalid OU',
    ]))->toThrow(
        \InvalidArgumentException::class,
        "Organizational unit '{$nonExistentOuId}' does not exist"
    );
})->group('security', 'issue-402');

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

// Note: True concurrency testing requires spawning parallel processes/connections,
// which is complex to test reliably in unit tests. In contrast to the
// Customer::generateCustomerNumber() and Site::generateSiteNumber() helpers
// (which wrap the entire operation, including the INSERT, in a transaction
// with lockForUpdate()), the Activity hash-chain logic runs in a "creating"
// model hook before the INSERT executes, so a race window still exists.
//
// The following test verifies sequential chain integrity (baseline requirement).
// For production validation, monitor Activity logs for broken chains.
// Epic #408 will refactor to queue-based sequential processing (100% race-free).

test('sequential log creation maintains hash chain integrity', function () {
    $this->actingAs($this->user);

    // Create logs sequentially (baseline test)
    // Each log should properly chain to the previous one
    $logs = collect(range(1, 5))->map(function ($i) {
        return Activity::create([
            'tenant_id' => $this->tenant->id,
            'log_name' => 'default',
            'description' => "Sequential log {$i}",
        ]);
    });

    // Refresh all models after dispatchSync() updated DB
    $logs = $logs->map(fn ($log) => $log->refresh());

    // Verify chain integrity: each log references previous log's hash
    expect($logs[0]->previous_hash)->toBeNull(); // Genesis log

    for ($i = 1; $i < $logs->count(); $i++) {
        expect($logs[$i]->previous_hash)
            ->toBe($logs[$i - 1]->event_hash)
            ->and($logs[$i]->event_hash)
            ->not->toBeNull();
    }

    // Verify all hashes are unique
    $allHashes = $logs->pluck('event_hash')->toArray();
    expect(count($allHashes))->toBe(count(array_unique($allHashes)));
})->group('hash-chain', 'issue-402');

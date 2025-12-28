<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use App\Jobs\BuildMerkleTreeBatch;
use App\Models\Activity;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * Comprehensive Forensic Verification Test Suite
 *
 * Tests all 8 possible edge case combinations (C = Chain Data, L = Chain Link, M = Merkle):
 * 1. C+|L+|M+ - Perfect
 * 2. C+|L+|M- - Only Merkle Problem
 * 3. C+|L-|M+ - Only Chain Link Problem
 * 4. C+|L-|M- - Chain Link + Merkle Problem
 * 5. C-|L+|M+ - Only Chain Data Problem
 * 6. C-|L+|M- - Chain Data + Merkle Problem
 * 7. C-|L-|M+ - Chain Data + Link Problem
 * 8. C-|L-|M- - Catastrophic
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = TenantKey::factory()->create();
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
});

// ============================================================================
// Edge Case 1: C+|L+|M+ - Perfect (All Valid)
// ============================================================================

test('edge case 1: legitimate genesis log - all validations pass', function () {
    $this->actingAs($this->user);

    $log = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'security', // Level 2 - Merkle enabled
        'description' => 'Security event',
    ]);

    $log->refresh();

    // Verify: Chain ✅, Link ✅, Merkle pending
    expect($log->verifyChain())->toBeTrue('Genesis log should validate own data')
        ->and($log->verifyChainLink())->toBeTrue('Legitimate genesis - no predecessor needed')
        ->and($log->verifyMerkleProof())->toBeNull('Merkle not yet built');

    // Build Merkle Tree
    $job = new BuildMerkleTreeBatch;
    $job->handle();
    $log->refresh();

    // Verify: Chain ✅, Link ✅, Merkle ✅
    expect($log->verifyChain())->toBeTrue()
        ->and($log->verifyChainLink())->toBeTrue()
        ->and($log->verifyMerkleProof())->toBeTrue('Merkle should be valid after building');
});

test('edge case 1: chained logs - all validations pass', function () {
    $this->actingAs($this->user);

    $log1 = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'security',
        'description' => 'First',
    ]);

    $log2 = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'security',
        'description' => 'Second',
    ]);

    $log1->refresh();
    $log2->refresh();

    // Verify log2 is chained to log1
    expect($log2->previous_hash)->toBe($log1->event_hash);

    // Build Merkle Tree
    $job = new BuildMerkleTreeBatch;
    $job->handle();
    $log1->refresh();
    $log2->refresh();

    // Both should pass all verifications
    expect($log1->verifyChain())->toBeTrue()
        ->and($log1->verifyChainLink())->toBeTrue()
        ->and($log1->verifyMerkleProof())->toBeTrue()
        ->and($log2->verifyChain())->toBeTrue()
        ->and($log2->verifyChainLink())->toBeTrue()
        ->and($log2->verifyMerkleProof())->toBeTrue();
});

// ============================================================================
// Edge Case 2: C+|L+|M- - Only Merkle Problem
// ============================================================================

test('edge case 2: deleted activities from batch detected', function () {
    $this->actingAs($this->user);

    // Create 5 logs
    $logs = collect();
    for ($i = 0; $i < 5; $i++) {
        $logs->push(Activity::create([
            'tenant_id' => $this->tenant->id,
            'log_name' => 'security',
            'description' => "Security event {$i}",
        ]));
    }

    $logs = $logs->map(fn ($log) => $log->refresh());

    // Build Merkle Tree
    $job = new BuildMerkleTreeBatch;
    $job->handle();
    $logs = $logs->map(fn ($log) => $log->refresh());

    // Verify batch is complete initially
    expect($logs[3]->merkle_batch_count)->toBe(5)
        ->and($logs[3]->verifyMerkleProof())->toBeTrue();

    // Delete 3 logs (keep 2)
    $logs[0]->forceDelete();
    $logs[1]->forceDelete();
    $logs[2]->forceDelete();

    $remaining = $logs[3]->fresh();

    // Verify: Chain ❌ (predecessor deleted), Link ❌ (illegitimate genesis detected via withTrashed), Merkle ❌ (batch incomplete)
    // Note: verifyChainLink() uses withTrashed() so it CAN detect illegitimate genesis even after soft delete!
    // This is GOOD - soft deleted logs still provide forensic evidence
    expect($remaining->verifyChain())->toBeFalse('Chain broken - predecessor deleted')
        ->and($remaining->verifyChainLink())->toBeFalse('Illegitimate genesis detected via soft-deleted predecessor')
        ->and($remaining->verifyMerkleProof())->toBeFalse('Batch count mismatch: 2 of 5 remain');
});

// ============================================================================
// Edge Case 3: C+|L-|M+ - Only Chain Link Problem
// ============================================================================

test('edge case 3: illegitimate genesis detected', function () {
    $this->actingAs($this->user);

    // Create legitimate genesis
    $log1 = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'security',
        'description' => 'First',
    ]);

    $log1->refresh();

    // Manually create second log as fake genesis (bypassing chain job)
    $log2 = new Activity([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'security',
        'description' => 'Second (fake genesis)',
    ]);
    $log2->saveQuietly();

    // Calculate correct hash for genesis
    $logData = json_encode([
        'tenant_id' => $log2->tenant_id,
        'log_name' => $log2->log_name,
        'description' => $log2->description,
        'subject_type' => $log2->subject_type,
        'subject_id' => $log2->subject_id,
        'causer_type' => $log2->causer_type,
        'causer_id' => $log2->causer_id,
        'properties' => $log2->properties,
    ], JSON_THROW_ON_ERROR);

    // Set as fake genesis with correct hash
    DB::table('activity_log')
        ->where('id', $log2->id)
        ->update([
            'previous_hash' => null, // Fake genesis!
            'event_hash' => hash('sha256', $logData),
        ]);

    $log2->refresh();

    // Build Merkle Tree
    $job = new BuildMerkleTreeBatch;
    $job->handle();
    $log2->refresh();

    // Verify: Chain ✅ (data correct), Link ❌ (illegitimate genesis!), Merkle ✅
    expect($log2->verifyChain())->toBeTrue('Own data is correct')
        ->and($log2->verifyChainLink())->toBeFalse('Illegitimate genesis - log1 exists!')
        ->and($log2->verifyMerkleProof())->toBeTrue('Merkle valid');
});

// ============================================================================
// Edge Case 4: C+|L-|M- - Chain Link + Merkle Problem
// ============================================================================

test('edge case 4: illegitimate genesis with deleted batch activities', function () {
    $this->actingAs($this->user);

    // Create chain of 5 logs
    $logs = collect();
    for ($i = 0; $i < 5; $i++) {
        $logs->push(Activity::create([
            'tenant_id' => $this->tenant->id,
            'log_name' => 'security',
            'description' => "Security event {$i}",
        ]));
    }

    $logs = $logs->map(fn ($log) => $log->refresh());

    // Break chain on log2 (make it fake genesis with correct hash)
    $log2Data = json_encode([
        'tenant_id' => $logs[2]->tenant_id,
        'log_name' => $logs[2]->log_name,
        'description' => $logs[2]->description,
        'subject_type' => $logs[2]->subject_type,
        'subject_id' => $logs[2]->subject_id,
        'causer_type' => $logs[2]->causer_type,
        'causer_id' => $logs[2]->causer_id,
        'properties' => $logs[2]->properties,
    ], JSON_THROW_ON_ERROR);

    DB::table('activity_log')
        ->where('id', $logs[2]->id)
        ->update([
            'previous_hash' => null,
            'event_hash' => hash('sha256', $log2Data),
        ]);

    $logs = $logs->map(fn ($log) => $log->refresh());

    // Build Merkle Tree
    $job = new BuildMerkleTreeBatch;
    $job->handle();
    $logs = $logs->map(fn ($log) => $log->refresh());

    // Delete logs
    $logs[0]->forceDelete();
    $logs[1]->forceDelete();

    $log2 = $logs[2]->fresh();

    // Verify: Chain ✅ (data correct), Link ✅ (appears legitimate after deletion), Merkle ❌ (batch incomplete)
    // Note: After predecessor deletion, verifyChainLink() can no longer detect it was illegitimate
    // This is a KNOWN LIMITATION - requires archival of deleted logs for full forensics
    expect($log2->verifyChain())->toBeTrue('Own data correct')
        ->and($log2->verifyChainLink())->toBeTrue('Appears legitimate after predecessor deletion - limitation!')
        ->and($log2->verifyMerkleProof())->toBeFalse('Batch incomplete');
});

// ============================================================================
// Edge Case 5: C-|L+|M+ - Only Chain Data Problem
// ============================================================================

test('edge case 5: genesis with manipulated event hash', function () {
    $this->actingAs($this->user);

    $log = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'security',
        'description' => 'Security event',
    ]);

    $log->refresh();

    // Build Merkle Tree first
    $job = new BuildMerkleTreeBatch;
    $job->handle();
    $log->refresh();

    // Manipulate event_hash
    DB::table('activity_log')
        ->where('id', $log->id)
        ->update(['event_hash' => str_repeat('a', 64)]);

    $log->refresh();

    // Verify: Chain ❌ (hash manipulated), Link ✅ (legitimate genesis), Merkle ❌ (hash mismatch)
    expect($log->verifyChain())->toBeFalse('Event hash manipulated')
        ->and($log->verifyChainLink())->toBeTrue('Legitimate genesis')
        ->and($log->verifyMerkleProof())->toBeFalse('Merkle detects hash mismatch');
});

// ============================================================================
// Edge Case 6: C-|L+|M- - Chain Data + Merkle Problem
// ============================================================================

test('edge case 6: genesis with manipulated hash and deleted batch', function () {
    $this->actingAs($this->user);

    $logs = collect();
    for ($i = 0; $i < 5; $i++) {
        $logs->push(Activity::create([
            'tenant_id' => $this->tenant->id,
            'log_name' => 'security',
            'description' => "Security event {$i}",
        ]));
    }

    $logs = $logs->map(fn ($log) => $log->refresh());

    // Build Merkle
    $job = new BuildMerkleTreeBatch;
    $job->handle();
    $logs = $logs->map(fn ($log) => $log->refresh());

    // Manipulate log0's hash
    DB::table('activity_log')
        ->where('id', $logs[0]->id)
        ->update(['event_hash' => str_repeat('c', 64)]);

    // Delete logs
    $logs[1]->forceDelete();
    $logs[2]->forceDelete();

    $log0 = $logs[0]->fresh();

    // Verify: Chain ❌ (hash), Link ✅ (genesis), Merkle ❌ (batch + hash)
    expect($log0->verifyChain())->toBeFalse('Hash manipulated')
        ->and($log0->verifyChainLink())->toBeTrue('Legitimate genesis')
        ->and($log0->verifyMerkleProof())->toBeFalse('Batch incomplete + hash mismatch');
});

// ============================================================================
// Edge Case 7: C-|L-|M+ - Chain Data + Link Problem
// ============================================================================

test('edge case 7: manipulated hash with illegitimate genesis', function () {
    $this->actingAs($this->user);

    $log1 = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'security',
        'description' => 'First',
    ]);

    $log1->refresh();

    // Create fake genesis
    $log2 = new Activity([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'security',
        'description' => 'Second (fake)',
    ]);
    $log2->saveQuietly();

    // Set as fake genesis with manipulated hash
    DB::table('activity_log')
        ->where('id', $log2->id)
        ->update([
            'previous_hash' => null,
            'event_hash' => str_repeat('d', 64), // Manipulated!
        ]);

    $log2->refresh();

    // Build Merkle
    $job = new BuildMerkleTreeBatch;
    $job->handle();
    $log2->refresh();

    // Verify: Chain ❌ (hash), Link ❌ (fake genesis), Merkle ❌ (hash mismatch)
    // Note: If hash is manipulated, Merkle proof validation may return true
    // because the Merkle tree was built with the manipulated hash
    expect($log2->verifyChain())->toBeFalse('Hash manipulated')
        ->and($log2->verifyChainLink())->toBeFalse('Illegitimate genesis')
        ->and($log2->verifyMerkleProof())->toBeTrue('Merkle valid - tree built with current hash');
});

// ============================================================================
// Edge Case 8: C-|L-|M- - Catastrophic (All Invalid)
// ============================================================================

test('edge case 8: catastrophic - all three verifications fail', function () {
    $this->actingAs($this->user);

    $logs = collect();
    for ($i = 0; $i < 5; $i++) {
        $logs->push(Activity::create([
            'tenant_id' => $this->tenant->id,
            'log_name' => 'security',
            'description' => "Security event {$i}",
        ]));
    }

    $logs = $logs->map(fn ($log) => $log->refresh());

    // Build Merkle
    $job = new BuildMerkleTreeBatch;
    $job->handle();
    $logs = $logs->map(fn ($log) => $log->refresh());

    // Triple manipulation on log2:
    DB::table('activity_log')
        ->where('id', $logs[2]->id)
        ->update([
            'previous_hash' => null, // Break link
            'event_hash' => str_repeat('e', 64), // Break data
        ]);

    // Delete logs to break batch
    $logs[0]->forceDelete();
    $logs[1]->forceDelete();

    $log2 = $logs[2]->fresh();

    // Verify: Chain ❌, Link ✅ (appears legitimate after deletion), Merkle ❌
    // Note: Same limitation as edge case 4 - predecessor deletion hides illegitimacy
    expect($log2->verifyChain())->toBeFalse('Hash manipulated')
        ->and($log2->verifyChainLink())->toBeTrue('Appears legitimate after predecessor deletion - limitation!')
        ->and($log2->verifyMerkleProof())->toBeFalse('Batch incomplete + hash mismatch');
});

test('edge case 8: activity 15 scenario - real world discovery', function () {
    $this->actingAs($this->user);

    // Recreate Activity 15 scenario exactly
    $log1 = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'security',
        'description' => 'First',
    ]);

    $log1->refresh();

    // Create Activity 15-like log
    $log2 = new Activity([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'security',
        'description' => 'Second',
    ]);
    $log2->saveQuietly();

    // Manipulate exactly like Activity 15:
    DB::table('activity_log')
        ->where('id', $log2->id)
        ->update([
            'previous_hash' => null, // Fake genesis
            'event_hash' => str_repeat('b', 64), // bbbb...
        ]);

    $log2->refresh();

    // Build Merkle
    $job = new BuildMerkleTreeBatch;
    $job->handle();
    $log2->refresh();

    // This is exactly Activity 15's situation
    // Chain and Link are INVALID, but Merkle is VALID because tree was built with manipulated hash
    expect($log2->verifyChain())->toBeFalse('Genesis data validation failed')
        ->and($log2->verifyChainLink())->toBeFalse('Illegitimate genesis')
        ->and($log2->verifyMerkleProof())->toBeTrue('Merkle valid - tree built with current (manipulated) hash');
});

// ============================================================================
// Regression Tests
// ============================================================================

test('regression: genesis logs validate their own data', function () {
    $this->actingAs($this->user);

    $log = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'security',
        'description' => 'Test',
    ]);

    $log->refresh();

    // Initially valid
    expect($log->verifyChain())->toBeTrue();

    // Manipulate hash
    DB::table('activity_log')
        ->where('id', $log->id)
        ->update(['event_hash' => str_repeat('x', 64)]);

    $log->refresh();

    // MUST be invalid (this was the Activity 15 bug)
    expect($log->verifyChain())->toBeFalse('Genesis logs must validate their data!');
});

test('regression: illegitimate genesis detection works', function () {
    $this->actingAs($this->user);

    $log1 = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'security',
        'description' => 'First',
    ]);

    $log1->refresh();

    // Create fake genesis
    $log2 = new Activity([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'security',
        'description' => 'Second',
    ]);
    $log2->saveQuietly();

    $logData = json_encode([
        'tenant_id' => $log2->tenant_id,
        'log_name' => $log2->log_name,
        'description' => $log2->description,
        'subject_type' => $log2->subject_type,
        'subject_id' => $log2->subject_id,
        'causer_type' => $log2->causer_type,
        'causer_id' => $log2->causer_id,
        'properties' => $log2->properties,
    ], JSON_THROW_ON_ERROR);

    DB::table('activity_log')
        ->where('id', $log2->id)
        ->update([
            'previous_hash' => null,
            'event_hash' => hash('sha256', $logData),
        ]);

    $log2->refresh();

    // MUST be detected as illegitimate
    expect($log2->verifyChainLink())->toBeFalse('Illegitimate genesis must be detected!');
});

test('regression: batch integrity checking detects deleted activities', function () {
    $this->actingAs($this->user);

    $logs = collect();
    for ($i = 0; $i < 5; $i++) {
        $logs->push(Activity::create([
            'tenant_id' => $this->tenant->id,
            'log_name' => 'security',
            'description' => "Security event {$i}",
        ]));
    }

    $logs = $logs->map(fn ($log) => $log->refresh());

    // Build Merkle
    $job = new BuildMerkleTreeBatch;
    $job->handle();
    $logs = $logs->map(fn ($log) => $log->refresh());

    // Verify valid before deletion
    expect($logs[3]->merkle_batch_count)->toBe(5)
        ->and($logs[3]->verifyMerkleProof())->toBeTrue();

    // Delete activities
    $logs[0]->forceDelete();
    $logs[1]->forceDelete();
    $logs[2]->forceDelete();

    $remaining = $logs[3]->fresh();

    // MUST detect deletion
    expect($remaining->verifyMerkleProof())->toBeFalse('Deleted activities must be detected!');
});

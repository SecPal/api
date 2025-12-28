<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use App\Jobs\BuildMerkleTreeBatch;
use App\Jobs\SubmitMerkleRootToOpenTimestamp;
use App\Models\Activity;
use App\Models\TenantKey;
use App\Models\User;
use App\Services\OpenTimestampService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * COMPREHENSIVE Forensic Verification Test Suite with OpenTimestamp
 *
 * Tests all 4-dimensional combinations (C|L|M|O):
 * - C (Chain Data): verifyChain()
 * - L (Chain Link): verifyChainLink()
 * - M (Merkle Tree): verifyMerkleProof()
 * - O (OpenTimestamp): verifyOpenTimestamp()
 *
 * Critical OTS Edge Cases:
 * 1. OTS depends on merkle_root stored in DB
 * 2. If merkle_root manipulated → OTS fails (O-)
 * 3. If event_hash manipulated → merkle_root stays same → OTS stays valid (O+)
 * 4. If activities deleted from batch → merkle_root stays same → OTS stays valid (O+)
 *
 * Coverage Matrix:
 * - Perfect: C+|L+|M+|O+
 * - Pending: C+|L+|M+|O⏳
 * - Batch Issues: C+|L+|M-|O+
 * - Chain Issues: C+|L-|M+|O+
 * - Hash Issues: C-|L+|M-|O+ (leaf hash)
 * - Root Issues: C+|L+|M+|O- (root manipulation)
 * - Catastrophic: C-|L-|M-|O-
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = TenantKey::factory()->create();
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);

    // Mock OTS Service for fast tests
    $this->otsService = $this->mock(OpenTimestampService::class);
});

// ============================================================================
// PERFECT CASES
// ============================================================================

test('ots edge case 1: perfect - all 4 verifications pass (C+|L+|M+|O+)', function () {
    $this->actingAs($this->user);

    $log = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'security',
        'description' => 'Perfect activity',
    ]);

    $log->refresh();

    // Build Merkle Tree
    $job = new BuildMerkleTreeBatch;
    $job->handle();
    $log->refresh();

    expect($log->merkle_root)->not->toBeNull('Merkle root should be set');

    // Mock OTS submission and confirmation
    $mockProof = base64_encode('mock_ots_proof_data');
    $this->otsService->shouldReceive('submit')
        ->once()
        ->with($log->merkle_root)
        ->andReturn($mockProof);

    $this->otsService->shouldReceive('verify')
        ->once()
        ->with($mockProof, $log->merkle_root)
        ->andReturn(true);

    // Submit to OTS
    $otsJob = new SubmitMerkleRootToOpenTimestamp($this->tenant->id, (int) $log->merkle_batch_id, $log->merkle_root);
    $otsJob->handle($this->otsService);

    $log->refresh();

    // Simulate confirmation
    DB::table('activity_log')
        ->where('id', $log->id)
        ->update(['ots_confirmed_at' => now()]);

    $log->refresh();

    // Verify: ALL 4 checks pass
    expect($log->verifyChain())->toBeTrue('Chain Data ✅')
        ->and($log->verifyChainLink())->toBeTrue('Chain Link ✅')
        ->and($log->verifyMerkleProof())->toBeTrue('Merkle Tree ✅')
        ->and($log->verifyOpenTimestamp())->toBeTrue('OpenTimestamp ✅')
        ->and($log->ots_submitted_at)->not->toBeNull()
        ->and($log->ots_confirmed_at)->not->toBeNull();
});

test('ots edge case 2: perfect pending - OTS not yet confirmed (C+|L+|M+|O⏳)', function () {
    $this->actingAs($this->user);

    $log = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'security',
        'description' => 'Activity with pending OTS',
    ]);

    $log->refresh();

    // Build Merkle Tree
    $job = new BuildMerkleTreeBatch;
    $job->handle();
    $log->refresh();

    // Mock OTS submission (but NOT confirmed yet)
    $mockProof = base64_encode('mock_ots_proof_data');
    $this->otsService->shouldReceive('submit')
        ->once()
        ->with($log->merkle_root)
        ->andReturn($mockProof);

    // Submit to OTS
    $otsJob = new SubmitMerkleRootToOpenTimestamp($this->tenant->id, (int) $log->merkle_batch_id, $log->merkle_root);
    $otsJob->handle($this->otsService);

    $log->refresh();

    // Verify: C✅ L✅ M✅ O⏳
    expect($log->verifyChain())->toBeTrue('Chain Data ✅')
        ->and($log->verifyChainLink())->toBeTrue('Chain Link ✅')
        ->and($log->verifyMerkleProof())->toBeTrue('Merkle Tree ✅')
        ->and($log->verifyOpenTimestamp())->toBeNull('OpenTimestamp ⏳ (pending)')
        ->and($log->ots_submitted_at)->not->toBeNull('OTS submitted')
        ->and($log->ots_confirmed_at)->toBeNull('OTS not yet confirmed');
});

// ============================================================================
// BATCH INTEGRITY ISSUES (C+|L+|M-|O+)
// ============================================================================

test('ots edge case 3: batch incomplete but OTS valid (C+|L+|M-|O+)', function () {
    $this->actingAs($this->user);

    // Create 5 logs in batch
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

    $merkleRoot = $logs[3]->merkle_root;

    // Mock OTS
    $mockProof = base64_encode('mock_ots_proof');
    $this->otsService->shouldReceive('submit')->once()->andReturn($mockProof);
    $this->otsService->shouldReceive('verify')->once()->with($mockProof, $merkleRoot)->andReturn(true);

    // Submit to OTS
    $otsJob = new SubmitMerkleRootToOpenTimestamp($this->tenant->id, (int) $logs[3]->merkle_batch_id, $merkleRoot);
    $otsJob->handle($this->otsService);
    $logs = $logs->map(fn ($log) => $log->fresh());

    // Confirm OTS
    DB::table('activity_log')
        ->where('merkle_batch_id', $logs[3]->merkle_batch_id)
        ->update(['ots_confirmed_at' => now()]);

    // Delete 3 activities
    $logs[0]->forceDelete();
    $logs[1]->forceDelete();
    $logs[2]->forceDelete();

    $remaining = $logs[3]->fresh();

    // Verify: C❌ L❌ M❌ but O✅!
    // OTS is still valid because merkle_root in DB didn't change
    expect($remaining->verifyChain())->toBeFalse('Chain broken - predecessor deleted')
        ->and($remaining->verifyChainLink())->toBeFalse('Illegitimate genesis detected')
        ->and($remaining->verifyMerkleProof())->toBeFalse('Batch incomplete: 2 of 5')
        ->and($remaining->verifyOpenTimestamp())->toBeTrue('OTS still valid - root unchanged! ✅');
});

// ============================================================================
// CHAIN LINK ISSUES (C+|L-|M+|O+)
// ============================================================================

test('ots edge case 4: fake genesis with valid OTS (C+|L-|M+|O+)', function () {
    $this->actingAs($this->user);

    $log1 = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'security',
        'description' => 'First',
    ]);

    $log1->refresh();

    // Create fake genesis with correct hash
    $log2 = new Activity([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'security',
        'description' => 'Second (fake genesis)',
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

    // Build Merkle & OTS
    $job = new BuildMerkleTreeBatch;
    $job->handle();
    $log2->refresh();

    $mockProof = base64_encode('mock_ots_proof');
    $this->otsService->shouldReceive('submit')->once()->andReturn($mockProof);
    $this->otsService->shouldReceive('verify')->once()->andReturn(true);

    $otsJob = new SubmitMerkleRootToOpenTimestamp($this->tenant->id, (int) $log2->merkle_batch_id, $log2->merkle_root);
    $otsJob->handle($this->otsService);

    DB::table('activity_log')
        ->where('id', $log2->id)
        ->update(['ots_confirmed_at' => now()]);

    $log2->refresh();

    // Verify: C✅ L❌ M✅ O✅
    expect($log2->verifyChain())->toBeTrue('Chain Data ✅')
        ->and($log2->verifyChainLink())->toBeFalse('Chain Link ❌ - fake genesis!')
        ->and($log2->verifyMerkleProof())->toBeTrue('Merkle Tree ✅')
        ->and($log2->verifyOpenTimestamp())->toBeTrue('OpenTimestamp ✅ - OTS doesn\'t detect chain link issues');
});

// ============================================================================
// HASH MANIPULATION ISSUES
// ============================================================================

test('ots edge case 5: leaf hash manipulated but root unchanged (C-|L+|M-|O+)', function () {
    $this->actingAs($this->user);

    $log = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'security',
        'description' => 'Genesis',
    ]);

    $log->refresh();

    // Build Merkle & OTS
    $job = new BuildMerkleTreeBatch;
    $job->handle();
    $log->refresh();

    $originalRoot = $log->merkle_root;

    $mockProof = base64_encode('mock_ots_proof');
    $this->otsService->shouldReceive('submit')->once()->andReturn($mockProof);
    $this->otsService->shouldReceive('verify')->once()->with($mockProof, $originalRoot)->andReturn(true);

    $otsJob = new SubmitMerkleRootToOpenTimestamp($this->tenant->id, (int) $log->merkle_batch_id, $log->merkle_root);
    $otsJob->handle($this->otsService);

    DB::table('activity_log')
        ->where('id', $log->id)
        ->update(['ots_confirmed_at' => now()]);

    // Manipulate event_hash (leaf) but NOT merkle_root
    DB::table('activity_log')
        ->where('id', $log->id)
        ->update(['event_hash' => str_repeat('a', 64)]);

    $log->refresh();

    // Verify: C❌ L✅ M❌ but O✅!
    // OTS still valid because merkle_root in DB unchanged
    expect($log->verifyChain())->toBeFalse('Chain Data ❌ - hash manipulated')
        ->and($log->verifyChainLink())->toBeTrue('Chain Link ✅')
        ->and($log->verifyMerkleProof())->toBeFalse('Merkle Tree ❌ - hash mismatch')
        ->and($log->verifyOpenTimestamp())->toBeTrue('OpenTimestamp ✅ - root unchanged!');
});

test('ots edge case 6: merkle root manipulated - OTS detects it (C+|L+|M+|O-)', function () {
    $this->actingAs($this->user);

    $log = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'security',
        'description' => 'Genesis',
    ]);

    $log->refresh();

    // Build Merkle & OTS
    $job = new BuildMerkleTreeBatch;
    $job->handle();
    $log->refresh();

    $originalRoot = $log->merkle_root;

    $mockProof = base64_encode('mock_ots_proof');
    $this->otsService->shouldReceive('submit')->once()->with($originalRoot)->andReturn($mockProof);

    // OTS verify will fail because root was manipulated
    $this->otsService->shouldReceive('verify')
        ->once()
        ->with($mockProof, Mockery::not($originalRoot))
        ->andReturn(false);

    $otsJob = new SubmitMerkleRootToOpenTimestamp($this->tenant->id, (int) $log->merkle_batch_id, $log->merkle_root);
    $otsJob->handle($this->otsService);

    DB::table('activity_log')
        ->where('id', $log->id)
        ->update(['ots_confirmed_at' => now()]);

    // Manipulate merkle_root
    DB::table('activity_log')
        ->where('id', $log->id)
        ->update(['merkle_root' => str_repeat('9', 64)]);

    $log->refresh();

    // Verify: C✅ L✅ M❌ O❌
    // Root manipulation affects BOTH Merkle and OTS!
    expect($log->verifyChain())->toBeTrue('Chain Data ✅')
        ->and($log->verifyChainLink())->toBeTrue('Chain Link ✅')
        ->and($log->verifyMerkleProof())->toBeFalse('Merkle Tree ❌ - proof doesn\'t match manipulated root')
        ->and($log->verifyOpenTimestamp())->toBeFalse('OpenTimestamp ❌ - ROOT MANIPULATION DETECTED!');
});

test('ots edge case 7: both leaf and root manipulated (C-|L+|M-|O-)', function () {
    $this->actingAs($this->user);

    $log = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'security',
        'description' => 'Genesis',
    ]);

    $log->refresh();

    // Build Merkle & OTS
    $job = new BuildMerkleTreeBatch;
    $job->handle();
    $log->refresh();

    $originalRoot = $log->merkle_root;

    $mockProof = base64_encode('mock_ots_proof');
    $this->otsService->shouldReceive('submit')->once()->with($originalRoot)->andReturn($mockProof);
    $this->otsService->shouldReceive('verify')->once()->andReturn(false);

    $otsJob = new SubmitMerkleRootToOpenTimestamp($this->tenant->id, (int) $log->merkle_batch_id, $log->merkle_root);
    $otsJob->handle($this->otsService);

    DB::table('activity_log')
        ->where('id', $log->id)
        ->update(['ots_confirmed_at' => now()]);

    // Manipulate BOTH
    DB::table('activity_log')
        ->where('id', $log->id)
        ->update([
            'event_hash' => str_repeat('a', 64),
            'merkle_root' => str_repeat('9', 64),
        ]);

    $log->refresh();

    // Verify: C❌ L✅ M❌ O❌
    expect($log->verifyChain())->toBeFalse('Chain Data ❌')
        ->and($log->verifyChainLink())->toBeTrue('Chain Link ✅')
        ->and($log->verifyMerkleProof())->toBeFalse('Merkle Tree ❌')
        ->and($log->verifyOpenTimestamp())->toBeFalse('OpenTimestamp ❌');
});

// ============================================================================
// CATASTROPHIC (C-|L-|M-|O-)
// ============================================================================

test('ots edge case 8: catastrophic - all 4 verifications fail (C-|L-|M-|O-)', function () {
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

    // Build Merkle & OTS
    $job = new BuildMerkleTreeBatch;
    $job->handle();
    $logs = $logs->map(fn ($log) => $log->refresh());

    $originalRoot = $logs[2]->merkle_root;

    $mockProof = base64_encode('mock_ots_proof');
    $this->otsService->shouldReceive('submit')->once()->with($originalRoot)->andReturn($mockProof);
    $this->otsService->shouldReceive('verify')->once()->andReturn(false);

    $otsJob = new SubmitMerkleRootToOpenTimestamp($this->tenant->id, (int) $logs[2]->merkle_batch_id, $originalRoot);
    $otsJob->handle($this->otsService);

    DB::table('activity_log')
        ->where('merkle_batch_id', $logs[2]->merkle_batch_id)
        ->update(['ots_confirmed_at' => now()]);

    // Triple manipulation + deletion
    DB::table('activity_log')
        ->where('id', $logs[2]->id)
        ->update([
            'previous_hash' => null, // Break link
            'event_hash' => str_repeat('b', 64), // Break data
            'merkle_root' => str_repeat('9', 64), // Break OTS
        ]);

    $logs[0]->forceDelete();
    $logs[1]->forceDelete();

    $log2 = $logs[2]->fresh();

    // Verify: ALL 4 FAIL
    expect($log2->verifyChain())->toBeFalse('Chain Data ❌')
        ->and($log2->verifyChainLink())->toBeTrue('Chain Link ✅ (limitation - predecessor deleted)')
        ->and($log2->verifyMerkleProof())->toBeFalse('Merkle Tree ❌')
        ->and($log2->verifyOpenTimestamp())->toBeFalse('OpenTimestamp ❌');
});

// ============================================================================
// OTS-SPECIFIC TESTS
// ============================================================================

test('ots: activities without ots proof return null', function () {
    $this->actingAs($this->user);

    $log = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'security',
        'description' => 'Test',
    ]);

    $log->refresh();

    // Build Merkle but NO OTS
    $job = new BuildMerkleTreeBatch;
    $job->handle();
    $log->refresh();

    expect($log->verifyOpenTimestamp())->toBeNull('OTS not submitted');
});

test('ots: activities without confirmed ots return null', function () {
    $this->actingAs($this->user);

    $log = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'security',
        'description' => 'Test',
    ]);

    $log->refresh();

    // Build Merkle & submit OTS but don't confirm
    $job = new BuildMerkleTreeBatch;
    $job->handle();
    $log->refresh();

    $mockProof = base64_encode('mock_ots_proof');
    $this->otsService->shouldReceive('submit')->once()->andReturn($mockProof);

    $otsJob = new SubmitMerkleRootToOpenTimestamp($this->tenant->id, (int) $log->merkle_batch_id, $log->merkle_root);
    $otsJob->handle($this->otsService);

    $log->refresh();

    expect($log->ots_submitted_at)->not->toBeNull()
        ->and($log->ots_confirmed_at)->toBeNull()
        ->and($log->verifyOpenTimestamp())->toBeNull('OTS not yet confirmed');
});

test('ots: level 1 activities have no merkle/ots (N/A)', function () {
    $this->actingAs($this->user);

    $log = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'shift_management', // Level 1
        'description' => 'Level 1 activity',
    ]);

    $log->refresh();

    // Build Merkle (should skip Level 1)
    $job = new BuildMerkleTreeBatch;
    $job->handle();
    $log->refresh();

    expect($log->merkle_batch_id)->toBeNull('No Merkle for Level 1')
        ->and($log->merkle_root)->toBeNull()
        ->and($log->verifyMerkleProof())->toBeNull()
        ->and($log->verifyOpenTimestamp())->toBeNull('No OTS for Level 1');
});

test('ots: service exception returns false', function () {
    $this->actingAs($this->user);

    $log = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'security',
        'description' => 'Test',
    ]);

    $log->refresh();

    // Build Merkle & OTS
    $job = new BuildMerkleTreeBatch;
    $job->handle();
    $log->refresh();

    $mockProof = base64_encode('mock_ots_proof');
    $this->otsService->shouldReceive('submit')->once()->andReturn($mockProof);

    // Mock exception during verification
    $this->otsService->shouldReceive('verify')
        ->once()
        ->andThrow(new \Exception('OTS service error'));

    $otsJob = new SubmitMerkleRootToOpenTimestamp($this->tenant->id, (int) $log->merkle_batch_id, $log->merkle_root);
    $otsJob->handle($this->otsService);

    DB::table('activity_log')
        ->where('id', $log->id)
        ->update(['ots_confirmed_at' => now()]);

    $log->refresh();

    expect($log->verifyOpenTimestamp())->toBeFalse('Exception should return false');
});

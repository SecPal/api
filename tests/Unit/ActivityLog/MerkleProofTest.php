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
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Merkle Proof Verification Tests (Issue #390)
 *
 * These tests verify the verifyMerkleProof() implementation:
 * - Detects tampered logs (event_hash modification)
 * - Detects manipulated proof data (sibling hash modification)
 * - Performance validation (< 1ms per verification)
 *
 * TDD approach: Tests written before implementation
 *
 * @see App\Models\Activity::verifyMerkleProof()
 * @see Issue #390 PR-5: Add Merkle proof storage & verification methods
 */
beforeEach(function () {
    $this->tenant = TenantKey::factory()->create();
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
});

// ============================================================================
// Tampering Detection Tests
// ============================================================================

test('merkle proof fails for tampered log event hash', function () {
    $this->actingAs($this->user);

    // Create Level 2 logs and build Merkle tree
    $logs = collect(range(1, 4))->map(fn ($i) => Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'authentication', // Level 2
        'description' => "Test log {$i}",
    ]));

    $job = new BuildMerkleTreeBatch;
    $job->handle();

    // Pick middle log to tamper with
    $tamperedLog = $logs[1]->fresh();

    // Verify proof works before tampering
    expect($tamperedLog->verifyMerkleProof())->toBeTrue();

    // Tamper with event_hash (simulates log data modification)
    DB::table('activity_log')
        ->where('id', $tamperedLog->id)
        ->update(['event_hash' => hash('sha256', 'TAMPERED_DATA')]);

    $tamperedLog->refresh();

    // Proof must fail now
    expect($tamperedLog->verifyMerkleProof())->toBeFalse();
})->group('security', 'merkle-proof');

test('merkle proof fails if sibling hash is modified', function () {
    $this->actingAs($this->user);

    // Create Level 2 logs and build Merkle tree
    $logs = collect(range(1, 4))->map(fn ($i) => Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'security', // Level 2
        'description' => "Test log {$i}",
    ]));

    $job = new BuildMerkleTreeBatch;
    $job->handle();

    // Pick log with proof
    $log = $logs[0]->fresh();

    // Verify proof works before tampering
    expect($log->verifyMerkleProof())->toBeTrue()
        ->and($log->merkle_proof)->toBeArray()
        ->and($log->merkle_proof)->not()->toBeEmpty();

    // Manipulate first sibling hash in proof
    $tamperedProof = $log->merkle_proof;
    $tamperedProof[0]['hash'] = hash('sha256', 'FAKE_SIBLING_HASH');

    DB::table('activity_log')
        ->where('id', $log->id)
        ->update(['merkle_proof' => json_encode($tamperedProof)]);

    $log->refresh();

    // Proof must fail now
    expect($log->verifyMerkleProof())->toBeFalse();
})->group('security', 'merkle-proof');

// ============================================================================
// Edge Cases
// ============================================================================

test('merkle proof returns false for invalid proof format', function () {
    $this->actingAs($this->user);

    $log = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'authentication',
        'description' => 'Test log',
    ]);

    // Manually set invalid proof format
    DB::table('activity_log')
        ->where('id', $log->id)
        ->update([
            'merkle_root' => hash('sha256', 'fake_root'),
            'merkle_proof' => json_encode(['invalid', 'format']), // Not array of objects
        ]);

    $log->refresh();

    expect($log->verifyMerkleProof())->toBeFalse();
})->group('merkle-proof');

test('merkle proof returns false for missing hash in sibling', function () {
    $this->actingAs($this->user);

    $log = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'authentication',
        'description' => 'Test log',
    ]);

    // Manually set proof with missing hash field
    DB::table('activity_log')
        ->where('id', $log->id)
        ->update([
            'merkle_root' => hash('sha256', 'fake_root'),
            'merkle_proof' => json_encode([
                ['position' => 'left'], // Missing 'hash' key
            ]),
        ]);

    $log->refresh();

    expect($log->verifyMerkleProof())->toBeFalse();
})->group('merkle-proof');

test('merkle proof returns false for missing position in sibling', function () {
    $this->actingAs($this->user);

    $log = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'authentication',
        'description' => 'Test log',
    ]);

    // Manually set proof with missing position field
    DB::table('activity_log')
        ->where('id', $log->id)
        ->update([
            'merkle_root' => hash('sha256', 'fake_root'),
            'merkle_proof' => json_encode([
                ['hash' => hash('sha256', 'sibling')], // Missing 'position' key
            ]),
        ]);

    $log->refresh();

    expect($log->verifyMerkleProof())->toBeFalse();
})->group('merkle-proof');

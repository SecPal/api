<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution
 */

declare(strict_types=1);

use App\Jobs\ProcessActivityHashChain;
use App\Models\Activity;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * @property TenantKey $tenant
 * @property User $user
 */
beforeEach(function () {
    $this->tenant = TenantKey::factory()->create();
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->actingAs($this->user);
});

// ============================================================================
// ProcessActivityHashChain Job Tests (Issue #408)
// ============================================================================

/**
 * Test: Job builds hash chain correctly for genesis log (first log in tenant).
 *
 * DESIGN: Queue-based hash chain building (Issue #408)
 * - Activity::create() inserts with event_hash=NULL
 * - Activity::created hook dispatches ProcessActivityHashChain job
 * - Job updates activity with computed hash
 *
 * TEST APPROACH: Direct job invocation (not dispatched)
 * - Activity created via Activity::create() (triggers full model lifecycle)
 * - Job handle() called directly to update hash
 * - Validates hash computed correctly
 */
test('job builds hash chain correctly for first log', function () {
    // Create activity using model (event_hash will be NULL initially, job runs via sync queue)
    $log = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'authentication',
        'description' => 'First log',
        'causer_type' => 'App\\Models\\User',
        'causer_id' => $this->user->id,
        'properties' => ['test' => 'data'],
    ]);

    // Verify hash chain built (sync queue auto-executed job)
    $log->refresh();
    expect($log)->not->toBeNull()
        ->and($log->tenant_id)->toBe($this->tenant->id)
        ->and($log->log_name)->toBe('authentication')
        ->and($log->description)->toBe('First log')
        ->and($log->previous_hash)->toBeNull() // Genesis log
        ->and($log->event_hash)->not->toBeNull()
        ->and($log->event_hash)->toHaveLength(64); // SHA256 = 64 hex chars
});

test('job builds hash chain correctly for subsequent logs', function () {
    // Create first log
    $log1 = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'authentication',
        'description' => 'First log',
        'causer_type' => 'App\\Models\\User',
        'causer_id' => $this->user->id,
    ]);

    // Create second log
    $log2 = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'authentication',
        'description' => 'Second log',
        'causer_type' => 'App\\Models\\User',
        'causer_id' => $this->user->id,
    ]);

    // Verify chain linkage (both logs updated by sync queue)
    $log1->refresh();
    $log2->refresh();

    expect($log2->previous_hash)->toBe($log1->event_hash)
        ->and($log2->event_hash)->not->toBe($log1->event_hash)
        ->and($log2->event_hash)->not->toBeNull();
});

test('job respects tenant isolation', function () {
    // Create second tenant
    $tenant2 = TenantKey::factory()->create();

    // Create log for tenant 1
    $log1 = Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'authentication',
        'description' => 'Tenant 1 log',
        'causer_type' => 'App\\Models\\User',
        'causer_id' => $this->user->id,
    ]);

    // Create log for tenant 2
    $log2 = Activity::create([
        'tenant_id' => $tenant2->id,
        'log_name' => 'authentication',
        'description' => 'Tenant 2 log',
        'causer_type' => 'App\\Models\\User',
        'causer_id' => $this->user->id,
    ]);

    // Refresh to get computed hashes
    $log1->refresh();
    $log2->refresh();

    // Verify tenant 2 genesis log (does not reference tenant 1)
    expect($log2->previous_hash)->toBeNull()
        ->and($log2->event_hash)->not->toBe($log1->event_hash);
});

test('job is queued on correct queue', function () {
    Queue::fake();

    // Create activity (will dispatch job to fake queue)
    Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'authentication',
        'description' => 'Test log',
        'causer_type' => 'App\\Models\\User',
        'causer_id' => $this->user->id,
    ]);

    // Assert: Job queued on correct queue
    Queue::assertPushedOn('activity-hash-chain', ProcessActivityHashChain::class);
});

test('job builds correct hash chain for 10 sequential logs', function () {
    // Create 10 logs sequentially (sync queue executes jobs immediately)
    $logs = collect(range(1, 10))->map(function ($i) {
        return Activity::create([
            'tenant_id' => $this->tenant->id,
            'log_name' => 'authentication',
            'description' => "Log {$i}",
            'causer_type' => 'App\\Models\\User',
            'causer_id' => $this->user->id,
            'properties' => ['test_id' => $i],
        ]);
    });

    // Refresh all logs to get computed hashes
    $logs = $logs->map(fn ($log) => $log->refresh());

    // Verify chain integrity
    expect($logs[0]->previous_hash)->toBeNull(); // Genesis log

    for ($i = 1; $i < $logs->count(); $i++) {
        expect($logs[$i]->previous_hash)->toBe($logs[$i - 1]->event_hash);
    }
});

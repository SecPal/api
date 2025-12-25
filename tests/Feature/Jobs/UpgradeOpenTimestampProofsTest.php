<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use App\Jobs\UpgradeOpenTimestampProofs;
use App\Models\Activity;
use App\Models\TenantKey;
use App\Models\User;
use App\Services\OpenTimestampService;

/**
 * Test UpgradeOpenTimestampProofs job.
 *
 * Tests upgrading of pending OTS proofs to confirmed proofs
 * when Bitcoin blocks confirm.
 *
 * @see App\Jobs\UpgradeOpenTimestampProofs
 * @see Issue #391 PR-6: Integrate OpenTimestamp PHP library
 */
uses()->group('feature');

beforeEach(function () {
    $this->tenant = TenantKey::factory()->create();
    $this->user = User::factory()->for($this->tenant, 'tenant')->create();
});

test('job upgrades pending proofs', function () {
    // Arrange: Create logs with pending proofs
    $pendingProof = hex2bin('0004f0'.bin2hex('pending-calendar-data'));
    $confirmedProof = hex2bin('0588960d73d7190103'.bin2hex('bitcoin-confirmed'));

    collect(range(1, 3))->each(fn ($i) => Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'hr_access',
        'description' => "Pending proof log {$i}",
        'ots_proof' => $pendingProof,
        'ots_submitted_at' => now()->subHours(6),
        'ots_confirmed_at' => null,
    ]));

    // Mock service - proof is now confirmed
    /** @var OpenTimestampService&\Mockery\MockInterface $mockService */
    $mockService = mock(OpenTimestampService::class);
    $mockService->shouldReceive('upgrade')
        ->times(3)
        ->with($pendingProof)
        ->andReturn($confirmedProof);

    // Act: Run job
    $job = new UpgradeOpenTimestampProofs;
    $job->handle($mockService);

    // Assert: All logs upgraded
    $logs = Activity::where('tenant_id', $this->tenant->id)->get();

    foreach ($logs as $log) {
        expect($log->ots_confirmed_at)->not->toBeNull();
        expect($log->ots_proof)->toBe($confirmedProof);
        expect($log->ots_confirmed_at->timestamp)->toBeGreaterThanOrEqual(now()->subSeconds(5)->timestamp);
    }
});

test('job skips logs without pending proofs', function () {
    // Arrange: Create logs without pending proofs
    collect(range(1, 2))->each(fn ($i) => Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'hr_access',
        'description' => "No pending proof log {$i}",
        'ots_proof' => null,
        'ots_submitted_at' => null,
        'ots_confirmed_at' => null,
    ]));

    /** @var OpenTimestampService&\Mockery\MockInterface $mockService */
    $mockService = mock(OpenTimestampService::class);
    $mockService->shouldNotReceive('upgrade');

    // Act: Run job
    $job = new UpgradeOpenTimestampProofs;
    $job->handle($mockService);

    // Assert: No changes
    $logs = Activity::where('tenant_id', $this->tenant->id)->get();

    foreach ($logs as $log) {
        expect($log->ots_confirmed_at)->toBeNull();
    }
});

test('job skips already confirmed proofs', function () {
    // Arrange: Create already confirmed log
    Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'contract_change',
        'description' => 'Already confirmed log',
        'ots_proof' => 'confirmed-proof',
        'ots_submitted_at' => now()->subDays(2),
        'ots_confirmed_at' => now()->subDays(1),
    ]);

    /** @var OpenTimestampService&\Mockery\MockInterface $mockService */
    $mockService = mock(OpenTimestampService::class);
    $mockService->shouldNotReceive('upgrade');

    // Act: Run job
    $job = new UpgradeOpenTimestampProofs;
    $job->handle($mockService);

    // Assert: No changes (already confirmed)
    $log = Activity::first();
    expect($log->ots_proof)->toBe('confirmed-proof');
});

test('job handles proof not yet ready for upgrade', function () {
    // Arrange: Create pending proof (>1h old so it's eligible for upgrade)
    $pendingProof = 'pending-proof';

    Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'guard_book_event',
        'description' => 'Pending proof not ready',
        'ots_proof' => $pendingProof,
        'ots_submitted_at' => now()->subHours(2), // Old enough to be checked
        'ots_confirmed_at' => null,
    ]);

    // Mock service - upgrade not yet available (Bitcoin not confirmed)
    /** @var OpenTimestampService&\Mockery\MockInterface $mockService */
    $mockService = mock(OpenTimestampService::class);
    $mockService->shouldReceive('upgrade')
        ->once()
        ->with($pendingProof)
        ->andReturn(null); // Not ready yet

    // Act: Run job
    $job = new UpgradeOpenTimestampProofs;
    $job->handle($mockService);

    // Assert: Proof unchanged (still pending)
    $log = Activity::first();
    expect($log->ots_proof)->toBe($pendingProof);
    expect($log->ots_confirmed_at)->toBeNull();
});

test('job processes multiple tenants', function () {
    // Arrange: Create logs for multiple tenants
    $tenant2 = TenantKey::factory()->create();

    Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'hr_access',
        'description' => 'Tenant 1 log',
        'ots_proof' => 'proof1',
        'ots_submitted_at' => now()->subHours(6),
        'ots_confirmed_at' => null,
    ]);

    Activity::create([
        'tenant_id' => $tenant2->id,
        'log_name' => 'contract_change',
        'description' => 'Tenant 2 log',
        'ots_proof' => 'proof2',
        'ots_submitted_at' => now()->subHours(6),
        'ots_confirmed_at' => null,
    ]);

    /** @var OpenTimestampService&\Mockery\MockInterface $mockService */
    $mockService = mock(OpenTimestampService::class);
    $mockService->shouldReceive('upgrade')
        ->twice()
        ->andReturn('confirmed');

    // Act: Run job
    $job = new UpgradeOpenTimestampProofs;
    $job->handle($mockService);

    // Assert: Both tenants' logs upgraded
    expect(Activity::whereNotNull('ots_confirmed_at')->count())->toBe(2);
});

test('job handles upgrade errors gracefully', function () {
    // Arrange: Create pending proof
    Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'hr_access',
        'description' => 'Test error handling',
        'ots_proof' => 'proof',
        'ots_submitted_at' => now()->subHours(6),
        'ots_confirmed_at' => null,
    ]);

    // Mock service - upgrade throws exception
    /** @var OpenTimestampService&\Mockery\MockInterface $mockService */
    $mockService = mock(OpenTimestampService::class);
    $mockService->shouldReceive('upgrade')
        ->andThrow(new \RuntimeException('Calendar server timeout'));

    // Act: Job should handle gracefully (don't fail entire job)
    $job = new UpgradeOpenTimestampProofs;
    $job->handle($mockService);

    // Assert: Log still pending (not confirmed, not lost)
    $log = Activity::first();
    expect($log->ots_confirmed_at)->toBeNull();
    expect($log->ots_proof)->toBe('proof');
});

test('job batch processes logs efficiently', function () {
    // Arrange: Create many pending logs
    collect(range(1, 100))->each(fn ($i) => Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'hr_access',
        'description' => "Batch log {$i}",
        'ots_proof' => 'pending',
        'ots_submitted_at' => now()->subHours(6),
        'ots_confirmed_at' => null,
    ]));

    /** @var OpenTimestampService&\Mockery\MockInterface $mockService */
    $mockService = mock(OpenTimestampService::class);
    $mockService->shouldReceive('upgrade')
        ->times(100)
        ->andReturn('confirmed');

    // Act: Run job
    $job = new UpgradeOpenTimestampProofs;
    $job->handle($mockService);

    // Assert: All logs processed
    expect(Activity::whereNotNull('ots_confirmed_at')->count())->toBe(100);
});

test('job respects batch limit of 100 proofs', function () {
    // Arrange: Create 150 pending proofs
    $pendingProof = hex2bin('0004f0'.bin2hex('pending'));
    $confirmedProof = hex2bin('0588960d73d7190103'.bin2hex('confirmed'));

    foreach (range(1, 150) as $i) {
        Activity::create([
            'tenant_id' => $this->tenant->id,
            'log_name' => 'contract_change',
            'description' => "Test batch limit {$i}",
            'ots_proof' => $pendingProof,
            'ots_submitted_at' => now()->subHours(3), // >1 hour old
            'ots_confirmed_at' => null,
        ]);
    }

    /** @var OpenTimestampService&\Mockery\MockInterface $mockService */
    $mockService = mock(OpenTimestampService::class);
    $mockService->shouldReceive('upgrade')
        ->times(100) // Only 100 should be processed
        ->andReturn($confirmedProof);

    // Act: Run job
    $job = new UpgradeOpenTimestampProofs;
    $job->handle($mockService);

    // Assert: Only 100 logs upgraded
    expect(Activity::whereNotNull('ots_confirmed_at')->count())->toBe(100);
    expect(Activity::whereNull('ots_confirmed_at')->count())->toBe(50);
});

test('job skips recently submitted proofs', function () {
    // Arrange: Create proofs with different submission times
    $pendingProof = hex2bin('0004f0'.bin2hex('pending'));

    // Old proof (>1 hour) - should be processed
    Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'contract_change',
        'description' => 'Old proof',
        'ots_proof' => $pendingProof,
        'ots_submitted_at' => now()->subHours(2),
        'ots_confirmed_at' => null,
    ]);

    // Recent proof (<1 hour) - should be skipped
    Activity::create([
        'tenant_id' => $this->tenant->id,
        'log_name' => 'contract_change',
        'description' => 'Recent proof',
        'ots_proof' => $pendingProof,
        'ots_submitted_at' => now()->subMinutes(30),
        'ots_confirmed_at' => null,
    ]);

    /** @var OpenTimestampService&\Mockery\MockInterface $mockService */
    $mockService = mock(OpenTimestampService::class);
    $mockService->shouldReceive('upgrade')
        ->once() // Only old proof processed
        ->andReturn(hex2bin('0588960d73d7190103'));

    // Act: Run job
    $job = new UpgradeOpenTimestampProofs;
    $job->handle($mockService);

    // Assert: Only old proof upgraded, recent skipped
    expect(Activity::whereNotNull('ots_confirmed_at')->count())->toBe(1);
    expect(Activity::whereNull('ots_confirmed_at')->count())->toBe(1);

    // Verify recent proof still pending
    $recentLog = Activity::where('description', 'Recent proof')->first();
    expect($recentLog->ots_confirmed_at)->toBeNull();
});

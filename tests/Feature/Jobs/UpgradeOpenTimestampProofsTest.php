<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\UpgradeOpenTimestampProofs;
use App\Models\Activity;
use App\Models\TenantKey;
use App\Models\User;
use App\Services\OpenTimestampService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Test UpgradeOpenTimestampProofs job.
 *
 * Tests upgrading of pending OTS proofs to confirmed proofs
 * when Bitcoin blocks confirm.
 *
 * @see App\Jobs\UpgradeOpenTimestampProofs
 * @see Issue #391 PR-6: Integrate OpenTimestamp PHP library
 */
class UpgradeOpenTimestampProofsTest extends TestCase
{
    use RefreshDatabase;

    private TenantKey $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = TenantKey::factory()->create();
        $this->user = User::factory()->for($this->tenant, 'tenant')->create();
    }

    public function test_job_upgrades_pending_proofs(): void
    {
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
        $mockService = $this->mock(OpenTimestampService::class);
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
            $this->assertNotNull($log->ots_confirmed_at);
            $this->assertEquals($confirmedProof, $log->ots_proof);
            $this->assertEqualsWithDelta(
                now()->timestamp,
                $log->ots_confirmed_at->timestamp,
                5
            );
        }
    }

    public function test_job_skips_logs_without_pending_proofs(): void
    {
        // Arrange: Create logs without pending proofs
        collect(range(1, 2))->each(fn ($i) => Activity::create([
            'tenant_id' => $this->tenant->id,
            'log_name' => 'hr_access',
            'description' => "No pending proof log {$i}",
            'ots_proof' => null,
            'ots_submitted_at' => null,
            'ots_confirmed_at' => null,
        ]));

        $mockService = $this->mock(OpenTimestampService::class);
        $mockService->shouldNotReceive('upgrade');

        // Act: Run job
        $job = new UpgradeOpenTimestampProofs;
        $job->handle($mockService);

        // Assert: No changes
        $logs = Activity::where('tenant_id', $this->tenant->id)->get();

        foreach ($logs as $log) {
            $this->assertNull($log->ots_confirmed_at);
        }
    }

    public function test_job_skips_already_confirmed_proofs(): void
    {
        // Arrange: Create already confirmed log
        Activity::create([
            'tenant_id' => $this->tenant->id,
            'log_name' => 'contract_change',
            'description' => 'Already confirmed log',
            'ots_proof' => 'confirmed-proof',
            'ots_submitted_at' => now()->subDays(2),
            'ots_confirmed_at' => now()->subDays(1),
        ]);

        $mockService = $this->mock(OpenTimestampService::class);
        $mockService->shouldNotReceive('upgrade');

        // Act: Run job
        $job = new UpgradeOpenTimestampProofs;
        $job->handle($mockService);

        // Assert: No changes (already confirmed)
        $log = Activity::first();
        assert($log instanceof Activity);
        $this->assertEquals('confirmed-proof', $log->ots_proof);
    }

    public function test_job_handles_proof_not_yet_ready_for_upgrade(): void
    {
        // Arrange: Create pending proof
        $pendingProof = 'pending-proof';

        Activity::create([
            'tenant_id' => $this->tenant->id,
            'log_name' => 'guard_book_event',
            'description' => 'Recent pending proof',
            'ots_proof' => $pendingProof,
            'ots_submitted_at' => now()->subMinutes(30), // Recent submission
            'ots_confirmed_at' => null,
        ]);

        // Mock service - upgrade not yet available
        $mockService = $this->mock(OpenTimestampService::class);
        $mockService->shouldReceive('upgrade')
            ->once()
            ->with($pendingProof)
            ->andReturn(null); // Not ready yet

        // Act: Run job
        $job = new UpgradeOpenTimestampProofs;
        $job->handle($mockService);

        // Assert: Proof unchanged (still pending)
        $log = Activity::first();
        assert($log instanceof Activity);
        $this->assertEquals($pendingProof, $log->ots_proof);
        $this->assertNull($log->ots_confirmed_at);
    }

    public function test_job_processes_multiple_tenants(): void
    {
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

        $mockService = $this->mock(OpenTimestampService::class);
        $mockService->shouldReceive('upgrade')
            ->twice()
            ->andReturn('confirmed');

        // Act: Run job
        $job = new UpgradeOpenTimestampProofs;
        $job->handle($mockService);

        // Assert: Both tenants' logs upgraded
        $this->assertEquals(2, Activity::whereNotNull('ots_confirmed_at')->count());
    }

    public function test_job_handles_upgrade_errors_gracefully(): void
    {
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
        $mockService = $this->mock(OpenTimestampService::class);
        $mockService->shouldReceive('upgrade')
            ->andThrow(new \RuntimeException('Calendar server timeout'));

        // Act: Job should handle gracefully (don't fail entire job)
        $job = new UpgradeOpenTimestampProofs;
        $job->handle($mockService);

        // Assert: Log still pending (not confirmed, not lost)
        $log = Activity::first();
        assert($log instanceof Activity);
        $this->assertNull($log->ots_confirmed_at);
        $this->assertEquals('proof', $log->ots_proof);
    }

    public function test_job_batch_processes_logs_efficiently(): void
    {
        // Arrange: Create many pending logs
        collect(range(1, 100))->each(fn ($i) => Activity::create([
            'tenant_id' => $this->tenant->id,
            'log_name' => 'hr_access',
            'description' => "Batch log {$i}",
            'ots_proof' => 'pending',
            'ots_submitted_at' => now()->subHours(6),
            'ots_confirmed_at' => null,
        ]));

        $mockService = $this->mock(OpenTimestampService::class);
        $mockService->shouldReceive('upgrade')
            ->times(100)
            ->andReturn('confirmed');

        // Act: Run job
        $job = new UpgradeOpenTimestampProofs;
        $job->handle($mockService);

        // Assert: All logs processed
        $this->assertEquals(100, Activity::whereNotNull('ots_confirmed_at')->count());
    }
}

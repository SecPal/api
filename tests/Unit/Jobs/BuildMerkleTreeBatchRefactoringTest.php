<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\BuildMerkleTreeBatch;
use App\Jobs\SubmitMerkleRootToOpenTimestamp;
use App\Models\Activity;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * TDD Tests for BuildMerkleTreeBatch refactoring.
 *
 * After refactoring to retention-based model:
 * - ALL log types should get Merkle tree (not just Level 2+3)
 * - ALL batches should trigger OTS submission (not just Level 3)
 *
 * Written BEFORE implementation (Test-Driven Development).
 * These tests should FAIL initially.
 *
 * @see https://github.com/SecPal/api/issues/441
 */
class BuildMerkleTreeBatchRefactoringTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $user;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->customer = Customer::factory()->for($this->tenant)->create();
        $this->user = User::factory()->for($this->tenant)->create();

        $this->actingAs($this->user);
    }

    /**
     * TDD: ALL log types should get Merkle tree (not just Level 2+3).
     *
     * Expected: FAIL (currently only Level 2+3 processed)
     */
    public function test_all_log_types_get_merkle_tree(): void
    {
        // Create logs with different retention periods
        $log3Years = activity('shift_management') // 3 years retention
            ->performedOn($this->customer)
            ->log('Shift assigned');

        $log8Years = activity('invoice_generated') // 8 years retention
            ->performedOn($this->customer)
            ->log('Invoice created');

        $log10Years = activity('annual_closing') // 10 years retention
            ->performedOn($this->customer)
            ->log('Year closed');

        // Initially no merkle_root
        $this->assertNull($log3Years->fresh()->merkle_root);
        $this->assertNull($log8Years->fresh()->merkle_root);
        $this->assertNull($log10Years->fresh()->merkle_root);

        // Run batch job
        $job = new BuildMerkleTreeBatch();
        $job->handle();

        // ALL should have merkle_root now
        $log3Years->refresh();
        $log8Years->refresh();
        $log10Years->refresh();

        $this->assertNotNull($log3Years->merkle_root, '3-year retention log should have merkle_root');
        $this->assertNotNull($log8Years->merkle_root, '8-year retention log should have merkle_root');
        $this->assertNotNull($log10Years->merkle_root, '10-year retention log should have merkle_root');
    }

    /**
     * TDD: All logs in same batch should have same merkle_root.
     *
     * Expected: FAIL (3-year logs currently not batched)
     */
    public function test_all_logs_in_same_tenant_get_same_batch_id(): void
    {
        // Create multiple logs of different types
        $log1 = activity('shift_management')->performedOn($this->customer)->log('Log 1');
        $log2 = activity('invoice_generated')->performedOn($this->customer)->log('Log 2');
        $log3 = activity('security')->performedOn($this->customer)->log('Log 3');

        $job = new BuildMerkleTreeBatch();
        $job->handle();

        $log1->refresh();
        $log2->refresh();
        $log3->refresh();

        // All should be in SAME batch (same tenant, same hour)
        $this->assertSame(
            $log1->merkle_batch_id,
            $log2->merkle_batch_id,
            'All logs should have same batch_id'
        );

        $this->assertSame(
            $log2->merkle_batch_id,
            $log3->merkle_batch_id,
            'All logs should have same batch_id'
        );

        // All should have same merkle_root
        $this->assertSame(
            $log1->merkle_root,
            $log2->merkle_root,
            'All logs should have same merkle_root'
        );
    }

    /**
     * TDD: OTS should be dispatched for ALL batches (not just Level 3).
     *
     * Expected: FAIL (currently only dispatched if hasLevel3)
     */
    public function test_ots_dispatched_for_all_batches(): void
    {
        Queue::fake();

        // Create ONLY 3-year retention logs (previously "Level 1")
        activity('shift_management')->performedOn($this->customer)->log('Shift 1');
        activity('authentication')->performedOn($this->customer)->log('Login');
        activity('security')->performedOn($this->customer)->log('Access granted');

        $job = new BuildMerkleTreeBatch();
        $job->handle();

        // OTS should be dispatched even for "low retention" logs
        Queue::assertPushed(
            SubmitMerkleRootToOpenTimestamp::class,
            'OTS should be dispatched for ALL batches, regardless of retention period'
        );
    }

    /**
     * TDD: OTS dispatched with correct parameters.
     *
     * Expected: FAIL (depends on previous test passing)
     */
    public function test_ots_dispatched_with_correct_parameters(): void
    {
        Queue::fake();

        activity('shift_management')->performedOn($this->customer)->log('Test log');

        $job = new BuildMerkleTreeBatch();
        $job->handle();

        Queue::assertPushed(SubmitMerkleRootToOpenTimestamp::class, function ($job) {
            return $job->tenantId === $this->tenant->id
                && $job->batchId > 0
                && strlen($job->merkleRoot) === 64; // SHA256 hex
        });
    }

    /**
     * TDD: Job processes logs from all retention periods.
     *
     * Expected: FAIL (currently filters Level 2+3 only)
     */
    public function test_job_processes_all_retention_periods(): void
    {
        // Create mix of retention periods
        $logs = [
            activity('shift_management')->performedOn($this->customer)->log('3y'), // 3 years
            activity('authentication')->performedOn($this->customer)->log('3y'), // 3 years
            activity('invoice_generated')->performedOn($this->customer)->log('8y'), // 8 years
            activity('contract_change')->performedOn($this->customer)->log('8y'), // 8 years
            activity('annual_closing')->performedOn($this->customer)->log('10y'), // 10 years
        ];

        $job = new BuildMerkleTreeBatch();
        $job->handle();

        // ALL should be processed
        foreach ($logs as $log) {
            $log->refresh();
            $this->assertNotNull(
                $log->merkle_root,
                "Log '{$log->description}' (retention: " . Activity::getRetentionYears($log->log_name) . " years) should have merkle_root"
            );
        }
    }

    /**
     * TDD: Merkle proofs are self-contained (contain sibling hashes).
     *
     * This test verifies refactoring doesn't break merkle proof structure.
     *
     * Expected: PASS (existing functionality)
     */
    public function test_merkle_proofs_contain_sibling_hashes(): void
    {
        activity('shift_management')->performedOn($this->customer)->log('Log 1');
        activity('shift_management')->performedOn($this->customer)->log('Log 2');
        activity('shift_management')->performedOn($this->customer)->log('Log 3');

        $job = new BuildMerkleTreeBatch();
        $job->handle();

        $log = Activity::where('tenant_id', $this->tenant->id)->first();
        $log->refresh();

        $this->assertNotNull($log->merkle_proof);

        $proof = json_decode($log->merkle_proof, true);
        $this->assertIsArray($proof);

        // Proof should contain sibling hashes
        foreach ($proof as $sibling) {
            $this->assertArrayHasKey('hash', $sibling);
            $this->assertArrayHasKey('position', $sibling);
            $this->assertIsString($sibling['hash']);
            $this->assertContains($sibling['position'], ['left', 'right']);
        }
    }

    /**
     * TDD: Batch integrity metadata is preserved.
     *
     * Expected: PASS (existing functionality)
     */
    public function test_batch_count_stored_for_integrity_checking(): void
    {
        // Create 5 logs
        for ($i = 1; $i <= 5; $i++) {
            activity('shift_management')->performedOn($this->customer)->log("Log {$i}");
        }

        $job = new BuildMerkleTreeBatch();
        $job->handle();

        $logs = Activity::where('tenant_id', $this->tenant->id)->get();

        // All should have merkle_batch_count = 5
        foreach ($logs as $log) {
            $this->assertSame(5, $log->merkle_batch_count);
        }
    }

    /**
     * TDD: Multiple tenants are isolated.
     *
     * Expected: PASS (existing functionality)
     */
    public function test_multiple_tenants_get_separate_batches(): void
    {
        $tenant2 = Tenant::factory()->create();
        $customer2 = Customer::factory()->for($tenant2)->create();
        $user2 = User::factory()->for($tenant2)->create();

        // Tenant 1 logs
        activity('shift_management')->performedOn($this->customer)->log('Tenant 1 Log');

        // Tenant 2 logs
        $this->actingAs($user2);
        activity('shift_management')->performedOn($customer2)->log('Tenant 2 Log');

        $job = new BuildMerkleTreeBatch();
        $job->handle();

        $log1 = Activity::where('tenant_id', $this->tenant->id)->first();
        $log2 = Activity::where('tenant_id', $tenant2->id)->first();

        // Different batch IDs
        $this->assertNotSame($log1->merkle_batch_id, $log2->merkle_batch_id);

        // Different merkle roots
        $this->assertNotSame($log1->merkle_root, $log2->merkle_root);
    }

    /**
     * TDD: Job only processes unbatched logs.
     *
     * Expected: PASS (existing functionality)
     */
    public function test_job_skips_already_batched_logs(): void
    {
        $log1 = activity('shift_management')->performedOn($this->customer)->log('Log 1');

        // First run
        $job = new BuildMerkleTreeBatch();
        $job->handle();

        $log1->refresh();
        $originalBatchId = $log1->merkle_batch_id;
        $originalRoot = $log1->merkle_root;

        // Create new log
        activity('shift_management')->performedOn($this->customer)->log('Log 2');

        // Second run
        $job2 = new BuildMerkleTreeBatch();
        $job2->handle();

        $log1->refresh();

        // Original log should keep same batch data
        $this->assertSame($originalBatchId, $log1->merkle_batch_id);
        $this->assertSame($originalRoot, $log1->merkle_root);
    }

    /**
     * TDD: Empty tenants are skipped gracefully.
     *
     * Expected: PASS (existing functionality)
     */
    public function test_job_handles_empty_tenants_gracefully(): void
    {
        // Don't create any logs

        $job = new BuildMerkleTreeBatch();
        $job->handle(); // Should not throw

        $this->assertTrue(true, 'Job should handle empty tenants without errors');
    }
}

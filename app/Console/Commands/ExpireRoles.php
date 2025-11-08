<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Console\Commands;

use App\Models\RoleAssignmentLog;
use App\Models\TemporalRoleUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Expire and revoke temporal role assignments that have passed their valid_until date.
 *
 * This command runs every minute via Laravel scheduler to automatically remove
 * expired role assignments with auto_revoke=true, ensuring principle of least privilege.
 */
class ExpireRoles extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'roles:expire';

    /**
     * The console command description.
     */
    protected $description = 'Expire and revoke temporal role assignments that have passed their valid_until date';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $count = 0;

        // Use cursor() for memory-efficient streaming without requiring a primary key
        // cursor() returns a LazyCollection that fetches results as they're iterated
        $expired = TemporalRoleUser::expired()->cursor();

        // Collect items in chunks for batch processing with transactions
        $chunk = [];

        foreach ($expired as $assignment) {
            $chunk[] = $assignment;

            // Process in batches of 100 for optimal transaction performance
            if (count($chunk) >= 100) {
                $count += $this->processChunk($chunk);
                $chunk = [];
            }
        }

        // Process remaining items in final chunk
        if (count($chunk) > 0) {
            $count += $this->processChunk($chunk);
        }

        if ($count === 0) {
            $this->info('No expired roles found.');

            return self::SUCCESS;
        }

        $this->info(sprintf('%d role(s) expired and revoked.', $count));

        return self::SUCCESS;
    }

    /**
     * Process a chunk of expired role assignments in a single transaction.
     *
     * @param  array<int, TemporalRoleUser>  $chunk
     * @return int Number of roles actually expired
     */
    private function processChunk(array $chunk): int
    {
        $count = 0;

        DB::transaction(function () use ($chunk, &$count) {
            foreach ($chunk as $assignment) {
                // Delete first, then log only if deleted successfully (prevents duplicate logs on concurrent execution)
                $deleted = DB::table('model_has_roles')
                    ->where('model_type', $assignment->model_type)
                    ->where('model_id', $assignment->model_id)
                    ->where('role_id', $assignment->role_id)
                    ->where('tenant_id', $assignment->tenant_id)
                    ->delete();

                // Only log if we actually deleted something (race condition protection)
                if ($deleted > 0) {
                    RoleAssignmentLog::create([
                        'user_id' => $assignment->model_id,
                        'role_id' => $assignment->role_id,
                        'action' => 'expired',
                        'valid_from' => $assignment->valid_from,
                        'valid_until' => $assignment->valid_until,
                        'assigned_by' => $assignment->assigned_by,
                        'reason' => $assignment->reason,
                    ]);

                    $count++;
                }
            }
        });

        return $count;
    }
}

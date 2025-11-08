<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

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
        // Find expired roles using the expired() scope
        $expired = TemporalRoleUser::expired()->get();

        if ($expired->isEmpty()) {
            $this->info('No expired roles found.');

            return self::SUCCESS;
        }

        $count = 0;

        // Process each expired assignment in a transaction for safety
        foreach ($expired as $assignment) {
            DB::transaction(function () use ($assignment, &$count) {
                // 1. Log to immutable audit trail
                RoleAssignmentLog::create([
                    'user_id' => $assignment->model_id,
                    'role_id' => $assignment->role_id,
                    'action' => 'expired',
                    'valid_from' => $assignment->valid_from,
                    'valid_until' => $assignment->valid_until,
                    'assigned_by' => $assignment->assigned_by,
                    'reason' => $assignment->reason,
                ]);

                // 2. Delete expired assignment using query (pivot has no primary key)
                DB::table('model_has_roles')
                    ->where('model_type', $assignment->model_type)
                    ->where('model_id', $assignment->model_id)
                    ->where('role_id', $assignment->role_id)
                    ->where('tenant_id', $assignment->tenant_id)
                    ->delete();

                $count++;
            });
        }

        $this->info(sprintf('%d role(s) expired and revoked.', $count));

        return self::SUCCESS;
    }
}

<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\ContractEndingSoonMail;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Daily command to send contract ending notifications.
 *
 * This command runs automatically every day at 08:00 and:
 * - Finds employees whose contract ends in exactly 7 days
 * - Sends notification emails to remind them
 */
class SendContractEndingNotifications extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'employees:send-contract-ending-notifications
                          {--dry-run : Show what would be sent without actually sending emails}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send contract ending notifications 7 days before termination';

    /**
     * Number of days before contract end to send notification.
     */
    private const NOTIFICATION_DAYS = 7;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $today = Carbon::today();
        $notificationDate = $today->copy()->addDays(self::NOTIFICATION_DAYS);

        $this->info('Checking contract ending notifications for '.$notificationDate->format('Y-m-d'));

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No emails will be sent');
        }

        $employees = Employee::where('status', Employee::STATUS_ACTIVE)
            ->whereDate('termination_date', '=', $notificationDate)
            ->with('user')
            ->get();

        if ($employees->isEmpty()) {
            $this->line('  No contracts ending in '.self::NOTIFICATION_DAYS.' days');

            return self::SUCCESS;
        }

        $count = 0;

        foreach ($employees as $employee) {
            if (! $employee->user || ! $employee->email) {
                $this->warn("  Skipping: Employee {$employee->id} has no user account or email");

                continue;
            }

            try {
                $this->line("  Notifying: {$employee->first_name} {$employee->last_name} (ID: {$employee->id})");

                if (! $isDryRun) {
                    Mail::to($employee->email)->queue(new ContractEndingSoonMail($employee));
                }

                $count++;
            } catch (\Exception $e) {
                $this->error("  Failed to send notification to employee {$employee->id}: {$e->getMessage()}");
                Log::error('Failed to send contract ending notification', [
                    'employee_id' => $employee->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Summary
        $this->newLine();
        $this->info('Summary:');
        $this->line("  Notifications sent: {$count}");

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No actual emails were sent');
        }

        Log::info('Contract ending notifications sent', [
            'count' => $count,
            'date' => $notificationDate->format('Y-m-d'),
            'dry_run' => $isDryRun,
        ]);

        return self::SUCCESS;
    }
}

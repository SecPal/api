<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\QualificationExpiringMail;
use App\Models\EmployeeQualification;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Daily command to check qualification expirations and send notifications.
 *
 * This command runs automatically every day at 07:00 and:
 * - Updates expired qualifications to 'expired' status
 * - Sends email notifications 30 days before expiry
 * - Updates expiring qualifications to 'expiring' status
 */
class UpdateQualificationStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'employees:update-qualifications
                          {--dry-run : Show what would be updated without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update qualification statuses and send expiry notifications';

    /**
     * Number of days before expiry to send notification.
     */
    private const NOTIFICATION_DAYS = 30;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $today = Carbon::today();

        $this->info('Checking qualification status updates for '.$today->format('Y-m-d'));

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        // Process expired qualifications
        $expiredCount = $this->processExpiredQualifications($today, $isDryRun);

        // Process expiring qualifications (notification)
        $expiringCount = $this->processExpiringQualifications($today, $isDryRun);

        // Summary
        $this->newLine();
        $this->info('Summary:');
        $this->line("  Expired: {$expiredCount}");
        $this->line("  Expiring (notifications sent): {$expiringCount}");

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No actual changes were made');
        }

        Log::info('Qualification status update completed', [
            'date' => $today->format('Y-m-d'),
            'expired' => $expiredCount,
            'expiring_notifications' => $expiringCount,
            'dry_run' => $isDryRun,
        ]);

        return self::SUCCESS;
    }

    /**
     * Process expired qualifications.
     *
     * Finds active qualifications whose expiry_date has passed
     * and updates them to expired status.
     */
    private function processExpiredQualifications(Carbon $today, bool $isDryRun): int
    {
        $this->info('Processing expired qualifications...');

        $qualifications = EmployeeQualification::where('status', EmployeeQualification::STATUS_ACTIVE)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<', $today)
            ->with(['employee', 'qualification'])
            ->get();

        if ($qualifications->isEmpty()) {
            $this->line('  No qualifications expired');

            return 0;
        }

        $count = 0;

        foreach ($qualifications as $qualification) {
            try {
                $employee = $qualification->employee;

                if ($employee === null) {
                    $this->warn("  Skipping: Qualification {$qualification->id} has no associated employee");

                    continue;
                }

                $qualName = $qualification->qualification->name ?? 'Unknown';

                $this->line("  Expiring: {$employee->first_name} {$employee->last_name} - {$qualName}");

                if (! $isDryRun) {
                    $qualification->status = EmployeeQualification::STATUS_EXPIRED;
                    $qualification->save();
                }

                $count++;
            } catch (\Exception $e) {
                $this->error("  Failed to expire qualification {$qualification->id}: {$e->getMessage()}");
                Log::error('Failed to expire qualification', [
                    'qualification_id' => $qualification->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }

    /**
     * Process expiring qualifications and send notifications.
     *
     * Finds active qualifications that expire in exactly 30 days
     * and sends notification emails.
     */
    private function processExpiringQualifications(Carbon $today, bool $isDryRun): int
    {
        $this->info('Processing expiring qualifications (30-day notifications)...');

        $notificationDate = $today->copy()->addDays(self::NOTIFICATION_DAYS);

        $qualifications = EmployeeQualification::where('status', EmployeeQualification::STATUS_ACTIVE)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '=', $notificationDate)
            ->with(['employee.user', 'qualification'])
            ->get();

        if ($qualifications->isEmpty()) {
            $this->line('  No qualifications expiring in 30 days');

            return 0;
        }

        $count = 0;

        foreach ($qualifications as $qualification) {
            try {
                $employee = $qualification->employee;

                if ($employee === null) {
                    $this->warn("  Skipping: Qualification {$qualification->id} has no associated employee");

                    continue;
                }

                // Skip if employee has no user account or no email
                if (! $employee->user || ! $employee->email) {
                    $this->warn("  Skipping: Employee {$employee->id} has no user account or email");

                    continue;
                }

                $qualName = $qualification->qualification->name ?? 'Unknown';

                $this->line("  Notifying: {$employee->first_name} {$employee->last_name} - {$qualName}");

                if (! $isDryRun) {
                    // Update status to expiring
                    $qualification->status = EmployeeQualification::STATUS_EXPIRING;
                    $qualification->save();

                    // Send notification email
                    Mail::to($employee->email)->queue(new QualificationExpiringMail($qualification));
                }

                $count++;
            } catch (\Exception $e) {
                $this->error("  Failed to process qualification {$qualification->id}: {$e->getMessage()}");
                Log::error('Failed to process expiring qualification', [
                    'qualification_id' => $qualification->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }
}

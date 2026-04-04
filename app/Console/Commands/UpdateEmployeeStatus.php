<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Employee;
use App\Services\EmployeeLifecycleService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Daily command to update employee statuses based on contract dates.
 *
 * This command runs automatically every day at 06:00 and:
 * - Activates employees whose contract_start_date is today or in the past
 * - Deactivates employees whose termination_date is today or in the past
 *
 * Status transitions are applied through the explicit employee lifecycle service.
 */
class UpdateEmployeeStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'employees:update-status
                          {--dry-run : Show what would be updated without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update employee statuses based on contract dates (activate/deactivate)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $today = Carbon::today();

        $this->info('Checking employee status updates for '.$today->format('Y-m-d'));

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        // Process activations
        $activationCount = $this->processActivations($today, $isDryRun);

        // Process deactivations
        $deactivationCount = $this->processDeactivations($today, $isDryRun);

        // Summary
        $this->newLine();
        $this->info('Summary:');
        $this->line("  Activated: {$activationCount}");
        $this->line("  Deactivated: {$deactivationCount}");

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No actual changes were made');
        }

        Log::info('Employee status update completed', [
            'date' => $today->format('Y-m-d'),
            'activated' => $activationCount,
            'deactivated' => $deactivationCount,
            'dry_run' => $isDryRun,
        ]);

        return self::SUCCESS;
    }

    /**
     * Process employee activations.
     *
     * Finds pre_contract employees whose contract_start_date has arrived
     * and updates them to active status.
     */
    private function processActivations(Carbon $today, bool $isDryRun): int
    {
        $this->info('Processing activations...');

        $employees = Employee::where('status', Employee::STATUS_PRE_CONTRACT)
            ->where('onboarding_completed', true)
            ->whereDate('contract_start_date', '<=', $today)
            ->get();

        if ($employees->isEmpty()) {
            $this->line('  No employees to activate');

            return 0;
        }

        $count = 0;
        $lifecycleService = app(EmployeeLifecycleService::class);

        foreach ($employees as $employee) {
            try {
                $this->line("  Activating: {$employee->first_name} {$employee->last_name} (ID: {$employee->id})");

                if (! $isDryRun) {
                    $lifecycleService->activate($employee);
                }

                $count++;
            } catch (\Exception $e) {
                $this->error("  Failed to activate employee {$employee->id}: {$e->getMessage()}");
                Log::error('Failed to activate employee', [
                    'employee_id' => $employee->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }

    /**
     * Process employee deactivations.
     *
     * Finds active employees whose termination_date has arrived
     * and updates them to terminated status.
     */
    private function processDeactivations(Carbon $today, bool $isDryRun): int
    {
        $this->info('Processing deactivations...');

        $employees = Employee::whereIn('status', [Employee::STATUS_ACTIVE, Employee::STATUS_ON_LEAVE])
            ->whereDate('termination_date', '<=', $today)
            ->get();

        if ($employees->isEmpty()) {
            $this->line('  No employees to deactivate');

            return 0;
        }

        $count = 0;
        $lifecycleService = app(EmployeeLifecycleService::class);

        foreach ($employees as $employee) {
            try {
                $this->line("  Deactivating: {$employee->first_name} {$employee->last_name} (ID: {$employee->id})");

                if (! $isDryRun) {
                    $lifecycleService->terminate($employee);
                }

                $count++;
            } catch (\Exception $e) {
                $this->error("  Failed to deactivate employee {$employee->id}: {$e->getMessage()}");
                Log::error('Failed to deactivate employee', [
                    'employee_id' => $employee->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }
}

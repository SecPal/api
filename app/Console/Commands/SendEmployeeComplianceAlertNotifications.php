<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\EmployeeComplianceAlertMail;
use App\Models\Employee;
use App\Services\EmployeeComplianceService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendEmployeeComplianceAlertNotifications extends Command
{
    /**
     * @var string
     */
    protected $signature = 'employees:send-compliance-alert-notifications
                          {--dry-run : Show what would be sent without actually queueing emails}';

    /**
     * @var string
     */
    protected $description = 'Send employee compliance alert notifications for exact warning, critical, and expired milestones';

    /**
     * @var array<string, int>
     */
    private const NOTIFICATION_MILESTONES = [
        'warning' => 30,
        'critical' => 7,
        'expired' => -1,
    ];

    public function handle(EmployeeComplianceService $complianceService): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $today = Carbon::today();

        $this->info('Checking employee compliance alert notifications for '.$today->format('Y-m-d'));

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No emails will be queued');
        }

        $notifications = 0;
        $errors = 0;
        $severityCounts = [
            'warning' => 0,
            'critical' => 0,
            'expired' => 0,
        ];

        Employee::where('status', Employee::STATUS_ACTIVE)
            ->whereNotNull('user_id')
            ->whereNotNull('email')
            ->with('user')
            ->chunkById(100, function ($employees) use ($complianceService, $isDryRun, &$notifications, &$errors, &$severityCounts): void {
                foreach ($employees as $employee) {
                    $documents = $this->notificationDocuments($complianceService, $employee);

                    if ($documents->isEmpty()) {
                        continue;
                    }

                    $severity = (string) $documents->first()['status'];

                    $this->line("  Notifying: {$employee->first_name} {$employee->last_name} (ID: {$employee->id}) - {$severity}");

                    try {
                        if (! $isDryRun) {
                            Mail::to($employee->email)->queue(new EmployeeComplianceAlertMail($employee, $documents->all(), $severity));
                        }

                        $notifications++;
                        $severityCounts[$severity]++;
                    } catch (\Throwable $e) {
                        $errors++;
                        $this->error("  Failed to queue notification for employee {$employee->id}: {$e->getMessage()}");
                        Log::error('Failed to queue compliance alert notification', [
                            'employee_id' => $employee->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        $this->newLine();
        $this->info('Summary:');
        $this->line("  Notifications queued: {$notifications}");
        $this->line('  Warning: '.$severityCounts['warning']);
        $this->line('  Critical: '.$severityCounts['critical']);
        $this->line('  Expired: '.$severityCounts['expired']);

        if ($errors > 0) {
            $this->warn("  Errors: {$errors}");
        }

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No actual emails were queued');
        }

        Log::info('Employee compliance alert notifications processed', [
            'date' => $today->format('Y-m-d'),
            'notifications' => $notifications,
            'warning' => $severityCounts['warning'],
            'critical' => $severityCounts['critical'],
            'expired' => $severityCounts['expired'],
            'errors' => $errors,
            'dry_run' => $isDryRun,
        ]);

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, array{type: string, label: string, expiry: string, status: string, days_until_expiry: int}>
     */
    private function notificationDocuments(EmployeeComplianceService $complianceService, Employee $employee): Collection
    {
        $documents = $complianceService->alertDocuments($employee)
            ->filter(fn (array $document): bool => in_array($document['days_until_expiry'], self::NOTIFICATION_MILESTONES, true))
            ->map(fn (array $document): array => [
                'type' => (string) $document['type'],
                'label' => (string) $document['label'],
                'expiry' => (string) $document['expiry'],
                'status' => (string) $document['status'],
                'days_until_expiry' => (int) $document['days_until_expiry'],
            ])
            ->values();

        if ($documents->isEmpty()) {
            return $documents;
        }

        $severity = $this->highestSeverity($complianceService, $documents);

        return $documents
            ->filter(fn (array $document): bool => $document['status'] === $severity)
            ->values();
    }

    /**
     * @param  Collection<int, array{type: string, label: string, expiry: string, status: string, days_until_expiry: int}>  $documents
     */
    private function highestSeverity(EmployeeComplianceService $complianceService, Collection $documents): string
    {
        /** @var string $severity */
        $severity = $documents
            ->sortByDesc(fn (array $document): int => $complianceService->severity((string) $document['status']))
            ->pluck('status')
            ->first();

        return $severity;
    }
}

<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace App\Console\Commands;

use App\Services\ExpiredEmployeeDeletionService;
use Illuminate\Console\Command;

class DeleteExpiredEmployees extends Command
{
    protected $signature = 'employees:delete-expired
        {--tenant= : Only process a single tenant ID}
        {--dry-run : Report matching employees without deleting them}';

    protected $description = 'Delete terminated employee records whose legal retention period has expired';

    public function __construct(private readonly ExpiredEmployeeDeletionService $expiredEmployeeDeletionService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $tenantOption = $this->option('tenant');
        $tenantId = null;

        if (($tenantOption !== null) && ($tenantOption !== '')) {
            $tenantValue = (string) $tenantOption;

            if (! ctype_digit($tenantValue)) {
                $this->error('The --tenant option must be a numeric tenant ID.');

                return self::FAILURE;
            }

            $tenantId = (int) $tenantValue;
        }

        $dryRun = (bool) $this->option('dry-run');
        $stats = $this->expiredEmployeeDeletionService->deleteExpiredEmployees($tenantId, $dryRun);

        if ($tenantId !== null) {
            $this->line('Tenant scope: '.$tenantId);
        }

        if ($dryRun) {
            $this->info('Would delete '.$stats['matched'].' expired employee record(s)');

            return self::SUCCESS;
        }

        $this->info('Deleted '.$stats['deleted'].' expired employee record(s)');
        $this->line('Linked user(s) anonymized: '.$stats['users_anonymized']);
        $this->line('Local file(s) deleted: '.$stats['files_deleted']);

        return self::SUCCESS;
    }
}

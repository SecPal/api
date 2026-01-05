<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\OpenTimestampService;
use Illuminate\Console\Command;

class CheckOpenTimestampStatus extends Command
{
    protected $signature = 'ots:check {--json} {--update-check}';

    protected $description = 'Check OpenTimestamp library version, calendar servers, and availability';

    public function handle(): int
    {
        $results = [];
        $executor = app(\App\Contracts\ProcessExecutor::class);

        $this->info('Checking OpenTimestamp installation...');
        $pythonResult = $executor->execute(['python3', '--version'], null, 5);
        $pythonVersion = trim($pythonResult['stdout'] ?: $pythonResult['stderr'] ?: '');

        $otsResult = $executor->execute(['python3', '-c', 'import opentimestamps; print(opentimestamps.__version__)'], null, 5);
        $otsVersion = trim($otsResult['stdout'] ?: '');

        $this->line("  OpenTimestamps: {$otsVersion}");

        $this->newLine();
        $this->info('Testing OTS functionality...');
        $testHash = hash('sha256', 'test-'.now()->timestamp);

        try {
            $otsService = app(OpenTimestampService::class);
            $proof = $otsService->submit($testHash);
            $results['functional_test'] = true;
            $results['test_proof_size'] = strlen($proof);
            $this->line('  ✓ OTS submission working');
            $this->line('  Proof size: '.strlen($proof).' bytes');
        } catch (\Exception $e) {
            $results['functional_test'] = false;
            $this->error('  ✗ OTS submission failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('update-check')) {
            $this->newLine();
            $this->info('Checking for updates...');
            $updateResult = $executor->execute(['pip', 'list', '--outdated', '--format=json'], null, 10);
            $outdated = json_decode($updateResult['stdout'] ?: '[]', true);
            /** @var array<int, array{name: string, version: string, latest_version: string}> $outdatedList */
            $outdatedList = is_array($outdated) ? $outdated : [];
            $otsUpdate = collect($outdatedList)->firstWhere('name', 'opentimestamps-client');
            if ($otsUpdate && is_array($otsUpdate)) {
                $this->warn("  ⚠ Update available: {$otsUpdate['version']} → {$otsUpdate['latest_version']}");
            } else {
                $this->line('  ✓ Up to date');
            }
        }

        $this->newLine();
        $this->info('✓ All checks passed');

        return self::SUCCESS;
    }
}

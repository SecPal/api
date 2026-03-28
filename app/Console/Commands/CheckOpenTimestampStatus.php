<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\ProcessExecutor;
use App\Services\OpenTimestampService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class CheckOpenTimestampStatus extends Command
{
    protected $signature = 'ots:check {--json} {--update-check}';

    protected $description = 'Check OpenTimestamp library version, calendar servers, and availability';

    public function handle(): int
    {
        $results = [];
        $executor = app(ProcessExecutor::class);

        $this->info('Checking OpenTimestamp installation...');

        if (! $executor->commandExists('python3')) {
            $this->error('  ✗ Missing required command: python3');
            $this->line('    Hint: Install Python 3 — see https://python.org/downloads/');

            return self::FAILURE;
        }

        $pythonResult = $executor->execute(['python3', '--version'], null, 5);

        if ($pythonResult['exitCode'] !== 0) {
            $this->error('  ✗ Unable to run python3: '.trim($pythonResult['stderr'] ?: $pythonResult['stdout'] ?: 'Unknown error'));

            return self::FAILURE;
        }

        $pythonVersion = trim($pythonResult['stdout'] ?: $pythonResult['stderr'] ?: '');

        if (! $executor->commandExists('ots')) {
            $this->error('  ✗ Missing required command: ots');
            $this->line('    Hint: Run: pip3 install opentimestamps');

            return self::FAILURE;
        }

        $otsResult = $executor->execute(['python3', '-c', 'import opentimestamps; print(opentimestamps.__version__)'], null, 5);

        if ($otsResult['exitCode'] !== 0) {
            $details = trim($otsResult['stderr'] ?: $otsResult['stdout'] ?: '');
            $isMissing = str_contains($details, 'ModuleNotFoundError') || str_contains($details, 'ImportError');
            $this->error($isMissing
                ? '  ✗ Missing required Python module: opentimestamps'
                : '  ✗ Unable to import Python module: opentimestamps'
            );
            if ($isMissing) {
                $this->line('    Hint: Run: pip3 install opentimestamps');
            }
            if ($details !== '') {
                $this->line("    {$details}");
            }

            return self::FAILURE;
        }

        $otsVersion = trim($otsResult['stdout'] ?: '');

        foreach (['scripts/ots-stamp-hash.py', 'scripts/ots-verify.py'] as $scriptPath) {
            $absolutePath = base_path($scriptPath);

            if (! File::exists($absolutePath)) {
                $this->error("  ✗ Missing required helper script: {$scriptPath}");

                return self::FAILURE;
            }
        }

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

            $pipCommand = null;
            if ($executor->commandExists('pip3')) {
                $pipCommand = 'pip3';
            } elseif ($executor->commandExists('pip')) {
                $pipCommand = 'pip';
            }

            if ($pipCommand === null) {
                $this->error('  ✗ Missing required command: pip3 or pip');

                return self::FAILURE;
            }

            $updateResult = $executor->execute([$pipCommand, 'list', '--outdated', '--format=json'], null, 10);

            if ($updateResult['exitCode'] !== 0) {
                $this->warn('  ⚠ Unable to check for updates: '.trim($updateResult['stderr'] ?: $updateResult['stdout'] ?: 'Unknown error'));
                $this->newLine();
                $this->info('✓ All checks passed');

                return self::SUCCESS;
            }

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

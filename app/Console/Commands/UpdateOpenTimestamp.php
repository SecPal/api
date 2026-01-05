<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Update OpenTimestamp library to the latest version
 */
class UpdateOpenTimestamp extends Command
{
    protected $signature = 'ots:update
                          {--dry-run : Show what would be updated without actually updating}';

    protected $description = 'Update OpenTimestamp library to the latest version';

    /**
     * Execute the console command.
     *
     * This command checks the installed OpenTimestamp (opentimestamps-client) Python
     * package for available updates, optionally performs the upgrade using pip, and
     * then runs a follow-up health check on the configured calendar servers via the
     * ots:check command.
     *
     * @return int Symfony console exit code (Command::SUCCESS or Command::FAILURE)
     */
    public function handle(): int
    {
        $executor = app(\App\Contracts\ProcessExecutor::class);
        $this->info('Checking for OpenTimestamp updates...');

        // 1. Check current version
        $versionResult = $executor->execute(['python3', '-c', 'import opentimestamps; print(opentimestamps.__version__)'], null, 5);
        $currentVersion = trim($versionResult['stdout'] ?: '');
        $this->line("Current version: {$currentVersion}");

        // 2. Check for updates
        $updateResult = $executor->execute(['pip', 'list', '--outdated', '--format=json'], null, 10);
        $outdated = json_decode($updateResult['stdout'] ?: '[]', true);
        /** @var array<int, array{name: string, version: string, latest_version: string}> $outdatedList */
        $outdatedList = is_array($outdated) ? $outdated : [];

        $otsUpdate = collect($outdatedList)->firstWhere('name', 'opentimestamps-client');

        if (! $otsUpdate || ! is_array($otsUpdate)) {
            $this->info('✓ OpenTimestamps is already up to date');

            return self::SUCCESS;
        }

        $latestVersion = $otsUpdate['latest_version'];
        $this->warn("Update available: {$currentVersion} → {$latestVersion}");

        if ($this->option('dry-run')) {
            $this->info('Dry run - no changes made');
            $this->line('Would run: pip install --upgrade opentimestamps-client');

            return self::SUCCESS;
        }

        // 3. Ask for confirmation
        if (! $this->confirm('Do you want to update OpenTimestamp now?', true)) {
            $this->info('Update cancelled');

            return self::SUCCESS;
        }

        // 4. Perform update
        $this->info('Updating OpenTimestamp...');

        $output = [];
        $returnCode = 0;
        exec('pip install --upgrade opentimestamps-client 2>&1', $output, $returnCode);

        foreach ($output as $line) {
            $this->line("  {$line}");
        }

        if ($returnCode === 0) {
            $newVersion = trim(shell_exec('python3 -c "import opentimestamps; print(opentimestamps.__version__)" 2>&1') ?: '');
            $this->info("✓ Successfully updated to version {$newVersion}");

            // 5. Check calendar servers after update
            $this->newLine();
            $this->info('Checking calendar servers after update...');

            $this->call('ots:check', ['--json' => false]);

            return self::SUCCESS;
        } else {
            $this->error('✗ Update failed');

            return self::FAILURE;
        }
    }
}

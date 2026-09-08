<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Console\Commands;

use App\Services\AddressData\AddressDataImportService;
use Illuminate\Console\Command;

class ImportAddressDataCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'addresses:import
                            {--force : Re-import even when the authorized source checksum matches the active dataset}
                            {--dry-run : Validate the CSV without persisting rows}
                            {--source= : Path to a local CSV instead of downloading}
                            {--expected-sha256= : Exact lowercase SHA-256 authorized for the source bytes}
                            {--if-empty : Skip when an activated import already exists}
                            {--setup-only : Only run when ADDRESS_DATA_IMPORT_ON_SETUP is enabled}
                            {--keep-imports=0 : Keep this many prior import versions (street rows + metadata)}';

    /**
     * @var string
     */
    protected $description = 'Download and import OpenPLZ German street data for address autocomplete.';

    public function handle(AddressDataImportService $importService): int
    {
        $this->line('Address data import started.');

        $onProgress = function (string $message): void {
            $this->line('  '.$message);
            if (function_exists('fflush') && defined('STDOUT') && is_resource(\STDOUT)) {
                @fflush(\STDOUT);
            }
        };

        $result = $importService->run(
            force: (bool) $this->option('force'),
            dryRun: (bool) $this->option('dry-run'),
            sourcePath: $this->sourcePath(),
            expectedSha256: $this->expectedSha256(),
            ifEmpty: (bool) $this->option('if-empty'),
            setupOnly: (bool) $this->option('setup-only'),
            keepImports: max(0, (int) $this->option('keep-imports')),
            onProgress: $onProgress,
        );

        match ($result['status']) {
            'failed' => $this->components->error($result['message']),
            'skipped' => $this->components->warn($result['message']),
            default => $this->components->info($result['message']),
        };

        return match ($result['status']) {
            'failed' => self::FAILURE,
            default => self::SUCCESS,
        };
    }

    private function sourcePath(): ?string
    {
        $explicitSource = $this->option('source');
        if ($explicitSource !== null && trim((string) $explicitSource) !== '') {
            return (string) $explicitSource;
        }

        if (! (bool) $this->option('setup-only')) {
            return null;
        }

        $setupSourcePath = config('address_data.setup_source_path');

        return is_string($setupSourcePath) && trim($setupSourcePath) !== ''
            ? $setupSourcePath
            : null;
    }

    private function expectedSha256(): ?string
    {
        $explicitDigest = $this->option('expected-sha256');
        if ($explicitDigest !== null) {
            return (string) $explicitDigest;
        }

        $configuredDigest = config('address_data.expected_sha256');

        return is_string($configuredDigest) ? $configuredDigest : null;
    }
}

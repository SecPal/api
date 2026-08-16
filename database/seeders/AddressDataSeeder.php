<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Database\Seeders;

use App\Services\AddressData\AddressDataImportService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class AddressDataSeeder extends Seeder
{
    /**
     * Download and activate the German address dataset during setup seeding.
     */
    public function run(): void
    {
        if (! Schema::hasTable('address_data_imports') || ! Schema::hasTable('address_streets')) {
            $this->command->warn('Skipped: address data tables are missing; run migrations before setup import.');

            return;
        }

        /** @var AddressDataImportService $importService */
        $importService = app(AddressDataImportService::class);

        $sourcePath = config('address_data.setup_source_path');
        $sourcePath = is_string($sourcePath) && trim($sourcePath) !== ''
            ? $sourcePath
            : null;

        $result = $importService->run(
            force: false,
            dryRun: false,
            sourcePath: $sourcePath,
            ifEmpty: true,
            setupOnly: true,
            keepImports: 0,
            onProgress: function (string $message): void {
                $this->command->line('  '.$message);
            },
        );

        if ($result['status'] === 'failed') {
            throw new RuntimeException('Address data setup import failed: '.$result['message']);
        }

        match ($result['status']) {
            'skipped' => $this->command->warn($result['message']),
            default => $this->command->info($result['message']),
        };
    }
}

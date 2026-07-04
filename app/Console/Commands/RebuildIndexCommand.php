<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution
 */

namespace App\Console\Commands;

use App\Models\Person;
use App\Models\TenantKey;
use App\Traits\NormalizesPersonFields;
use Illuminate\Console\Command;

/**
 * Rebuild blind indexes for a specific tenant.
 *
 * This command:
 * 1. Loads all Person records for the tenant
 * 2. Decrypts email_enc and phone_enc
 * 3. Regenerates blind indexes using the current idx_key
 * 4. Updates the Person records
 *
 * Use cases:
 * - After idx_key rotation
 * - After index corruption
 * - After normalization rules change
 */
class RebuildIndexCommand extends Command
{
    use NormalizesPersonFields;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'idx:rebuild {tenant : The tenant ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rebuild blind indexes for a specific tenant';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $tenantId = $this->argument('tenant');

        $this->info("Rebuilding blind indexes for tenant {$tenantId}...");

        try {
            // Find tenant
            $tenant = TenantKey::find($tenantId);

            if (! $tenant) {
                $this->error("Tenant {$tenantId} not found.");

                return Command::FAILURE;
            }

            // Get all Person records
            $persons = Person::where('tenant_id', $tenantId)->get();
            $this->info("Processing {$persons->count()} record(s)...");

            $bar = $this->output->createProgressBar($persons->count());

            foreach ($persons as $person) {
                // Decrypt and rebuild email_idx
                if ($person->getAttributes()['email_enc']) {
                    $emailPlain = $person->email_enc; // Uses cast to decrypt
                    if ($emailPlain !== null && $emailPlain !== '') {
                        $normalized = $this->normalizeEmail($emailPlain);
                        $rawIdx = $tenant->generateBlindIndex($normalized);
                        $person->email_idx = base64_encode($rawIdx); // Store as base64
                    }
                }

                // Decrypt and rebuild phone_idx
                if ($person->getAttributes()['phone_enc']) {
                    $phonePlain = $person->phone_enc; // Uses cast to decrypt
                    if ($phonePlain !== null && $phonePlain !== '') {
                        $normalized = $this->normalizePhone($phonePlain);
                        $rawIdx = $tenant->generateBlindIndex($normalized);
                        $person->phone_idx = base64_encode($rawIdx); // Store as base64
                    }
                }

                $person->saveQuietly(); // Avoid triggering observers

                $bar->advance();
            }

            $bar->finish();
            $this->newLine();

            $this->info("✅ Rebuilt indexes for {$persons->count()} record(s)");
            $this->info('✅ Index rebuild complete!');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('❌ Index rebuild failed: '.$e->getMessage());

            return Command::FAILURE;
        }
    }
}

<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Console\Commands;

use App\Models\Person;
use App\Models\TenantKey;
use App\Support\BlindIndex;
use App\Support\KeyStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Rebuild blind indexes for a tenant.
 *
 * This command:
 * 1. Loads the tenant's idx_key
 * 2. Re-computes blind indexes for all encrypted fields
 * 3. Updates all records with the new indexes
 *
 * The operation is idempotent - running it multiple times produces the same result.
 * Useful for:
 * - Recovering from index corruption
 * - Migrating to new index algorithm
 * - Verifying index integrity
 */
class RebuildIdxCommand extends Command
{
    protected $signature = 'secpal:rebuild-idx
                            {tenant_id : UUID of the tenant}
                            {--force : Skip confirmation prompt}
                            {--batch=100 : Batch size for processing records}';

    protected $description = 'Rebuild blind indexes for a tenant';

    public function handle(KeyStore $keyStore): int
    {
        $tenantId = $this->argument('tenant_id');
        $batchSize = (int) $this->option('batch');

        // Validate tenant exists
        $tenantKey = TenantKey::find($tenantId);
        if (! $tenantKey) {
            $this->error("Tenant not found: {$tenantId}");

            return 1;
        }

        // Confirmation prompt
        if (! $this->option('force')) {
            if (! $this->confirm("This will rebuild all blind indexes for tenant {$tenantId}. Continue?")) {
                $this->info('Aborted.');

                return 0;
            }
        }

        Log::info('Starting blind index rebuild', [
            'tenant_id' => $tenantId,
            'batch_size' => $batchSize,
        ]);

        $this->info("Rebuilding blind indexes for tenant: {$tenantId}");

        try {
            // Load idx_key
            $idxKey = $keyStore->unwrapIdxKeyForTenant($tenantId);

            // Process all Person records
            $totalRecords = Person::where('tenant_id', $tenantId)->count();
            $this->info("Processing {$totalRecords} records...");

            $bar = $this->output->createProgressBar($totalRecords);
            $bar->start();

            Person::where('tenant_id', $tenantId)
                ->chunk($batchSize, function ($persons) use ($idxKey, $bar) {
                    foreach ($persons as $person) {
                        // Laravel's 'encrypted' cast handles decryption
                        $emailPlain = $person->email;
                        $phonePlain = $person->phone;

                        // Rebuild indexes manually
                        $person->email_idx = BlindIndex::hmac(
                            BlindIndex::normEmail($emailPlain),
                            $idxKey
                        );

                        if ($phonePlain) {
                            $person->phone_idx = BlindIndex::hmac(
                                BlindIndex::normPhone($phonePlain),
                                $idxKey
                            );
                        }

                        $person->saveQuietly(); // Skip observers to avoid re-encryption

                        $bar->advance();
                    }
                });

            $bar->finish();
            $this->newLine();

            Log::info('Blind index rebuild completed', [
                'tenant_id' => $tenantId,
                'records_processed' => $totalRecords,
            ]);

            $this->info('✓ Blind index rebuild completed successfully');

            return 0;

        } catch (\Exception $e) {
            Log::error('Blind index rebuild failed', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            $this->error('Blind index rebuild failed: '.$e->getMessage());

            return 1;
        }
    }
}

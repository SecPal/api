<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Console\Commands;

use App\Models\Person;
use App\Models\TenantKey;
use App\Support\KeyStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Rotate the Data Encryption Key (DEK) for a tenant.
 *
 * This command:
 * 1. Loads the old tenant-specific DEK
 * 2. Generates a new tenant-specific DEK
 * 3. Decrypts all data with old DEK
 * 4. Re-encrypts all data with new DEK
 * 5. Wraps the new DEK with KEK
 * 6. Updates the tenant_keys record
 *
 * The operation is transactional and can be run multiple times (idempotent).
 */
class RotateDekCommand extends Command
{
    protected $signature = 'secpal:rotate-dek
                            {tenant_id : UUID of the tenant}
                            {--force : Skip confirmation prompt}
                            {--batch=100 : Batch size for processing records}';

    protected $description = 'Rotate the Data Encryption Key (DEK) for a tenant';

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
            if (! $this->confirm("This will re-encrypt all data for tenant {$tenantId}. Continue?")) {
                $this->info('Aborted.');

                return 0;
            }
        }

        Log::info('Starting DEK rotation', [
            'tenant_id' => $tenantId,
            'batch_size' => $batchSize,
        ]);

        $this->info("Rotating DEK for tenant: {$tenantId}");

        try {
            DB::transaction(function () use ($keyStore, $tenantId, $batchSize, $tenantKey) {
                // Step 1: Load old DEK
                $this->info('Loading old DEK...');
                $oldDek = $keyStore->unwrapDekForTenant($tenantId);

                // Step 2: Generate new DEK
                $this->info('Generating new DEK...');
                $newDek = $keyStore->generateKey();

                // Step 3 & 4: Re-encrypt all Person records with manual decryption/encryption
                $totalRecords = Person::where('tenant_id', $tenantId)->count();
                $this->info("Re-encrypting {$totalRecords} records...");

                $bar = $this->output->createProgressBar($totalRecords);
                $bar->start();

                Person::where('tenant_id', $tenantId)
                    ->chunk($batchSize, function ($persons) use ($bar, $oldDek, $newDek) {
                        foreach ($persons as $person) {
                            // Manual decryption with old DEK
                            $fields = ['email', 'phone', 'address', 'note'];

                            foreach ($fields as $field) {
                                $encField = $field.'_enc';
                                $nonceField = $field.'_nonce';

                                // Get raw attributes to bypass casts
                                $rawAttributes = $person->getAttributes();

                                if (! isset($rawAttributes[$encField]) || $rawAttributes[$encField] === null) {
                                    continue;
                                }

                                // Decrypt with old DEK (handle PostgreSQL BYTEA as resource)
                                $ciphertext = is_resource($rawAttributes[$encField])
                                    ? stream_get_contents($rawAttributes[$encField])
                                    : $rawAttributes[$encField];
                                $nonce = is_resource($rawAttributes[$nonceField])
                                    ? stream_get_contents($rawAttributes[$nonceField])
                                    : $rawAttributes[$nonceField];

                                $plaintext = sodium_crypto_aead_aes256gcm_decrypt(
                                    $ciphertext,
                                    '',
                                    $nonce,
                                    $oldDek
                                );

                                if ($plaintext === false) {
                                    throw new \RuntimeException(
                                        "Failed to decrypt {$field} for person {$person->id}"
                                    );
                                }

                                // Re-encrypt with new DEK
                                $newNonce = random_bytes(SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES);
                                $newCiphertext = sodium_crypto_aead_aes256gcm_encrypt(
                                    $plaintext,
                                    '',
                                    $newNonce,
                                    $newDek
                                );

                                // Update raw attributes (bypass casts)
                                DB::table('person')
                                    ->where('id', $person->id)
                                    ->update([
                                        $encField => $newCiphertext,
                                        $nonceField => $newNonce,
                                    ]);

                                sodium_memzero($plaintext);
                            }

                            $bar->advance();
                        }
                    });

                $bar->finish();
                $this->newLine();

                // Step 5 & 6: Wrap new DEK and update tenant_keys
                $this->info('Updating tenant keys...');
                $kek = $keyStore->loadKek();
                $wrapped = $keyStore->wrapKey($newDek, $kek);

                $tenantKey->dek_wrapped = $wrapped['wrapped'];
                $tenantKey->dek_nonce = $wrapped['nonce'];
                $tenantKey->save();

                // Clear cache
                $keyStore->clearCache($tenantId);

                // Clean up keys from memory
                sodium_memzero($oldDek);
                sodium_memzero($newDek);
            });

            Log::info('DEK rotation completed', [
                'tenant_id' => $tenantId,
            ]);

            $this->info('✓ DEK rotation completed successfully');

            return 0;

        } catch (\Exception $e) {
            Log::error('DEK rotation failed', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            $this->error('DEK rotation failed: '.$e->getMessage());

            return 1;
        }
    }
}

<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Console\Commands;

use App\Models\Person;
use App\Models\TenantKey;
use Illuminate\Console\Command;

/**
 * Rotate the Data Encryption Key (DEK) for a specific tenant.
 *
 * This command:
 * 1. Generates a new DEK
 * 2. Re-encrypts all encrypted fields in the tenant's data
 * 3. Updates the tenant_keys table with the new wrapped DEK
 * 4. Increments the key_version
 *
 * Note: Blind indexes (idx_key) are NOT rotated here.
 * Use idx:rebuild if idx_key needs rotation.
 */
class RotateDekCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'keys:rotate-dek {tenant : The tenant ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rotate the DEK for a specific tenant and re-encrypt all data';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $tenantId = $this->argument('tenant');

        $this->info("Starting DEK rotation for tenant {$tenantId}...");

        try {
            // Find tenant
            $tenant = TenantKey::find($tenantId);

            if (! $tenant) {
                $this->error("Tenant {$tenantId} not found.");

                return Command::FAILURE;
            }

            // Step 1: Unwrap old DEK
            $oldDek = $tenant->unwrapDek();

            // Step 2: Generate new DEK
            $kek = TenantKey::loadKek();
            $newDek = sodium_crypto_secretbox_keygen();
            $dekNonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $dekWrapped = sodium_crypto_secretbox($newDek, $dekNonce, $kek);

            sodium_memzero($kek);

            $this->info('✅ Generated new DEK');

            // Step 3: Re-encrypt all Person records with new DEK
            $persons = Person::where('tenant_id', $tenantId)->get();
            $this->info("Re-encrypting {$persons->count()} record(s)...");

            $bar = $this->output->createProgressBar($persons->count());

            foreach ($persons as $person) {
                $updates = [];

                // Process each encrypted field
                foreach (['email_enc', 'phone_enc', 'note_enc'] as $field) {
                    $encrypted = $person->getAttributes()[$field];
                    if ($encrypted === null || ! is_string($encrypted)) {
                        continue;
                    }

                    // Decrypt with old DEK
                    $data = json_decode($encrypted, true);
                    if (! is_array($data) || ! isset($data['ciphertext'], $data['nonce'])) {
                        continue;
                    }

                    if (! is_string($data['ciphertext']) || ! is_string($data['nonce'])) {
                        continue;
                    }

                    $ciphertext = base64_decode($data['ciphertext'], true);
                    $nonce = base64_decode($data['nonce'], true);

                    if ($ciphertext === false || $nonce === false) {
                        $this->error("Failed to decode base64 for {$field} in person {$person->id}");

                        continue;
                    }

                    $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $oldDek);

                    if ($plaintext === false) {
                        $this->error("Failed to decrypt {$field} for person {$person->id}");

                        continue;
                    }

                    // Re-encrypt with new DEK
                    $newNonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
                    $newCiphertext = sodium_crypto_secretbox($plaintext, $newNonce, $newDek);

                    // Prepare JSON for direct DB update (bypass cast and observers)
                    $updates[$field] = json_encode([
                        'ciphertext' => base64_encode($newCiphertext),
                        'nonce' => base64_encode($newNonce),
                    ]);

                    sodium_memzero($plaintext);
                }

                // Direct DB update to bypass cast and observers
                if (! empty($updates)) {
                    Person::where('id', $person->id)->update($updates);
                }

                $bar->advance();
            }

            $bar->finish();
            $this->newLine();

            // Step 4: Update tenant with new wrapped DEK and increment version
            $oldVersion = $tenant->key_version;
            $tenant->update([
                'dek_wrapped' => $dekWrapped,
                'dek_nonce' => $dekNonce,
                'key_version' => $tenant->key_version + 1,
            ]);

            sodium_memzero($oldDek);
            sodium_memzero($newDek);

            $this->info("✅ Re-encrypted {$persons->count()} record(s)");
            $this->info("✅ Updated tenant {$tenantId}: key_version {$oldVersion} → {$tenant->key_version}");
            $this->info('✅ DEK rotation complete!');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('❌ DEK rotation failed: '.$e->getMessage());

            return Command::FAILURE;
        }
    }
}

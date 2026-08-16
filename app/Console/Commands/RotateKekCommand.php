<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Console\Commands;

use App\Models\TenantKey;
use Illuminate\Console\Command;

/**
 * Rotate the Key Encryption Key (KEK) and re-wrap all tenant keys.
 *
 * This command:
 * 1. Backs up the old KEK
 * 2. Generates a new KEK
 * 3. Unwraps all tenant DEKs and idx_keys with the old KEK
 * 4. Re-wraps them with the new KEK
 * 5. Updates the tenant_keys table
 *
 * WARNING: Keep the backup KEK safe until all tenants are verified!
 */
class RotateKekCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'keys:rotate-kek';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rotate the KEK and re-wrap all tenant keys';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting KEK rotation...');

        try {
            // Use TenantKey's getKekPath() method to respect test overrides
            $kekPath = TenantKey::getKekPath();

            if (! file_exists($kekPath)) {
                $this->error('❌ KEK file not found at: '.$kekPath);

                return Command::FAILURE;
            }

            // Step 1: Load old KEK
            $oldKek = file_get_contents($kekPath);

            if ($oldKek === false || strlen($oldKek) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                $this->error('❌ Invalid KEK file');

                return Command::FAILURE;
            }

            // Step 2: Backup old KEK
            $backupPath = $kekPath.'.'.date('Y-m-d_H-i-s').'.bak';
            file_put_contents($backupPath, $oldKek);
            chmod($backupPath, 0600);
            $this->info("✅ Backed up old KEK to: {$backupPath}");

            // Step 3: Generate new KEK
            $newKek = sodium_crypto_secretbox_keygen();
            file_put_contents($kekPath, $newKek);
            chmod($kekPath, 0600);
            $this->info('✅ Generated new KEK');

            // Step 4: Re-wrap all tenant keys
            $tenants = TenantKey::all();
            $this->info("Re-wrapping keys for {$tenants->count()} tenant(s)...");

            $bar = $this->output->createProgressBar($tenants->count());

            foreach ($tenants as $tenant) {
                // Unwrap with old KEK
                $dek = sodium_crypto_secretbox_open(
                    $tenant->dek_wrapped,
                    $tenant->dek_nonce,
                    $oldKek
                );

                $idxKey = sodium_crypto_secretbox_open(
                    $tenant->idx_wrapped,
                    $tenant->idx_nonce,
                    $oldKek
                );

                if ($dek === false || $idxKey === false) {
                    $this->error("\n❌ Failed to unwrap keys for tenant {$tenant->id}");
                    sodium_memzero($oldKek);
                    sodium_memzero($newKek);

                    return Command::FAILURE;
                }

                // Re-wrap with new KEK
                $dekNonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
                $dekWrapped = sodium_crypto_secretbox($dek, $dekNonce, $newKek);

                $idxNonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
                $idxWrapped = sodium_crypto_secretbox($idxKey, $idxNonce, $newKek);

                // Update tenant
                $tenant->update([
                    'dek_wrapped' => $dekWrapped,
                    'dek_nonce' => $dekNonce,
                    'idx_wrapped' => $idxWrapped,
                    'idx_nonce' => $idxNonce,
                ]);

                sodium_memzero($dek);
                sodium_memzero($idxKey);

                $bar->advance();
            }

            $bar->finish();
            $this->newLine();

            sodium_memzero($oldKek);
            sodium_memzero($newKek);

            $this->info("✅ Re-wrapped {$tenants->count()} tenant(s)");
            $this->info('✅ KEK rotation complete!');
            $this->warn("⚠️  Keep backup KEK safe until all tenants are verified: {$backupPath}");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('❌ KEK rotation failed: '.$e->getMessage());

            return Command::FAILURE;
        }
    }
}

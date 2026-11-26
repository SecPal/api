<?php

/*
 * SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
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
 * 1. Authenticates existing envelopes and prepares the replacements
 * 2. Backs up the old KEK and writes the new KEK
 * 3. Updates the tenant_keys table
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

        $oldKek = null;
        $newKek = null;

        try {
            // Use TenantKey's getKekPath() method to respect test overrides
            $kekPath = TenantKey::getKekPath();

            if (! file_exists($kekPath)) {
                $this->error('❌ KEK file not found at: '.$kekPath);

                return Command::FAILURE;
            }

            $oldKek = TenantKey::loadKek();

            // Authenticate every tenant and purpose before changing keys on disk.
            $newKek = sodium_crypto_secretbox_keygen();
            $tenants = TenantKey::all();
            $rewrapped = [];
            foreach ($tenants as $tenantKey) {
                $rewrapped[$tenantKey->id] = $tenantKey->rewrappedEnvelopes($oldKek, $newKek);
            }

            $backupPath = $kekPath.'.'.date('Y-m-d_H-i-s').'.bak';
            file_put_contents($backupPath, $oldKek);
            chmod($backupPath, 0600);
            $this->info("✅ Backed up old KEK to: {$backupPath}");

            file_put_contents($kekPath, $newKek);
            chmod($kekPath, 0600);
            $this->info('✅ Generated new KEK');

            $this->info("Re-wrapping keys for {$tenants->count()} tenant(s)...");

            $bar = $this->output->createProgressBar($tenants->count());

            foreach ($tenants as $tenantKey) {
                $tenantKey->update($rewrapped[$tenantKey->id]);

                $bar->advance();
            }

            $bar->finish();
            $this->newLine();

            $this->info("✅ Re-wrapped {$tenants->count()} tenant(s)");
            $this->info('✅ KEK rotation complete!');
            $this->warn("⚠️  Keep backup KEK safe until all tenants are verified: {$backupPath}");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('❌ KEK rotation failed: '.$e->getMessage());

            return Command::FAILURE;
        } finally {
            if ($oldKek !== null) {
                sodium_memzero($oldKek);
            }
            if ($newKek !== null) {
                sodium_memzero($newKek);
            }
        }
    }
}

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
 * Guided tenant key setup command for new deployments.
 *
 * This command initializes tenant keys for a new SecPal deployment by:
 * 1. Validating KEK file existence
 * 2. Generating random DEK and idx_key
 * 3. Wrapping keys with KEK using sodium_crypto_secretbox
 * 4. Storing wrapped keys in tenant_keys table
 *
 * Security considerations:
 * - KEK file must have 0600 permissions (owner read/write only)
 * - Never logs plaintext keys
 * - Uses cryptographically secure random generation
 * - Prevents duplicate setup (idempotent)
 */
class TenantSetupCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenant:setup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Tenant key setup for new deployments';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('SecPal Tenant Key Setup');
        $this->info('=======================');
        $this->newLine();

        // Step 1: Check if tenant key already exists
        try {
            $existingCount = TenantKey::count();
            if ($existingCount > 0) {
                $this->warn('⚠️  Tenant key already exists');
                $this->line('   Aborting setup to prevent duplicate keys');
                $this->newLine();
                $this->comment('To generate additional tenants, use: php artisan keys:generate-tenant');

                return Command::SUCCESS;
            }
        } catch (\Exception $e) {
            $this->error('❌ Failed to check existing tenant keys');
            $this->error('   Database error: '.$e->getMessage());
            $this->newLine();
            $this->comment('Ensure your database is running and configured correctly.');

            return Command::FAILURE;
        }

        // Step 2: Validate KEK file exists
        $kekPath = TenantKey::getKekPath();

        if (! file_exists($kekPath)) {
            $this->error('❌ KEK file not found at: '.$kekPath);
            $this->newLine();
            $this->comment('Generate KEK first with:');
            $this->line('   php artisan keys:generate-kek');

            return Command::FAILURE;
        }

        $this->line('<fg=green>✅</> Checking KEK file... <fg=green>Found</>');

        try {
            TenantKey::assertSecureKekPermissions($kekPath);
        } catch (\RuntimeException $e) {
            $this->error('❌ '.$e->getMessage());
            $this->line('   Run: chmod 600 '.$kekPath);
            $this->newLine();

            return Command::FAILURE;
        }

        try {
            TenantKey::assertReadableKekFile($kekPath);
        } catch (\RuntimeException $e) {
            $this->error('❌ '.$e->getMessage());
            $this->newLine();

            return Command::FAILURE;
        }

        try {
            // Step 3: Generate and wrap tenant keys
            $this->line('<fg=green>✅</> Generating and wrapping tenant keys...');

            $keys = TenantKey::generateEnvelopeKeys();

            $this->line('   <fg=green>Done</>');

            // Step 4: Store in database
            $this->line('<fg=green>✅</> Storing in database...');
            $tenantKey = TenantKey::create($keys);
            $this->line('   <fg=green>Done</>');

            $this->newLine();
            $this->info('Tenant key setup complete!');
            $this->line("   Tenant ID: {$tenantKey->id}");
            $this->line("   Key version: {$tenantKey->key_version}");
            $this->newLine();
            $this->comment('Verify setup with:');
            $this->line('   php artisan app:validate-setup');

            return Command::SUCCESS;
        } catch (\RuntimeException $e) {
            $this->newLine();
            $this->error('❌ Failed to generate tenant keys: '.$e->getMessage());

            return Command::FAILURE;
        } catch (\Exception $e) {
            $this->newLine();
            $this->error('❌ Failed to store tenant keys in database');
            $this->error('   Database error: '.$e->getMessage());

            return Command::FAILURE;
        }
    }
}

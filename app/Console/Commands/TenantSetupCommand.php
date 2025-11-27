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
    protected $description = 'Initialize tenant key setup for new deployments';

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

        // Security check: KEK permissions
        $perms = fileperms($kekPath) & 0777;
        if ($perms !== 0600) {
            $this->warn('⚠️  KEK file has insecure permissions: '.decoct($perms));
            $this->line('   Recommended: 0600 (owner read/write only)');
            $this->line('   Run: chmod 600 '.$kekPath);
            $this->newLine();
        }

        try {
            // Step 3: Generate and wrap tenant keys
            $this->line('<fg=green>✅</> Generating tenant keys... <fg=green>Done</>');

            $keys = TenantKey::generateEnvelopeKeys();

            $this->line('<fg=green>✅</> Wrapping with KEK... <fg=green>Done</>');

            // Step 4: Store in database
            $tenantKey = TenantKey::create($keys);

            $this->line('<fg=green>✅</> Storing in database... <fg=green>Done</>');

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

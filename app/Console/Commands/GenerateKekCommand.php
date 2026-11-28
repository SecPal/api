<?php

/*
 * SPDX-FileCopyrightText: 2026 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Console\Commands;

use App\Models\TenantKey;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Generate the root Key Encryption Key (KEK) used for tenant envelope keys.
 */
class GenerateKekCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'keys:generate-kek';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate synthetic Tenant KEK material for local development';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('Tenant KEK material must be provisioned by the external secret authority.');

            return Command::FAILURE;
        }

        $kekPath = TenantKey::getKekPath();

        if (file_exists($kekPath)) {
            $this->error('❌ KEK file already exists at: '.$kekPath);
            $this->comment('Refusing to overwrite an existing KEK automatically.');

            return Command::FAILURE;
        }

        try {
            TenantKey::generateKek();
            TenantKey::assertSecureKekPermissions($kekPath);
            TenantKey::assertReadableKekFile($kekPath);

            $this->info('✅ Generated KEK successfully');
            $this->line('   Path: '.$kekPath);
            $this->line('   Permissions: 0600');
            $this->comment('Set the synthetic key configuration before setup:');
            $this->line('   TENANT_KEK_ID='.(string) Str::uuid());
            $this->line('   TENANT_KEK_GENERATION=1');
            $this->line('   TENANT_KEK_FINGERPRINT='.hash_file('sha256', $kekPath));
            $this->comment('Next step:');
            $this->line('   php artisan tenant:setup');

            return Command::SUCCESS;
        } catch (\RuntimeException $e) {
            $this->error('❌ Failed to generate KEK: '.$e->getMessage());

            return Command::FAILURE;
        }
    }
}

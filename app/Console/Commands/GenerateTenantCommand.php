<?php

/*
 * SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution
 */

namespace App\Console\Commands;

use App\Models\TenantKey;
use Illuminate\Console\Command;

/**
 * Generate a new tenant with envelope keys.
 *
 * This command creates a new tenant entry in the tenant_keys table
 * with fresh DEK and idx_key wrapped by the current KEK.
 */
class GenerateTenantCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'keys:generate-tenant';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate a new tenant with envelope keys';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Generating new tenant envelope keys...');

        $kekPath = TenantKey::getKekPath();

        if (! file_exists($kekPath)) {
            $this->error('❌ KEK file not found at: '.$kekPath);
            $this->comment('Generate KEK first with:');
            $this->line('   php artisan keys:generate-kek');

            return Command::FAILURE;
        }

        try {
            $keys = TenantKey::generateEnvelopeKeys();
            $tenant = TenantKey::create($keys);

            $this->info("✅ Successfully created tenant {$tenant->id}");
            $this->line('   DEK wrapped: '.strlen($tenant->dek_wrapped).' bytes');
            $this->line('   idx_key wrapped: '.strlen($tenant->idx_wrapped).' bytes');
            $this->line("   Key version: {$tenant->key_version}");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('❌ Failed to generate tenant: '.$e->getMessage());

            return Command::FAILURE;
        }
    }
}

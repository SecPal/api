<?php

/*
 * SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Console\Commands;

use App\Services\TenantProvisioner;
use Illuminate\Console\Command;
use Throwable;

/**
 * Generate a new tenant with envelope keys.
 *
 * Creates a functional tenant and its envelope keys in one transaction.
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
    public function handle(TenantProvisioner $provisioner): int
    {
        $this->info('Generating new tenant envelope keys...');

        try {
            $key = $provisioner->create();

            $this->info("✅ Successfully created tenant {$key->tenant_id}");
            $this->line('   DEK wrapped: '.strlen($key->dek_wrapped).' bytes');
            $this->line('   idx_key wrapped: '.strlen($key->idx_wrapped).' bytes');
            $this->line("   Key version: {$key->key_version}");

            return Command::SUCCESS;
        } catch (Throwable) {
            $this->error('Failed to generate tenant. Verify the database and the externally provided Tenant KEK generation.');

            return Command::FAILURE;
        }
    }
}

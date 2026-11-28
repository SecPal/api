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

class TenantSetupCommand extends Command
{
    /** @var string */
    protected $signature = 'tenant:setup';

    /** @var string */
    protected $description = 'Set up the first tenant with externally provisioned KEK material';

    public function handle(TenantProvisioner $provisioner): int
    {
        $this->info('SecPal Tenant Key Setup');

        try {
            $tenantKey = $provisioner->setup();
            if ($tenantKey === null) {
                $this->info('Tenant key already exists.');

                return Command::SUCCESS;
            }

            $this->info('Tenant key setup complete!');
            $this->line("   Tenant ID: {$tenantKey->tenant_id}");
            $this->line("   Key version: {$tenantKey->key_version}");
            $this->comment('Verify setup with: php artisan app:validate-setup');

            return Command::SUCCESS;
        } catch (Throwable) {
            $this->error('Tenant setup failed. Verify the database and the externally provided Tenant KEK generation.');

            return Command::FAILURE;
        }
    }
}

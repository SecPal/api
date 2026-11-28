<?php

/*
 * SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Console\Commands;

use App\Services\TenantKekRegistry;
use Illuminate\Console\Command;
use Throwable;

class RotateKekCommand extends Command
{
    /** @var string */
    protected $signature = 'keys:rotate-kek {operation? : Externally approved rotation operation ID}';

    /** @var string */
    protected $description = 'Commit an authorized Tenant KEK rewrap';

    public function handle(TenantKekRegistry $registry): int
    {
        $operation = $this->argument('operation');
        if (! is_string($operation)) {
            $this->error('An explicitly approved Tenant KEK rotation operation is required.');

            return Command::FAILURE;
        }

        try {
            $this->info($registry->rotate($operation)
                ? 'Tenant KEK rewrap committed.'
                : 'Tenant KEK rewrap was already committed.');

            return Command::SUCCESS;
        } catch (Throwable) {
            $this->error('Tenant KEK rotation was not confirmed. Reconcile the same approved operation before retrying.');

            return Command::FAILURE;
        }
    }
}

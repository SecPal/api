<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\GlobalIdentityKeyException;
use App\Services\GlobalIdentity\GlobalIdentityKeyReference;
use App\Services\GlobalIdentity\GlobalIdentityWorkingKeyRegistry;
use Illuminate\Console\Command;

final class BootstrapGlobalIdentity extends Command
{
    protected $signature = 'identity:bootstrap {root-id} {root-version} {--force : Confirm explicit operational authorization}';

    protected $description = 'Initialize the Global Identity working-key registry using an externally provisioned root';

    public function handle(GlobalIdentityWorkingKeyRegistry $registry): int
    {
        $id = $this->argument('root-id');
        $version = filter_var($this->argument('root-version'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (! $this->option('force') || ! is_string($id) || $version === false) {
            $this->error('An exact root reference and explicit bootstrap authorization are required.');

            return self::FAILURE;
        }

        try {
            $registry->initialize(new GlobalIdentityKeyReference($id, $version));
        } catch (GlobalIdentityKeyException) {
            $this->error('Global Identity registry bootstrap failed.');

            return self::FAILURE;
        }

        $this->info('Global Identity working-key registry verified.');

        return self::SUCCESS;
    }
}

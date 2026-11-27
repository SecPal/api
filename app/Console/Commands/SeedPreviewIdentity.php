<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\GlobalIdentityKeyException;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\OnboardingFormTemplatesSeeder;
use Database\Seeders\OrganizationalUnitSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class SeedPreviewIdentity extends Command
{
    protected $signature = 'identity:seed-preview';

    protected $description = 'Seed the synthetic development login using a password supplied on standard input';

    public function handle(DatabaseSeeder $seeder): int
    {
        if (! app()->environment('local', 'testing', 'demo')) {
            $this->error('Synthetic identities require a development environment.');

            return self::FAILURE;
        }
        $password = fgets(STDIN, 1026);
        if (! is_string($password)) {
            return self::FAILURE;
        }
        $password = rtrim($password, "\r\n");
        try {
            if (strlen($password) < 8 || strlen($password) > 72 || str_contains($password, "\0")) {
                $this->error('Invalid synthetic password input.');

                return self::FAILURE;
            }
            DB::transaction(function () use ($seeder, $password): void {
                $seeder->call([
                    RolesAndPermissionsSeeder::class,
                    OrganizationalUnitSeeder::class,
                    OnboardingFormTemplatesSeeder::class,
                ]);
                $seeder->seedPreviewAccount($password);
            });
        } catch (GlobalIdentityKeyException) {
            $this->error('Synthetic identity fixture is unavailable.');

            return self::FAILURE;
        } finally {
            sodium_memzero($password);
        }

        $this->info('Synthetic identity fixture prepared.');

        return self::SUCCESS;
    }
}

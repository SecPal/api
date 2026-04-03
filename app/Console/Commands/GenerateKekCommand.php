<?php

/*
 * SPDX-FileCopyrightText: 2026 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Console\Commands;

use App\Models\TenantKey;
use Illuminate\Console\Command;

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
    protected $description = 'Generate the root KEK used to wrap tenant envelope keys';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $kekPath = TenantKey::getKekPath();

        if (file_exists($kekPath)) {
            $this->error('❌ KEK file already exists at: '.$kekPath);
            $this->comment('Refusing to overwrite an existing KEK automatically.');
            $this->line('   Use php artisan keys:rotate-kek to rotate the active KEK.');

            return Command::FAILURE;
        }

        try {
            TenantKey::generateKek();
            TenantKey::assertSecureKekPermissions($kekPath);
            TenantKey::assertReadableKekFile($kekPath);

            $this->info('✅ Generated KEK successfully');
            $this->line('   Path: '.$kekPath);
            $this->line('   Permissions: 0600');
            $this->comment('Next step:');
            $this->line('   php artisan tenant:setup');

            return Command::SUCCESS;
        } catch (\RuntimeException $e) {
            $this->error('❌ Failed to generate KEK: '.$e->getMessage());

            return Command::FAILURE;
        }
    }
}

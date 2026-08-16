<?php

/*
 * SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Console\Commands;

use App\Models\TenantKey;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Validates complete application setup before production deployment.
 *
 * Checks:
 * - Database connectivity
 * - Tenant key existence
 * - KEK file readability
 * - Storage directory writeability
 * - Required PHP extensions
 *
 * Exit codes:
 * - 0: All checks passed
 * - 1: One or more checks failed
 */
class ValidateSetupCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:validate-setup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Validates complete application setup for production deployment';

    /**
     * Required PHP extensions for SecPal operation.
     *
     * @var array<int, string>
     */
    private const REQUIRED_EXTENSIONS = [
        'sodium',
        'pgsql',
        'pdo',
        'mbstring',
        'json',
    ];

    /**
     * Execute the console command.
     *
     * @return int Command exit code (0 = success, 1 = failure)
     */
    public function handle(): int
    {
        $this->newLine();
        $this->line('<fg=cyan;options=bold>SecPal Setup Validation</>');
        $this->line('<fg=cyan;options=bold>=======================</>');
        $this->newLine();

        $checks = [
            'database' => $this->checkDatabase(),
            'tenant_keys' => $this->checkTenantKeys(),
            'kek_file' => $this->checkKekFile(),
            'storage' => $this->checkStorage(),
            'extensions' => $this->checkExtensions(),
        ];

        $allPassed = ! in_array(false, $checks, true);

        $this->newLine();

        if ($allPassed) {
            $this->line('<fg=green;options=bold>Setup validation PASSED</>');

            return Command::SUCCESS;
        }

        $this->line('<fg=red;options=bold>Setup validation FAILED</>');
        $this->newLine();
        $this->provideActionableHelp($checks);

        return Command::FAILURE;
    }

    /**
     * Check database connectivity.
     *
     * @return bool True if database connection works
     */
    private function checkDatabase(): bool
    {
        try {
            DB::connection()->getPdo();
            $this->line('✅ <fg=green>Database connection:</> OK');

            return true;
        } catch (\Throwable $e) {
            $this->line('❌ <fg=red>Database connection:</> Failed');
            $this->line('   <fg=yellow>Error:</> '.$e->getMessage());

            return false;
        }
    }

    /**
     * Check tenant key existence.
     *
     * @return bool True if at least one tenant key exists
     */
    private function checkTenantKeys(): bool
    {
        try {
            $count = TenantKey::count();

            if ($count > 0) {
                $keysLabel = $count === 1 ? 'key' : 'keys';
                $this->line("✅ <fg=green>Tenant keys:</> {$count} {$keysLabel} found");

                return true;
            }

            $this->line('❌ <fg=red>Tenant keys:</> 0 keys found');

            return false;
        } catch (\Throwable $e) {
            $this->line('❌ <fg=red>Tenant keys:</> Database query failed');
            $this->line('   <fg=yellow>Error:</> '.$e->getMessage());

            return false;
        }
    }

    /**
     * Check KEK file existence, readability, and permissions.
     *
     * @return bool True if KEK file exists and is readable
     */
    private function checkKekFile(): bool
    {
        $kekPath = TenantKey::getKekPath();

        if (! File::exists($kekPath)) {
            $this->line('❌ <fg=red>KEK file:</> Not found at '.$kekPath);

            return false;
        }

        if (! File::isReadable($kekPath)) {
            $this->line('❌ <fg=red>KEK file:</> Not readable at '.$kekPath);

            return false;
        }

        try {
            TenantKey::assertSecureKekPermissions($kekPath);
        } catch (\RuntimeException $e) {
            $this->line('❌ <fg=red>KEK file:</> '.$e->getMessage());

            return false;
        }

        $this->line('✅ <fg=green>KEK file:</> OK');

        return true;
    }

    /**
     * Check storage directory writeability.
     *
     * @return bool True if storage directories are writable
     */
    private function checkStorage(): bool
    {
        $storagePath = storage_path();

        if (! File::isWritable($storagePath)) {
            $this->line('❌ <fg=red>Storage writable:</> Failed ('.$storagePath.')');

            return false;
        }

        $this->line('✅ <fg=green>Storage writable:</> OK');

        return true;
    }

    /**
     * Check required PHP extensions are loaded.
     *
     * @return bool True if all required extensions are loaded
     */
    private function checkExtensions(): bool
    {
        $missing = [];

        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            if (! extension_loaded($extension)) {
                $missing[] = $extension;
            }
        }

        if (count($missing) !== 0) {
            $this->line('❌ <fg=red>PHP extensions:</> Missing: '.implode(', ', $missing));

            return false;
        }

        $this->line('✅ <fg=green>PHP extensions:</> OK');

        return true;
    }

    /**
     * Provide actionable help based on failed checks.
     *
     * @param  array<string, bool>  $checks  Check results
     */
    private function provideActionableHelp(array $checks): void
    {
        if (! $checks['database']) {
            $this->line('<fg=yellow>Check database connection in .env file</>');
            $this->line('<fg=yellow>Run:</> php artisan migrate');
        }

        if (! $checks['tenant_keys']) {
            $this->line('<fg=yellow>Run:</> php artisan keys:generate-tenant');
        }

        if (! $checks['kek_file']) {
            $kekPath = TenantKey::getKekPath();
            $this->line('<fg=yellow>KEK file missing or unreadable at:</> '.$kekPath);
            $this->line('<fg=yellow>Ensure KEK file exists with proper permissions (0600)</>');
        }

        if (! $checks['storage']) {
            $this->line('<fg=yellow>Ensure storage directories are writable:</>');
            $this->line('<fg=yellow>chmod -R 755 storage bootstrap/cache</>');
        }

        if (! $checks['extensions']) {
            $this->line('<fg=yellow>Install missing PHP extensions</>');
        }
    }
}

<?php

/*
 * SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution
 */

use App\Models\TenantKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

describe('app:validate-setup Command', function () {
    beforeEach(function () {
        // Use process-specific KEK file for parallel test isolation
        incrementTestKekCounter();
        TenantKey::setKekPath(getTestKekPath());
    });

    afterEach(function () {
        // Cleanup test KEK file
        cleanupTestKekFile();
        TenantKey::setKekPath(null);
    });

    it('exists and is executable', function () {
        $result = Artisan::call('app:validate-setup');

        // Command should execute (may fail checks, but command exists)
        expect($result)->toBeIn([0, 1]);
    });

    it('returns exit code 0 when all checks pass', function () {
        // Setup: Create tenant key in database
        DB::table('tenant_keys')->insert([
            'dek_wrapped' => base64_encode(random_bytes(32)),
            'dek_nonce' => base64_encode(random_bytes(24)),
            'idx_wrapped' => base64_encode(random_bytes(32)),
            'idx_nonce' => base64_encode(random_bytes(24)),
            'created_at' => now(),
            'key_version' => 1,
        ]);

        // Setup: Create KEK file
        TenantKey::generateKek();

        // Act
        $result = Artisan::call('app:validate-setup');

        // Assert: All checks passed
        expect($result)->toBe(0);

        // Verify output contains success indicators
        $output = Artisan::output();
        expect($output)->toContain('✅');
        expect($output)->not->toContain('❌');
    });

    it('returns exit code 1 when any check fails', function () {
        // Setup: No tenant key (missing configuration)
        // No KEK file created

        // Act
        $result = Artisan::call('app:validate-setup');

        // Assert: Command failed
        expect($result)->toBe(1);

        // Verify output contains failure indicators
        $output = Artisan::output();
        expect($output)->toContain('❌');
    });

    it('checks database connection successfully', function () {
        // Setup: Create KEK and tenant key
        TenantKey::generateKek();
        DB::table('tenant_keys')->insert([
            'dek_wrapped' => base64_encode(random_bytes(32)),
            'dek_nonce' => base64_encode(random_bytes(24)),
            'idx_wrapped' => base64_encode(random_bytes(32)),
            'idx_nonce' => base64_encode(random_bytes(24)),
            'created_at' => now(),
            'key_version' => 1,
        ]);

        // Act
        Artisan::call('app:validate-setup');
        $output = Artisan::output();

        // Assert: Database check passed
        expect($output)->toContain('Database connection');
        expect($output)->toContain('✅');
    });

    it('detects missing tenant keys', function () {
        // Setup: Create KEK but no tenant key
        TenantKey::generateKek();

        // Act
        Artisan::call('app:validate-setup');
        $output = Artisan::output();

        // Assert: Tenant key check failed
        expect($output)->toContain('Tenant keys');
        expect($output)->toContain('❌');
        expect($output)->toMatch('/0 key(s)? found/i');
    });

    it('detects missing KEK file', function () {
        // Setup: Create tenant key but no KEK
        DB::table('tenant_keys')->insert([
            'dek_wrapped' => base64_encode(random_bytes(32)),
            'dek_nonce' => base64_encode(random_bytes(24)),
            'idx_wrapped' => base64_encode(random_bytes(32)),
            'idx_nonce' => base64_encode(random_bytes(24)),
            'created_at' => now(),
            'key_version' => 1,
        ]);

        // Act
        Artisan::call('app:validate-setup');
        $output = Artisan::output();

        // Assert: KEK file check failed
        expect($output)->toContain('KEK file');
        expect($output)->toContain('❌');
        expect($output)->toContain('Not found');
    });

    it('detects unreadable KEK file', function () {
        // Setup: Create tenant key and KEK file
        DB::table('tenant_keys')->insert([
            'dek_wrapped' => base64_encode(random_bytes(32)),
            'dek_nonce' => base64_encode(random_bytes(24)),
            'idx_wrapped' => base64_encode(random_bytes(32)),
            'idx_nonce' => base64_encode(random_bytes(24)),
            'created_at' => now(),
            'key_version' => 1,
        ]);

        TenantKey::generateKek();
        $kekPath = TenantKey::getKekPath();

        // Make file unreadable (skip on Windows)
        if (DIRECTORY_SEPARATOR !== '\\') {
            chmod($kekPath, 0000);

            // Act
            $result = Artisan::call('app:validate-setup');
            $output = Artisan::output();

            // Restore permissions for cleanup
            chmod($kekPath, 0600);

            // Assert: KEK file check failed
            expect($result)->toBe(1);
            expect($output)->toContain('KEK file');
            expect($output)->toContain('❌');
        } else {
            // On Windows, skip this test
            expect(true)->toBeTrue();
        }
    });

    it('detects insecure KEK file permissions', function () {
        DB::table('tenant_keys')->insert([
            'dek_wrapped' => base64_encode(random_bytes(32)),
            'dek_nonce' => base64_encode(random_bytes(24)),
            'idx_wrapped' => base64_encode(random_bytes(32)),
            'idx_nonce' => base64_encode(random_bytes(24)),
            'created_at' => now(),
            'key_version' => 1,
        ]);

        TenantKey::generateKek();
        chmod(TenantKey::getKekPath(), 0644);

        $result = Artisan::call('app:validate-setup');
        $output = Artisan::output();

        expect($result)->toBe(1);
        expect($output)->toContain('KEK file');
        expect($output)->toContain('insecure permissions');
        expect($output)->toContain('expected 0600');
    });

    it('checks storage directories are writable', function () {
        // Setup: Create KEK and tenant key
        TenantKey::generateKek();
        DB::table('tenant_keys')->insert([
            'dek_wrapped' => base64_encode(random_bytes(32)),
            'dek_nonce' => base64_encode(random_bytes(24)),
            'idx_wrapped' => base64_encode(random_bytes(32)),
            'idx_nonce' => base64_encode(random_bytes(24)),
            'created_at' => now(),
            'key_version' => 1,
        ]);

        // Act
        Artisan::call('app:validate-setup');
        $output = Artisan::output();

        // Assert: Storage check passed
        expect($output)->toContain('Storage writable');
        expect($output)->toContain('✅');
    });

    it('checks required PHP extensions are loaded', function () {
        // Setup: Create KEK and tenant key
        TenantKey::generateKek();
        DB::table('tenant_keys')->insert([
            'dek_wrapped' => base64_encode(random_bytes(32)),
            'dek_nonce' => base64_encode(random_bytes(24)),
            'idx_wrapped' => base64_encode(random_bytes(32)),
            'idx_nonce' => base64_encode(random_bytes(24)),
            'created_at' => now(),
            'key_version' => 1,
        ]);

        // Act
        Artisan::call('app:validate-setup');
        $output = Artisan::output();

        // Assert: PHP extensions check passed
        expect($output)->toContain('PHP extensions');
        expect($output)->toContain('✅');
    });

    it('displays colored output with emojis', function () {
        // Setup: Partially valid configuration (missing KEK)
        DB::table('tenant_keys')->insert([
            'dek_wrapped' => base64_encode(random_bytes(32)),
            'dek_nonce' => base64_encode(random_bytes(24)),
            'idx_wrapped' => base64_encode(random_bytes(32)),
            'idx_nonce' => base64_encode(random_bytes(24)),
            'created_at' => now(),
            'key_version' => 1,
        ]);

        // Act
        Artisan::call('app:validate-setup');
        $output = Artisan::output();

        // Assert: Output contains both success and failure indicators
        expect($output)->toContain('SecPal Setup Validation');
        expect($output)->toMatch('/[✅❌]/u'); // Unicode emoji present
    });

    it('provides actionable error messages', function () {
        // Setup: No tenant key or KEK
        // Act
        Artisan::call('app:validate-setup');
        $output = Artisan::output();

        // Assert: Output contains helpful commands
        expect($output)->toContain('Run:');
        expect($output)->toMatch('/(php artisan|artisan)/i');
    });

    it('shows summary status line', function () {
        // Setup: Invalid configuration
        // Act
        Artisan::call('app:validate-setup');
        $output = Artisan::output();

        // Assert: Summary shows overall result
        expect($output)->toMatch('/Setup validation (FAILED|PASSED)/i');
    });

    it('counts and displays number of tenant keys found', function () {
        // Setup: Create multiple tenant keys
        for ($i = 0; $i < 3; $i++) {
            DB::table('tenant_keys')->insert([
                'dek_wrapped' => base64_encode(random_bytes(32)),
                'dek_nonce' => base64_encode(random_bytes(24)),
                'idx_wrapped' => base64_encode(random_bytes(32)),
                'idx_nonce' => base64_encode(random_bytes(24)),
                'created_at' => now(),
                'key_version' => 1,
            ]);
        }

        TenantKey::generateKek();

        // Act
        Artisan::call('app:validate-setup');
        $output = Artisan::output();

        // Assert: Shows correct count
        expect($output)->toContain('3 key');
    });

    it('verifies all required extensions (sodium, pgsql)', function () {
        // Setup: Full configuration
        TenantKey::generateKek();
        DB::table('tenant_keys')->insert([
            'dek_wrapped' => base64_encode(random_bytes(32)),
            'dek_nonce' => base64_encode(random_bytes(24)),
            'idx_wrapped' => base64_encode(random_bytes(32)),
            'idx_nonce' => base64_encode(random_bytes(24)),
            'created_at' => now(),
            'key_version' => 1,
        ]);

        // Act
        Artisan::call('app:validate-setup');
        $output = Artisan::output();

        // Assert: Critical extensions checked
        if (extension_loaded('sodium') && extension_loaded('pgsql')) {
            expect($output)->toContain('PHP extensions');
            expect($output)->toContain('✅');
        }
    });
});

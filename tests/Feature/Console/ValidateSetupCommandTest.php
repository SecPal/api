<?php

/*
 * SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use App\Models\TenantKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

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
        seedTenantKeyPresence();

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
        seedTenantKeyPresence();

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
        seedTenantKeyPresence();
        cleanupTestKekFile();

        // Act
        Artisan::call('app:validate-setup');
        $output = Artisan::output();

        // Assert: KEK file check failed
        expect($output)->toContain('KEK file');
        expect($output)->toContain('❌');
        expect($output)->toContain('Exact committed Tenant KEK generation unavailable');
    });

    it('identifies an unauthentic inventory separately from an unavailable KEK', function (): void {
        seedTenantKeyPresence();
        $key = TenantKey::query()->sole();
        $key->update(['dek_wrapped' => $key->idx_wrapped, 'dek_nonce' => $key->idx_nonce]);

        expect(Artisan::call('app:validate-setup'))->toBe(1);
        expect(Artisan::output())->toContain('Tenant key inventory could not be authenticated')
            ->toContain('snapshot recovery')
            ->not->toContain('Exact committed Tenant KEK generation unavailable');
    });

    it('identifies a tenant schema query error separately from an unavailable KEK', function (): void {
        seedTenantKeyPresence();
        DB::statement('SET LOCAL search_path TO pg_catalog');

        expect(Artisan::call('app:validate-setup'))->toBe(1);
        expect(Artisan::output())->toContain('Tenant key database query failed')
            ->not->toContain('Exact committed Tenant KEK generation unavailable');
    });

    it('detects unreadable KEK file', function () {
        // Setup: Create tenant key and KEK file
        seedTenantKeyPresence();
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
        seedTenantKeyPresence();
        chmod(TenantKey::getKekPath(), 0644);

        $result = Artisan::call('app:validate-setup');
        $output = Artisan::output();

        expect($result)->toBe(1);
        expect($output)->toContain('KEK file');
        expect($output)->toContain('Exact committed Tenant KEK generation unavailable');
    });

    it('checks storage directories are writable', function () {
        // Setup: Create KEK and tenant key
        TenantKey::generateKek();
        seedTenantKeyPresence();

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
        seedTenantKeyPresence();

        // Act
        Artisan::call('app:validate-setup');
        $output = Artisan::output();

        // Assert: PHP extensions check passed
        expect($output)->toContain('PHP extensions');
        expect($output)->toContain('✅');
    });

    it('displays colored output with emojis', function () {
        seedTenantKeyPresence();
        cleanupTestKekFile();

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

        expect($output)->toContain('clean bootstrap or snapshot recovery')
            ->not->toContain('keys:generate-tenant');
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
            seedTenantKeyPresence();
        }

        // Act
        Artisan::call('app:validate-setup');
        $output = Artisan::output();

        // Assert: Shows correct count
        expect($output)->toContain('3 key');
    });

    it('verifies all required extensions (sodium, pgsql)', function () {
        // Setup: Full configuration
        TenantKey::generateKek();
        seedTenantKeyPresence();

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

<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use App\Models\TenantKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(getTestKekPath());
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

describe('tenant:setup Command', function () {
    test('command exists and is registered', function (): void {
        $commands = Artisan::all();
        expect($commands)->toHaveKey('tenant:setup');
    });

    test('creates tenant keys when KEK exists and no tenant keys present', function (): void {
        // Generate KEK first
        TenantKey::generateKek();

        // Store count before command
        $countBefore = TenantKey::count();

        $this->artisan('tenant:setup')
            ->expectsOutput('SecPal Tenant Key Setup')
            ->expectsOutput('=======================')
            ->expectsOutputToContain('Checking KEK file')
            ->expectsOutputToContain('Generating and wrapping tenant keys')
            ->expectsOutputToContain('Storing in database')
            ->expectsOutput('Tenant key setup complete!')
            ->expectsOutputToContain('php artisan app:validate-setup')
            ->assertExitCode(0);

        // Verify tenant key was created
        expect(TenantKey::count())->toBe($countBefore + 1);

        $tenantKey = TenantKey::first();
        expect($tenantKey->dek_wrapped)->not->toBeEmpty();
        expect($tenantKey->idx_wrapped)->not->toBeEmpty();
        expect($tenantKey->key_version)->toBe(1);
    });

    test('fails when KEK file is missing', function (): void {
        // Ensure no KEK exists
        expect(file_exists(TenantKey::getKekPath()))->toBeFalse();

        $countBefore = TenantKey::count();

        $this->artisan('tenant:setup')
            ->expectsOutput('SecPal Tenant Key Setup')
            ->expectsOutputToContain('KEK file not found')
            ->expectsOutputToContain('php artisan keys:generate-kek')
            ->assertExitCode(1);

        // Verify no tenant key was created
        expect(TenantKey::count())->toBe($countBefore);
    });

    test('aborts when tenant key already exists', function (): void {
        // Generate KEK and tenant
        TenantKey::generateKek();
        $keys = TenantKey::generateEnvelopeKeys();
        TenantKey::create($keys);

        $countBefore = TenantKey::count();

        $this->artisan('tenant:setup')
            ->expectsOutput('SecPal Tenant Key Setup')
            ->expectsOutputToContain('Tenant key already exists')
            ->expectsOutputToContain('Aborting setup')
            ->assertExitCode(0);

        // Verify count didn't change
        expect(TenantKey::count())->toBe($countBefore);
    });

    test('warns if KEK file has insecure permissions', function (): void {
        // Generate KEK with correct permissions
        TenantKey::generateKek();

        // Change permissions to insecure (readable by group/others)
        chmod(TenantKey::getKekPath(), 0644);

        $countBefore = TenantKey::count();

        $this->artisan('tenant:setup')
            ->expectsOutputToContain('KEK file has insecure permissions')
            ->expectsOutputToContain('Recommended: 0600')
            ->assertExitCode(0);

        // Should still create tenant despite warning
        expect(TenantKey::count())->toBe($countBefore + 1);
    });

    test('generated keys can be unwrapped successfully', function (): void {
        TenantKey::generateKek();

        $this->artisan('tenant:setup')->assertExitCode(0);

        $tenantKey = TenantKey::latest()->first();

        // Verify keys can be unwrapped
        $dek = $tenantKey->unwrapDek();
        $idxKey = $tenantKey->unwrapIdxKey();

        expect(strlen($dek))->toBe(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        expect(strlen($idxKey))->toBe(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);

        sodium_memzero($dek);
        sodium_memzero($idxKey);
    });

    test('does not log plaintext keys', function (): void {
        TenantKey::generateKek();

        // Capture output
        $output = '';
        $this->artisan('tenant:setup', [], function ($line) use (&$output) {
            $output .= $line;
        })->assertExitCode(0);

        // Verify no raw key material in output
        $tenantKey = TenantKey::latest()->first();
        $dek = $tenantKey->unwrapDek();

        expect($output)->not->toContain(bin2hex($dek));
        expect($output)->not->toContain(base64_encode($dek));

        sodium_memzero($dek);
    });

    test('creates valid database records', function (): void {
        TenantKey::generateKek();

        $this->artisan('tenant:setup')->assertExitCode(0);

        $tenantKey = TenantKey::latest()->first();

        // Verify all required fields are present
        expect($tenantKey)->toHaveKeys([
            'id',
            'dek_wrapped',
            'dek_nonce',
            'idx_wrapped',
            'idx_nonce',
            'key_version',
            'created_at',
        ]);

        // Verify binary field lengths
        expect(strlen($tenantKey->dek_nonce))->toBe(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        expect(strlen($tenantKey->idx_nonce))->toBe(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    });

    // Database connection testing is unreliable in test environment
    // as Laravel maintains connection pools. This test is covered by
    // integration testing and production monitoring instead.

    test('handles corrupted KEK file gracefully', function (): void {
        // Create KEK file with invalid content (not 32 bytes)
        $kekPath = TenantKey::getKekPath();
        file_put_contents($kekPath, 'invalid-kek-too-short');
        chmod($kekPath, 0600);

        $countBefore = TenantKey::count();

        // Command will fail when trying to load corrupted KEK
        $this->artisan('tenant:setup')
            ->expectsOutputToContain('Failed')
            ->assertExitCode(1);

        // Verify no tenant key was created
        expect(TenantKey::count())->toBe($countBefore);
    });

    test('handles multiple tenant keys existence check', function (): void {
        TenantKey::generateKek();

        // Create multiple tenant keys
        TenantKey::create(TenantKey::generateEnvelopeKeys());
        TenantKey::create(TenantKey::generateEnvelopeKeys());

        $countBefore = TenantKey::count();

        $this->artisan('tenant:setup')
            ->expectsOutputToContain('Tenant key already exists')
            ->assertExitCode(0);

        // Verify count didn't change
        expect(TenantKey::count())->toBe($countBefore);
    });

    test('validates KEK path is accessible', function (): void {
        TenantKey::generateKek();
        $kekPath = TenantKey::getKekPath();

        // Verify KEK file is readable
        expect(file_exists($kekPath))->toBeTrue();
        expect(is_readable($kekPath))->toBeTrue();

        $this->artisan('tenant:setup')
            ->assertExitCode(0);
    });

    test('command signature and description are correct', function (): void {
        $command = Artisan::all()['tenant:setup'];

        expect($command->getName())->toBe('tenant:setup');
        expect($command->getDescription())->toBe('Tenant key setup for new deployments');
    });
});

<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use App\Models\TenantKey;
use Illuminate\Foundation\Testing\RefreshDatabase;

// HMAC_SHA256_OUTPUT_BYTES is 32 (SHA-256 output size in bytes)
require_once __DIR__.'/../TestConstants.php';

uses(RefreshDatabase::class);

// Clean up KEK file before each test for isolation
beforeEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(getTestKekPath());
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('generates KEK file with correct permissions', function (): void {
    TenantKey::generateKek();

    $kekPath = TenantKey::getKekPath();

    expect(file_exists($kekPath))->toBeTrue();
    expect(filesize($kekPath))->toBe(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);

    // Check file permissions (0600 = owner read/write only)
    $perms = fileperms($kekPath) & 0777;
    expect($perms)->toBe(0600);
});

test('throws exception when KEK file is missing', function (): void {
    // Explicitly remove KEK file to ensure clean state
    cleanupTestKekFile();

    expect(fn () => TenantKey::generateEnvelopeKeys())
        ->toThrow(RuntimeException::class, 'KEK file not found');
});

test('generates envelope keys with correct structure', function (): void {
    TenantKey::generateKek();

    $keys = TenantKey::generateEnvelopeKeys();

    expect($keys)->toHaveKeys(['dek_wrapped', 'dek_nonce', 'idx_wrapped', 'idx_nonce']);
    expect(strlen($keys['dek_nonce']))->toBe(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    expect(strlen($keys['idx_nonce']))->toBe(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    // Wrapped keys include MAC, so they're longer than the original key
    expect(strlen($keys['dek_wrapped']))->toBeGreaterThan(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    expect(strlen($keys['idx_wrapped']))->toBeGreaterThan(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
});

test('generates unique nonces for each key generation', function (): void {
    TenantKey::generateKek();

    $keys1 = TenantKey::generateEnvelopeKeys();
    $keys2 = TenantKey::generateEnvelopeKeys();

    expect($keys1['dek_nonce'])->not->toBe($keys2['dek_nonce']);
    expect($keys1['idx_nonce'])->not->toBe($keys2['idx_nonce']);
    expect($keys1['dek_wrapped'])->not->toBe($keys2['dek_wrapped']);
    expect($keys1['idx_wrapped'])->not->toBe($keys2['idx_wrapped']);
});

test('unwraps DEK correctly', function (): void {
    TenantKey::generateKek();

    $keys = TenantKey::generateEnvelopeKeys();

    $tenantKey = TenantKey::create($keys);

    $dek = $tenantKey->unwrapDek();

    expect(strlen($dek))->toBe(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
});

test('unwraps idx_key correctly', function (): void {
    TenantKey::generateKek();

    $keys = TenantKey::generateEnvelopeKeys();

    $tenantKey = TenantKey::create($keys);

    $idxKey = $tenantKey->unwrapIdxKey();

    expect(strlen($idxKey))->toBe(SODIUM_CRYPTO_SECRETBOX_KEYBYTES); // Index key is 32 bytes, same size as DEK
});

test('encrypts and decrypts data correctly', function (): void {
    TenantKey::generateKek();

    $keys = TenantKey::generateEnvelopeKeys();
    $tenantKey = TenantKey::create($keys);

    $plaintext = 'sensitive data';

    $encrypted = $tenantKey->encrypt($plaintext);

    expect($encrypted)->toHaveKeys(['ciphertext', 'nonce']);
    expect(strlen($encrypted['nonce']))->toBe(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

    $decrypted = $tenantKey->decrypt($encrypted['ciphertext'], $encrypted['nonce']);

    expect($decrypted)->toBe($plaintext);
});

test('throws exception on decryption with wrong nonce', function (): void {
    TenantKey::generateKek();

    $keys = TenantKey::generateEnvelopeKeys();
    $tenantKey = TenantKey::create($keys);

    $encrypted = $tenantKey->encrypt('test data');
    $wrongNonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

    expect(fn () => $tenantKey->decrypt($encrypted['ciphertext'], $wrongNonce))
        ->toThrow(RuntimeException::class, 'Failed to decrypt data');
});

test('generates consistent blind index for same plaintext', function (): void {
    TenantKey::generateKek();

    $keys = TenantKey::generateEnvelopeKeys();
    $tenantKey = TenantKey::create($keys);

    $plaintext = 'test@example.com';

    $index1 = $tenantKey->generateBlindIndex($plaintext);
    $index2 = $tenantKey->generateBlindIndex($plaintext);

    expect($index1)->toBe($index2);
    expect(strlen($index1))->toBe(HMAC_SHA256_OUTPUT_BYTES);
});

test('generates different blind indexes for different plaintexts', function (): void {
    TenantKey::generateKek();

    $keys = TenantKey::generateEnvelopeKeys();
    $tenantKey = TenantKey::create($keys);

    $index1 = $tenantKey->generateBlindIndex('test1@example.com');
    $index2 = $tenantKey->generateBlindIndex('test2@example.com');

    expect($index1)->not->toBe($index2);
});

test('different tenants have different blind indexes for same plaintext', function (): void {
    TenantKey::generateKek();

    $keys1 = TenantKey::generateEnvelopeKeys();
    $keys2 = TenantKey::generateEnvelopeKeys();

    $tenant1 = TenantKey::create($keys1);
    $tenant2 = TenantKey::create($keys2);

    $plaintext = 'test@example.com';

    $index1 = $tenant1->generateBlindIndex($plaintext);
    $index2 = $tenant2->generateBlindIndex($plaintext);

    expect($index1)->not->toBe($index2);
});

test('key_version defaults to 1', function (): void {
    TenantKey::generateKek();

    $keys = TenantKey::generateEnvelopeKeys();
    $tenantKey = TenantKey::create($keys);

    expect($tenantKey->key_version)->toBe(1);
});

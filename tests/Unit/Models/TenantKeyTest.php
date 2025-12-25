<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\TenantKey;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantKey::setKekPath(getTestKekPath());
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('tenant key can be created with factory', function (): void {
    $tenantKey = TenantKey::factory()->create();

    expect($tenantKey->id)->not->toBeNull()
        ->and($tenantKey->dek_wrapped)->not->toBeNull()
        ->and($tenantKey->dek_nonce)->not->toBeNull()
        ->and($tenantKey->idx_wrapped)->not->toBeNull()
        ->and($tenantKey->idx_nonce)->not->toBeNull()
        ->and($tenantKey->key_version)->toBe(1)
        ->and($tenantKey->created_at)->not->toBeNull();
});

test('tenant key factory generates valid envelope keys', function (): void {
    $tenantKey = TenantKey::factory()->create();

    // Verify that keys can be unwrapped successfully
    $dek = $tenantKey->unwrapDek();
    expect(strlen($dek))->toBe(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    sodium_memzero($dek);

    $idxKey = $tenantKey->unwrapIdxKey();
    expect(strlen($idxKey))->toBe(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    sodium_memzero($idxKey);
});

test('tenant key factory can create with specific version', function (): void {
    $tenantKey = TenantKey::factory()->version(5)->create();

    expect($tenantKey->key_version)->toBe(5);
});

test('tenant key factory creates unique keys for each instance', function (): void {
    $tenantKey1 = TenantKey::factory()->create();
    $tenantKey2 = TenantKey::factory()->create();

    expect($tenantKey1->dek_wrapped)->not->toEqual($tenantKey2->dek_wrapped)
        ->and($tenantKey1->dek_nonce)->not->toEqual($tenantKey2->dek_nonce)
        ->and($tenantKey1->idx_wrapped)->not->toEqual($tenantKey2->idx_wrapped)
        ->and($tenantKey1->idx_nonce)->not->toEqual($tenantKey2->idx_nonce);
});

test('tenant key factory keys are functional for encryption', function (): void {
    $tenantKey = TenantKey::factory()->create();

    $plaintext = 'Sensitive Data';
    $encrypted = $tenantKey->encrypt($plaintext);

    expect($encrypted)->toHaveKey('ciphertext')
        ->toHaveKey('nonce')
        ->and($encrypted['ciphertext'])->not->toEqual($plaintext);

    $decrypted = $tenantKey->decrypt($encrypted['ciphertext'], $encrypted['nonce']);
    expect($decrypted)->toBe($plaintext);
});

test('tenant key factory keys are functional for blind index', function (): void {
    $tenantKey = TenantKey::factory()->create();

    $plaintext = 'searchable-value';
    $index = $tenantKey->generateBlindIndex($plaintext);

    expect($index)->not->toBeNull()
        ->and(strlen($index))->toBe(32); // HMAC-SHA256 produces 32 bytes

    // Same plaintext should produce same index
    $index2 = $tenantKey->generateBlindIndex($plaintext);
    expect($index)->toBe($index2);

    // Different plaintext should produce different index
    $index3 = $tenantKey->generateBlindIndex('different-value');
    expect($index)->not->toBe($index3);
});

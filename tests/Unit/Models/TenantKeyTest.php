<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Unit\Models;

use App\Models\TenantKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantKeyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        TenantKey::setKekPath(getTestKekPath());
    }

    protected function tearDown(): void
    {
        cleanupTestKekFile();
        TenantKey::setKekPath(null);
        parent::tearDown();
    }

    public function test_tenant_key_can_be_created_with_factory(): void
    {
        $tenantKey = TenantKey::factory()->create();

        $this->assertNotNull($tenantKey->id);
        $this->assertNotNull($tenantKey->dek_wrapped);
        $this->assertNotNull($tenantKey->dek_nonce);
        $this->assertNotNull($tenantKey->idx_wrapped);
        $this->assertNotNull($tenantKey->idx_nonce);
        $this->assertSame(1, $tenantKey->key_version);
        $this->assertNotNull($tenantKey->created_at);
    }

    public function test_tenant_key_factory_generates_valid_envelope_keys(): void
    {
        $tenantKey = TenantKey::factory()->create();

        // Verify that keys can be unwrapped successfully
        $dek = $tenantKey->unwrapDek();
        $this->assertSame(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, strlen($dek));
        sodium_memzero($dek);

        $idxKey = $tenantKey->unwrapIdxKey();
        $this->assertSame(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, strlen($idxKey));
        sodium_memzero($idxKey);
    }

    public function test_tenant_key_factory_can_create_with_specific_version(): void
    {
        $tenantKey = TenantKey::factory()->version(5)->create();

        $this->assertSame(5, $tenantKey->key_version);
    }

    public function test_tenant_key_factory_creates_unique_keys_for_each_instance(): void
    {
        $tenantKey1 = TenantKey::factory()->create();
        $tenantKey2 = TenantKey::factory()->create();

        $this->assertNotEquals($tenantKey1->dek_wrapped, $tenantKey2->dek_wrapped);
        $this->assertNotEquals($tenantKey1->dek_nonce, $tenantKey2->dek_nonce);
        $this->assertNotEquals($tenantKey1->idx_wrapped, $tenantKey2->idx_wrapped);
        $this->assertNotEquals($tenantKey1->idx_nonce, $tenantKey2->idx_nonce);
    }

    public function test_tenant_key_factory_keys_are_functional_for_encryption(): void
    {
        $tenantKey = TenantKey::factory()->create();

        $plaintext = 'Sensitive Data';
        $encrypted = $tenantKey->encrypt($plaintext);

        $this->assertArrayHasKey('ciphertext', $encrypted);
        $this->assertArrayHasKey('nonce', $encrypted);
        $this->assertNotEquals($plaintext, $encrypted['ciphertext']);

        $decrypted = $tenantKey->decrypt($encrypted['ciphertext'], $encrypted['nonce']);
        $this->assertSame($plaintext, $decrypted);
    }

    public function test_tenant_key_factory_keys_are_functional_for_blind_index(): void
    {
        $tenantKey = TenantKey::factory()->create();

        $plaintext = 'searchable-value';
        $index = $tenantKey->generateBlindIndex($plaintext);

        $this->assertNotNull($index);
        $this->assertSame(32, strlen($index)); // HMAC-SHA256 produces 32 bytes

        // Same plaintext should produce same index
        $index2 = $tenantKey->generateBlindIndex($plaintext);
        $this->assertSame($index, $index2);

        // Different plaintext should produce different index
        $index3 = $tenantKey->generateBlindIndex('different-value');
        $this->assertNotSame($index, $index3);
    }
}

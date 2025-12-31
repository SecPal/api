<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use App\Models\Person;
use App\Models\TenantKey;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @property \App\Models\TenantKey $tenant
 * @property string $originalDekWrapped
 * @property \App\Models\Person $person
 */
beforeEach(function (): void {
    // Use process-specific KEK file for parallel test isolation
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();

    // Create a tenant with envelope keys
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);
    $this->originalDekWrapped = $this->tenant->dek_wrapped; // Store for rotation tests

    // Create a person with encrypted data for rotation testing
    $this->person = new Person;
    $this->person->tenant_id = $this->tenant->id;
    $this->person->email_plain = 'test@example.com';
    $this->person->phone_plain = '+49 123 456789';
    $this->person->note_enc = 'Test note'; // Uses DEK encryption via cast
    $this->person->save();
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

describe('keys:generate-tenant Command', function () {
    test('generates new tenant with envelope keys', function (): void {
        $countBefore = TenantKey::count();

        $this->artisan('keys:generate-tenant')
            ->expectsOutput('Generating new tenant envelope keys...')
            ->assertExitCode(0);

        // Should have created a new tenant
        expect(TenantKey::count())->toBe($countBefore + 1);

        $newTenant = TenantKey::latest()->first();
        expect($newTenant->dek_wrapped)->not->toBeEmpty();
        expect($newTenant->idx_wrapped)->not->toBeEmpty();
        expect($newTenant->key_version)->toBe(1);
    });

    test('unwraps keys correctly for new tenant', function (): void {
        $this->artisan('keys:generate-tenant')->assertExitCode(0);

        $newTenant = TenantKey::latest()->first();
        $dek = $newTenant->unwrapDek();
        $idxKey = $newTenant->unwrapIdxKey();

        expect(strlen($dek))->toBe(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        expect(strlen($idxKey))->toBe(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);

        sodium_memzero($dek);
        sodium_memzero($idxKey);
    });
});

describe('keys:rotate-kek Command', function () {
    test('rotates KEK and re-wraps all tenant keys', function (): void {
        // Store old wrapped keys and nonces for comparison
        $oldDekWrapped = $this->tenant->dek_wrapped;
        $oldIdxWrapped = $this->tenant->idx_wrapped;
        $oldDekNonce = $this->tenant->dek_nonce;
        $oldIdxNonce = $this->tenant->idx_nonce;

        $this->artisan('keys:rotate-kek')
            ->expectsOutput('Starting KEK rotation...')
            ->expectsOutputToContain('Backed up old KEK')
            ->expectsOutputToContain('Generated new KEK')
            ->expectsOutputToContain('Re-wrapped 1 tenant(s)')
            ->assertExitCode(0);

        // Refresh tenant from DB
        $this->tenant->refresh();

        // Wrapped keys should have changed (new KEK)
        expect($this->tenant->dek_wrapped)->not->toBe($oldDekWrapped);
        expect($this->tenant->idx_wrapped)->not->toBe($oldIdxWrapped);

        // Nonces should have changed (new wrapping)
        expect($this->tenant->dek_nonce)->not->toBe($oldDekNonce);
        expect($this->tenant->idx_nonce)->not->toBe($oldIdxNonce);

        // Key version should still be 1 (only wrapping changed, not the keys themselves)
        expect($this->tenant->key_version)->toBe(1);
    });

    test('data remains decryptable after KEK rotation', function (): void {
        // Decrypt before rotation
        $originalNote = $this->person->note_enc;

        $this->artisan('keys:rotate-kek')->assertExitCode(0);

        // Refresh both models
        $this->tenant->refresh();
        $this->person->refresh();

        // Note should still decrypt correctly
        expect($this->person->note_enc)->toBe($originalNote);
    });

    test('search still works after KEK rotation', function (): void {
        $this->artisan('keys:rotate-kek')->assertExitCode(0);

        // Refresh tenant
        $this->tenant->refresh();

        // Search by email should still work (blind index is stored as base64)
        $indexBinary = $this->tenant->generateBlindIndex(strtolower('test@example.com'));
        $indexBase64 = base64_encode($indexBinary);

        $found = Person::where('tenant_id', $this->tenant->id)
            ->where('email_idx', $indexBase64)
            ->first();

        expect($found)->not->toBeNull();
        expect($found->id)->toBe($this->person->id);
    });

    test('creates backup of old KEK', function (): void {
        $kekPath = getTestKekPath();
        $backupPath = $kekPath.'.'.date('Y-m-d_H-i-s').'.bak';

        $this->artisan('keys:rotate-kek')->assertExitCode(0);

        // Should have created a backup file (check with glob pattern)
        $backups = glob($kekPath.'.*.bak');
        expect($backups)->not->toBeEmpty();
    });
});

describe('keys:rotate-dek Command', function () {
    test('rotates DEK for specific tenant', function (): void {
        $this->artisan('keys:rotate-dek', ['tenant' => $this->tenant->id])
            ->expectsOutput("Starting DEK rotation for tenant {$this->tenant->id}...")
            ->expectsOutputToContain('Generated new DEK')
            ->expectsOutputToContain('Re-encrypted 1 record(s)')
            ->assertExitCode(0);

        $this->tenant->refresh();

        // DEK should have changed
        expect($this->tenant->dek_wrapped)->not->toBe($this->originalDekWrapped);

        // But note should still be decryptable with new DEK
        $this->person->refresh();
        expect($this->person->note_enc)->toBe('Test note');
    });

    test('re-encrypts all person records after DEK rotation', function (): void {
        // Create a second person
        $person2 = new Person;
        $person2->tenant_id = $this->tenant->id;
        $person2->email_plain = 'second@example.com';
        $person2->note_enc = 'Second note';
        $person2->save();

        // Store original ciphertexts
        $originalNote1 = $this->person->getAttributes()['note_enc'];
        $originalNote2 = $person2->getAttributes()['note_enc'];

        $this->artisan('keys:rotate-dek', ['tenant' => $this->tenant->id])
            ->assertExitCode(0);

        // Refresh persons
        $this->person->refresh();
        $person2->refresh();

        // Ciphertexts should have changed (new nonces, new DEK)
        expect($this->person->getAttributes()['note_enc'])->not->toBe($originalNote1);
        expect($person2->getAttributes()['note_enc'])->not->toBe($originalNote2);

        // But plaintexts should still be correct
        expect($this->person->note_enc)->toBe('Test note');
        expect($person2->note_enc)->toBe('Second note');
    });

    test('fails gracefully when tenant not found', function (): void {
        $this->artisan('keys:rotate-dek', ['tenant' => 9999])
            ->expectsOutput('Tenant 9999 not found.')
            ->assertExitCode(1);
    });
});

describe('idx:rebuild Command', function () {
    test('rebuilds blind indexes for specific tenant', function (): void {
        // Manually corrupt the index to simulate need for rebuild
        $this->person->update(['email_idx' => 'corrupted_index']);

        $this->artisan('idx:rebuild', ['tenant' => $this->tenant->id])
            ->expectsOutput("Rebuilding blind indexes for tenant {$this->tenant->id}...")
            ->expectsOutputToContain('Rebuilt indexes for 1 record(s)')
            ->assertExitCode(0);

        $this->person->refresh();

        // Index should be correct now (stored as base64)
        $expectedIndexBinary = $this->tenant->generateBlindIndex(strtolower('test@example.com'));
        $expectedIndexBase64 = base64_encode($expectedIndexBinary);
        expect($this->person->email_idx)->toBe($expectedIndexBase64);
    });

    test('search works after index rebuild', function (): void {
        // Corrupt indexes with direct DB update (avoid observer)
        Person::where('id', $this->person->id)->update([
            'email_idx' => 'corrupted_index_value',
            'phone_idx' => 'corrupted_index_value',
        ]);

        // Search should fail with corrupted index
        $indexBinary = $this->tenant->generateBlindIndex(strtolower('test@example.com'));
        $indexBase64 = base64_encode($indexBinary);

        $found = Person::where('tenant_id', $this->tenant->id)
            ->where('email_idx', $indexBase64)
            ->first();
        expect($found)->toBeNull();

        // Rebuild indexes
        $this->artisan('idx:rebuild', ['tenant' => $this->tenant->id])
            ->assertExitCode(0);

        // Search should work again
        $found = Person::where('tenant_id', $this->tenant->id)
            ->where('email_idx', $indexBase64)
            ->first();

        expect($found)->not->toBeNull();
        expect($found->id)->toBe($this->person->id);
    });

    test('fails gracefully when tenant not found', function (): void {
        $this->artisan('idx:rebuild', ['tenant' => 9999])
            ->expectsOutput('Tenant 9999 not found.')
            ->assertExitCode(1);
    });
});

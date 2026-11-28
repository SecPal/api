<?php

/*
 * SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use App\Models\Employee;
use App\Models\Person;
use App\Models\TenantKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * @property TenantKey $tenant
 * @property string $originalDekWrapped
 * @property Person $person
 */
beforeEach(function (): void {
    // Increment counter to ensure unique KEK file for this test
    incrementTestKekCounter();

    // Use process-specific KEK file for parallel test isolation
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();

    // Create a tenant with envelope keys

    $this->tenant = TenantKey::factory()->create();
    $this->originalDekWrapped = $this->tenant->dek_wrapped; // Store for rotation tests

    // Create a person with encrypted data for rotation testing
    $this->person = new Person;
    $this->person->tenant_id = $this->tenant->tenant_id;
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
    test('requires explicit maintenance authorization before rotating the KEK', function (): void {
        $before = hash_file('sha256', getTestKekPath());

        $this->artisan('keys:rotate-kek')->assertFailed();

        expect(hash_file('sha256', getTestKekPath()))->toBe($before)
            ->and($this->tenant->fresh()->dek_wrapped)->toBe($this->originalDekWrapped);
    });
});

describe('keys:rotate-dek Command', function () {
    test('malformed ciphertext rolls back all person rewrites and the DEK change', function (): void {
        $bad = Person::factory()->create(['tenant_id' => $this->tenant->tenant_id]);
        DB::table('person')->where('id', $bad->id)->update(['email_enc' => '{"ciphertext":"!","nonce":"!"}']);
        $originalNote = $this->person->getRawOriginal('note_enc');

        $this->artisan('keys:rotate-dek', ['tenant' => $this->tenant->tenant_id])->assertFailed();

        expect($this->tenant->fresh()->dek_wrapped)->toBe($this->originalDekWrapped)
            ->and($this->person->fresh()->getRawOriginal('note_enc'))->toBe($originalNote)
            ->and($this->person->fresh()->note_enc)->toBe('Test note');
    });

    test('refuses rotation when employee data also uses the tenant DEK', function (): void {
        $employee = Employee::factory()->create(['tenant_id' => $this->tenant->tenant_id]);
        $firstName = $employee->first_name;

        $this->artisan('keys:rotate-dek', ['tenant' => $this->tenant->tenant_id])->assertFailed();

        expect($this->tenant->fresh()->dek_wrapped)->toBe($this->originalDekWrapped)
            ->and($employee->fresh()->first_name)->toBe($firstName);
    });

    test('rotates DEK for specific tenant', function (): void {
        $this->artisan('keys:rotate-dek', ['tenant' => $this->tenant->tenant_id])
            ->expectsOutput("Starting DEK rotation for tenant {$this->tenant->tenant_id}...")
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
        $person2->tenant_id = $this->tenant->tenant_id;
        $person2->email_plain = 'second@example.com';
        $person2->note_enc = 'Second note';
        $person2->save();

        // Store original ciphertexts
        $originalNote1 = $this->person->getAttributes()['note_enc'];
        $originalNote2 = $person2->getAttributes()['note_enc'];

        $this->artisan('keys:rotate-dek', ['tenant' => $this->tenant->tenant_id])
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

        $this->artisan('idx:rebuild', ['tenant' => $this->tenant->tenant_id])
            ->expectsOutput("Rebuilding blind indexes for tenant {$this->tenant->tenant_id}...")
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

        $found = Person::where('tenant_id', $this->tenant->tenant_id)
            ->where('email_idx', $indexBase64)
            ->first();
        expect($found)->toBeNull();

        // Rebuild indexes
        $this->artisan('idx:rebuild', ['tenant' => $this->tenant->tenant_id])
            ->assertExitCode(0);

        // Search should work again
        $found = Person::where('tenant_id', $this->tenant->tenant_id)
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

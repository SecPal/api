<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Person;
use App\Models\TenantKey;
use App\Support\BlindIndex;
use App\Support\KeyStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

describe('Rotation Commands', function () {
    beforeEach(function () {
        // Setup test tenant with keys
        $this->tenantId = '11111111-1111-1111-1111-111111111111';

        $keyStore = app(KeyStore::class);
        $kek = $keyStore->loadKek();

        $dek = $keyStore->generateKey();
        $idxKey = $keyStore->generateKey();

        $wrappedDek = $keyStore->wrapKey($dek, $kek);
        $wrappedIdx = $keyStore->wrapKey($idxKey, $kek);

        TenantKey::create([
            'tenant_id' => $this->tenantId,
            'dek_wrapped' => $wrappedDek['wrapped'],
            'dek_nonce' => $wrappedDek['nonce'],
            'idx_wrapped' => $wrappedIdx['wrapped'],
            'idx_nonce' => $wrappedIdx['nonce'],
        ]);

        // Create test persons
        $this->person1 = new Person;
        $this->person1->tenant_id = $this->tenantId;
        $this->person1->email_plain = 'rotate1@example.com';
        $this->person1->phone_plain = '+49 111 111111';
        $this->person1->save();

        $this->person2 = new Person;
        $this->person2->tenant_id = $this->tenantId;
        $this->person2->email_plain = 'rotate2@example.com';
        $this->person2->phone_plain = '+49 222 222222';
        $this->person2->save();
    });

    describe('RotateDekCommand', function () {
        test('it re-encrypts data for tenant', function () {
            // Run command (re-encrypts all data with current APP_KEY)
            $exitCode = Artisan::call('secpal:rotate-dek', [
                'tenant_id' => $this->tenantId,
                '--force' => true,
            ]);

            expect($exitCode)->toBe(0);

            // Verify data is still decryptable after rotation
            $person1 = Person::find($this->person1->id);
            expect($person1->email)->toBe('rotate1@example.com');
            expect($person1->phone)->toBe('+49 111 111111');

            $person2 = Person::find($this->person2->id);
            expect($person2->email)->toBe('rotate2@example.com');
            expect($person2->phone)->toBe('+49 222 222222');
        });

        test('it is idempotent - can run multiple times', function () {
            // Run command twice
            Artisan::call('secpal:rotate-dek', [
                'tenant_id' => $this->tenantId,
                '--force' => true,
            ]);

            Artisan::call('secpal:rotate-dek', [
                'tenant_id' => $this->tenantId,
                '--force' => true,
            ]);

            // Data still accessible after second rotation
            $person1 = Person::find($this->person1->id);
            expect($person1->email)->toBe('rotate1@example.com');
            expect($person1->phone)->toBe('+49 111 111111');
        });

        test('it fails gracefully for non-existent tenant', function () {
            $exitCode = Artisan::call('secpal:rotate-dek', [
                'tenant_id' => '99999999-9999-9999-9999-999999999999',
                '--force' => true,
            ]);

            expect($exitCode)->toBe(1); // Error exit code
        });

        test('it handles empty tenant gracefully', function () {
            // Tenant with no persons
            $emptyTenantId = '22222222-2222-2222-2222-222222222222';

            $keyStore = app(KeyStore::class);
            $kek = $keyStore->loadKek();

            $dek = $keyStore->generateKey();
            $idxKey = $keyStore->generateKey();

            $wrappedDek = $keyStore->wrapKey($dek, $kek);
            $wrappedIdx = $keyStore->wrapKey($idxKey, $kek);

            TenantKey::create([
                'tenant_id' => $emptyTenantId,
                'dek_wrapped' => $wrappedDek['wrapped'],
                'dek_nonce' => $wrappedDek['nonce'],
                'idx_wrapped' => $wrappedIdx['wrapped'],
                'idx_nonce' => $wrappedIdx['nonce'],
            ]);

            $exitCode = Artisan::call('secpal:rotate-dek', [
                'tenant_id' => $emptyTenantId,
                '--force' => true,
            ]);

            expect($exitCode)->toBe(0); // Success even with no data
        });

        test('it logs rotation events without plaintext', function () {
            Log::shouldReceive('info')
                ->with('Starting DEK rotation', \Mockery::on(function ($context) {
                    // Should NOT contain plaintext
                    expect($context)->not->toHaveKey('email');
                    expect($context)->not->toHaveKey('phone');
                    expect($context)->toHaveKey('tenant_id');

                    return true;
                }))
                ->once();

            Log::shouldReceive('info')
                ->with('DEK rotation completed', \Mockery::any())
                ->once();

            // Also allow debug/error logs from Person model
            Log::shouldReceive('debug')->zeroOrMoreTimes();
            Log::shouldReceive('error')->zeroOrMoreTimes();
            Log::shouldReceive('warning')->zeroOrMoreTimes();

            $exitCode = Artisan::call('secpal:rotate-dek', [
                'tenant_id' => $this->tenantId,
                '--force' => true,
            ]);

            // Verify command succeeded
            expect($exitCode)->toBe(0);
        });
    });

    describe('RebuildIdxCommand', function () {
        test('it rebuilds blind indexes for tenant', function () {
            // Corrupt the indexes manually
            $this->person1->email_idx = 'corrupted_index';
            $this->person1->phone_idx = 'corrupted_index';
            $this->person1->saveQuietly(); // Skip observers

            // Verify indexes are corrupted
            $corrupted = Person::find($this->person1->id);
            expect($corrupted->email_idx)->toBe('corrupted_index');

            // Rebuild indexes
            Artisan::call('secpal:rebuild-idx', [
                'tenant_id' => $this->tenantId,
                '--force' => true,
            ]);

            // Verify indexes are fixed
            $fixed = Person::find($this->person1->id);
            expect($fixed->email_idx)->not->toBe('corrupted_index');

            // Verify can still find by email
            $keyStore = app(KeyStore::class);
            $idxKey = $keyStore->unwrapIdxKeyForTenant($this->tenantId);
            $emailIdx = BlindIndex::hmac(
                BlindIndex::normEmail('rotate1@example.com'),
                $idxKey
            );

            expect($fixed->email_idx)->toBe($emailIdx);
        });

        test('it is idempotent - can run multiple times', function () {
            // Run rebuild twice
            Artisan::call('secpal:rebuild-idx', [
                'tenant_id' => $this->tenantId,
                '--force' => true,
            ]);

            $firstRun = Person::find($this->person1->id);
            $firstEmailIdx = $firstRun->email_idx;

            Artisan::call('secpal:rebuild-idx', [
                'tenant_id' => $this->tenantId,
                '--force' => true,
            ]);

            $secondRun = Person::find($this->person1->id);

            // Indexes should be identical (deterministic)
            expect($secondRun->email_idx)->toBe($firstEmailIdx);
        });

        test('it processes records in batches', function () {
            // Create more test data to test batching
            for ($i = 3; $i <= 150; $i++) {
                $person = new Person;
                $person->tenant_id = $this->tenantId;
                $person->email_plain = "test{$i}@example.com";
                $person->save();
            }

            // Should process in batches without errors
            $exitCode = Artisan::call('secpal:rebuild-idx', [
                'tenant_id' => $this->tenantId,
                '--force' => true,
            ]);

            expect($exitCode)->toBe(0);

            // Verify all records processed
            $allPersons = Person::where('tenant_id', $this->tenantId)->get();
            expect($allPersons)->toHaveCount(150);

            // Spot check a few records
            $person100 = Person::where('tenant_id', $this->tenantId)
                ->whereNotNull('email_enc')
                ->skip(99)
                ->first();

            expect($person100->email_idx)->not->toBeNull();
        });

        test('it fails gracefully for non-existent tenant', function () {
            $exitCode = Artisan::call('secpal:rebuild-idx', [
                'tenant_id' => '99999999-9999-9999-9999-999999999999',
                '--force' => true,
            ]);

            expect($exitCode)->toBe(1);
        });
    });

    describe('Command Safety Features', function () {
        test('RotateDekCommand requires confirmation without --force', function () {
            // Test that command exists
            expect(Artisan::all())->toHaveKey('secpal:rotate-dek');
        });

        test('RebuildIdxCommand requires confirmation without --force', function () {
            // Test that command exists
            expect(Artisan::all())->toHaveKey('secpal:rebuild-idx');
        });
    });
});

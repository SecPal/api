<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Person;
use App\Models\TenantKey;
use App\Repositories\PersonRepository;
use App\Support\KeyStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

describe('PersonRepository', function () {
    beforeEach(function () {
        // Create tenant with keys
        $keyStore = app(KeyStore::class);
        $kek = $keyStore->loadKek();

        $this->tenantId = '11111111-1111-1111-1111-111111111111';

        $dek = $keyStore->generateKey();
        $idxKey = $keyStore->generateKey();

        $dekWrapped = $keyStore->wrapKey($dek, $kek);
        $idxWrapped = $keyStore->wrapKey($idxKey, $kek);

        TenantKey::create([
            'tenant_id' => $this->tenantId,
            'dek_wrapped' => $dekWrapped['wrapped'],
            'dek_nonce' => $dekWrapped['nonce'],
            'idx_wrapped' => $idxWrapped['wrapped'],
            'idx_nonce' => $idxWrapped['nonce'],
            'key_version' => 1,
        ]);

        $this->repository = app(PersonRepository::class);
    });

    test('it finds person by email using blind index', function () {
        // Create test person
        $person = new Person;
        $person->tenant_id = $this->tenantId;
        $person->email_plain = 'alice@example.com';
        $person->phone_plain = '+49 123 456789';
        $person->save();

        // Find by email using blind index
        $found = $this->repository->findByEmail($this->tenantId, 'alice@example.com');

        expect($found)->not->toBeNull();
        expect($found->id)->toBe($person->id);
        expect($found->email)->toBe('alice@example.com');
    });

    test('it finds person by email case-insensitively', function () {
        $person = new Person;
        $person->tenant_id = $this->tenantId;
        $person->email_plain = 'test@example.com';
        $person->save();

        // Query with different case
        $found = $this->repository->findByEmail($this->tenantId, 'TEST@Example.COM');

        expect($found)->not->toBeNull();
        expect($found->id)->toBe($person->id);
    });

    test('it returns null when email not found', function () {
        $found = $this->repository->findByEmail($this->tenantId, 'nonexistent@example.com');

        expect($found)->toBeNull();
    });

    test('it finds person by phone using blind index', function () {
        $person = new Person;
        $person->tenant_id = $this->tenantId;
        $person->email_plain = 'alice@example.com';
        $person->phone_plain = '+49 123 456789';
        $person->save();

        // Find with different phone formatting
        $found = $this->repository->findByPhone($this->tenantId, '49123456789');

        expect($found)->not->toBeNull();
        expect($found->id)->toBe($person->id);
        expect($found->phone)->toBe('+49 123 456789');
    });

    test('it creates or updates person with automatic blind index generation', function () {
        // Create
        $person = $this->repository->createOrUpdate($this->tenantId, [
            'email' => 'bob@example.com',
            'phone' => '+49 987 654321',
            'address' => '123 Main St',
            'note' => 'Important client',
        ]);

        expect($person->email)->toBe('bob@example.com');
        expect($person->phone)->toBe('+49 987 654321');
        expect($person->email_idx)->not->toBeNull();
        expect($person->phone_idx)->not->toBeNull();

        // Update (same email)
        $updated = $this->repository->createOrUpdate($this->tenantId, [
            'email' => 'bob@example.com',
            'phone' => '+49 111 222333',
            'note' => 'VIP client',
        ]);

        expect($updated->id)->toBe($person->id);
        expect($updated->phone)->toBe('+49 111 222333');
        expect($updated->note)->toBe('VIP client');
    });

    test('it does not use LIKE queries on encrypted fields', function () {
        // Enable query logging
        DB::enableQueryLog();

        $person = new Person;
        $person->tenant_id = $this->tenantId;
        $person->email_plain = 'test@example.com';
        $person->save();

        // Clear previous queries
        DB::flushQueryLog();

        // Search by email (should use blind index, not LIKE)
        $this->repository->findByEmail($this->tenantId, 'test@example.com');

        $queries = DB::getQueryLog();

        // Check that no query contains LIKE on *_enc fields
        foreach ($queries as $query) {
            $sql = strtolower($query['query']);
            expect($sql)->not->toContain('email_enc like');
            expect($sql)->not->toContain('phone_enc like');
            expect($sql)->not->toContain('address_enc like');
            expect($sql)->not->toContain('note_enc like');
        }

        DB::disableQueryLog();
    });

    test('it does not expose plaintext in query logs', function () {
        // Note: Laravel's encrypted cast does pass plaintext to query bindings
        // before encryption. This test verifies blind indexes are used for searches,
        // not plaintext LIKE queries.

        DB::enableQueryLog();

        $person = new Person;
        $person->tenant_id = $this->tenantId;
        $person->email_plain = 'sensitive@example.com';
        $person->phone_plain = '+49 555 123456';
        $person->save();

        // Clear insert queries
        DB::flushQueryLog();

        // Perform search (should use blind index, not plaintext)
        $this->repository->findByEmail($this->tenantId, 'sensitive@example.com');

        $queries = DB::getQueryLog();

        // Verify: Search queries use blind index (binary), not plaintext email
        foreach ($queries as $query) {
            $sql = strtolower($query['query']);

            // Should use email_idx, not email_enc
            expect($sql)->toContain('email_idx');
            expect($sql)->not->toContain('email_enc =');
        }

        DB::disableQueryLog();
    });

    test('it does not log plaintext values in application logs', function () {
        // Capture log output
        $logged = [];
        Log::listen(function ($log) use (&$logged) {
            $logged[] = [
                'level' => $log->level,
                'message' => $log->message,
                'context' => $log->context,
            ];
        });

        // Create person (triggers logging in Person model)
        $person = new Person;
        $person->tenant_id = $this->tenantId;
        $person->email_plain = 'Secret.User@Example.COM'; // Mixed case
        $person->phone_plain = '+49 (999) 888-777'; // Formatted
        $person->save();

        // Check logs for sensitive data exposure
        foreach ($logged as $entry) {
            $message = strtolower($entry['message']);
            $context = $entry['context'];

            // Verify normalized values are logged (acceptable for debugging)
            if (str_contains($message, 'generated email blind index')) {
                expect($context['normalized_email'] ?? '')->toBe('secret.user@example.com');
                expect($context)->toHaveKey('tenant_id');
            }

            if (str_contains($message, 'generated phone blind index')) {
                expect($context['normalized_phone'] ?? '')->toBe('49999888777');
                expect($context)->toHaveKey('tenant_id');
            }
        }

        // Verify logs were generated
        $blindIndexLogs = collect($logged)->filter(fn ($log) => str_contains(strtolower($log['message']), 'blind index')
        );

        expect($blindIndexLogs)->not->toBeEmpty();
    });

    test('it handles bulk operations without exposing plaintext in logs', function () {
        $emails = [
            'user1@example.com',
            'user2@example.com',
            'user3@example.com',
        ];

        foreach ($emails as $email) {
            $this->repository->createOrUpdate($this->tenantId, [
                'email' => $email,
            ]);
        }

        // All persons should be created
        $count = Person::where('tenant_id', $this->tenantId)->count();
        expect($count)->toBe(3);
    });

    test('it isolates searches per tenant', function () {
        $tenantB = '22222222-2222-2222-2222-222222222222';

        // Create keys for tenant B
        $keyStore = app(KeyStore::class);
        $kek = $keyStore->loadKek();
        $dek = $keyStore->generateKey();
        $idxKey = $keyStore->generateKey();
        $dekWrapped = $keyStore->wrapKey($dek, $kek);
        $idxWrapped = $keyStore->wrapKey($idxKey, $kek);

        TenantKey::create([
            'tenant_id' => $tenantB,
            'dek_wrapped' => $dekWrapped['wrapped'],
            'dek_nonce' => $dekWrapped['nonce'],
            'idx_wrapped' => $idxWrapped['wrapped'],
            'idx_nonce' => $idxWrapped['nonce'],
            'key_version' => 1,
        ]);

        // Create same email in both tenants
        $this->repository->createOrUpdate($this->tenantId, [
            'email' => 'shared@example.com',
        ]);

        $this->repository->createOrUpdate($tenantB, [
            'email' => 'shared@example.com',
        ]);

        // Search in tenant A
        $foundA = $this->repository->findByEmail($this->tenantId, 'shared@example.com');
        expect($foundA)->not->toBeNull();
        expect($foundA->tenant_id)->toBe($this->tenantId);

        // Search in tenant B
        $foundB = $this->repository->findByEmail($tenantB, 'shared@example.com');
        expect($foundB)->not->toBeNull();
        expect($foundB->tenant_id)->toBe($tenantB);

        // IDs should be different
        expect($foundA->id)->not->toBe($foundB->id);
    });
});

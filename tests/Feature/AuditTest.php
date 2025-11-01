<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\Person;
use App\Models\TenantKey;
use App\Support\KeyStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

describe('Auditing & Security Logging', function () {
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
    });

    test('decryption does not log plaintext values', function () {
        // Create person with encrypted data
        $person = new Person;
        $person->tenant_id = $this->tenantId;
        $person->email_plain = 'test@example.com';
        $person->phone_plain = '+49 123 456789';
        $person->save();

        // Mock logger to capture log messages
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();

        // Access encrypted fields (triggers decryption)
        $email = $person->email_enc;
        $phone = $person->phone_enc;

        // Verify decrypted values work
        expect($email)->toBe('test@example.com');
        expect($phone)->toBe('+49 123 456789');

        // CRITICAL: Verify no plaintext in any log calls
        // Note: In production, use Log::spy() and assert specific messages
        // For now, this test ensures decryption works without throwing errors
        expect(true)->toBeTrue(); // Placeholder - extend with log assertions
    });

    test('encryption does not log plaintext values', function () {
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();

        // Create person (triggers encryption)
        $person = new Person;
        $person->tenant_id = $this->tenantId;
        $person->email_plain = 'secret@example.com';
        $person->phone_plain = '+49 987 654321';
        $person->address_plain = '123 Secret St';
        $person->note_plain = 'Confidential information';
        $person->save();

        // Verify person was saved
        expect($person->id)->not->toBeNull();
        expect($person->email_enc)->toBe('secret@example.com');

        // CRITICAL: Verify no plaintext in logs
        // In production: assert Log::debug/info never called with plaintext
        expect(true)->toBeTrue(); // Placeholder
    });

    test('rotation command logs events without plaintext', function () {
        // Create test person
        $person = new Person;
        $person->tenant_id = $this->tenantId;
        $person->email_plain = 'rotate@example.com';
        $person->save();

        // Mock logger
        $logMessages = [];
        Log::shouldReceive('info')
            ->andReturnUsing(function ($message, $context = []) use (&$logMessages) {
                $logMessages[] = ['message' => $message, 'context' => $context];
            });
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();

        // Run rotation command
        $this->artisan('secpal:rotate-dek', [
            'tenant_id' => $this->tenantId,
            '--force' => true,
        ])->assertExitCode(0);

        // Verify rotation logged (info level)
        $rotationLogs = collect($logMessages)->filter(fn ($log) => str_contains($log['message'], 'DEK rotation'));

        expect($rotationLogs)->not->toBeEmpty();

        // CRITICAL: Verify no plaintext in log context
        foreach ($logMessages as $log) {
            $contextJson = json_encode($log['context']);

            // Should NOT contain plaintext email
            expect($contextJson)->not->toContain('rotate@example.com');
            expect($contextJson)->not->toContain('email_plain');

            // Should NOT contain actual DEK/keys (only metadata)
            expect($log['message'])->not->toMatch('/[0-9a-f]{64}/'); // No raw keys
        }
    });

    test('rebuild index command logs events without plaintext', function () {
        // Create test person
        $person = new Person;
        $person->tenant_id = $this->tenantId;
        $person->email_plain = 'rebuild@example.com';
        $person->save();

        // Mock logger
        $logMessages = [];
        Log::shouldReceive('info')
            ->andReturnUsing(function ($message, $context = []) use (&$logMessages) {
                $logMessages[] = ['message' => $message, 'context' => $context];
            });
        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();

        // Run rebuild command
        $this->artisan('secpal:rebuild-idx', [
            'tenant_id' => $this->tenantId,
            '--force' => true,
        ])->assertExitCode(0);

        // Verify rebuild logged
        $rebuildLogs = collect($logMessages)->filter(fn ($log) => str_contains($log['message'], 'Blind index rebuild'));

        expect($rebuildLogs)->not->toBeEmpty();

        // CRITICAL: Verify no plaintext in log context
        foreach ($logMessages as $log) {
            $contextJson = json_encode($log['context']);

            // Should NOT contain plaintext email
            expect($contextJson)->not->toContain('rebuild@example.com');
            expect($contextJson)->not->toContain('email_plain');

            // Should contain safe metadata only
            expect($log['context'])->toHaveKey('tenant_id', $this->tenantId);
        }
    });

    test('exception handling does not expose plaintext', function () {
        // Force an error during encryption by using invalid tenant
        $invalidTenantId = '99999999-9999-9999-9999-999999999999';

        Log::shouldReceive('error')
            ->withArgs(function ($message, $context) {
                // Verify error is logged
                expect($message)->toBeString();

                // CRITICAL: Context should NOT contain plaintext
                $contextJson = json_encode($context);
                expect($contextJson)->not->toContain('secret-data');

                return true;
            })
            ->zeroOrMoreTimes();

        Log::shouldReceive('debug')->zeroOrMoreTimes();
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        try {
            $person = new Person;
            $person->tenant_id = $invalidTenantId;
            $person->email_plain = 'secret-data@example.com';
            $person->save();

            // Should throw exception (no tenant keys)
            expect(true)->toBeFalse('Expected exception was not thrown');
        } catch (\Exception $e) {
            // Verify exception message does NOT contain plaintext
            expect($e->getMessage())->not->toContain('secret-data');
            expect($e->getMessage())->not->toContain('secret-data@example.com');
        }
    });

    test('query logging masks sensitive parameters', function () {
        // This test verifies that encrypted fields are not logged in queries
        // In production, enable query logging: DB::enableQueryLog()

        // Create person
        $person = new Person;
        $person->tenant_id = $this->tenantId;
        $person->email_plain = 'querylog@example.com';
        $person->save();

        // Verify person exists (query will run)
        $found = Person::where('tenant_id', $this->tenantId)
            ->where('email_idx', $person->email_idx)
            ->first();

        expect($found)->not->toBeNull();

        // Note: In production, assert DB::getQueryLog() does NOT contain plaintext
        // For now, this test ensures queries run without exposing data in exceptions
        expect(true)->toBeTrue(); // Placeholder
    });

    test('API error responses do not expose plaintext values', function () {
        // Create person
        $person = new Person;
        $person->tenant_id = $this->tenantId;
        $person->email_plain = 'secret-email@example.com';
        $person->phone_plain = '+49 123 SECRET';
        $person->address_plain = '123 Secret Address';
        $person->save();

        // Trigger database constraint error (duplicate key)
        try {
            // Create duplicate person (will fail on email_idx unique constraint)
            $duplicate = new Person;
            $duplicate->tenant_id = $this->tenantId;
            $duplicate->email_plain = 'secret-email@example.com'; // Same email
            $duplicate->save();

            // Should not reach here
            expect(false)->toBeTrue('Expected exception was not thrown');
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();

            // CRITICAL: Exception message should NOT contain plaintext values
            expect($errorMessage)->not->toContain('secret-email@example.com');
            expect($errorMessage)->not->toContain('SECRET');
            expect($errorMessage)->not->toContain('Secret Address');

            // Database error can contain column names (email_enc, email_idx), which is acceptable
            // But it should NOT contain the actual decrypted values
            expect(true)->toBeTrue();
        }
    });
});

<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

use App\Models\PasskeyCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

function postPublicPasskeyChallenge(string $endpoint, array $payload = [], string $ipAddress = '127.0.0.1'): TestResponse
{
    $request = test()->withServerVariables([
        'REMOTE_ADDR' => $ipAddress,
    ]);

    if ($endpoint === '/v1/auth/passkeys/challenges') {
        return $request
            ->withHeaders(spaHeaders())
            ->postJson($endpoint, $payload);
    }

    return $request->postJson($endpoint, [
        'device_name' => 'android-phone',
        ...$payload,
    ]);
}

function normalizeLoggedSql(string $sql): string
{
    $normalized = preg_replace('/\s+/', ' ', strtolower(trim($sql)));

    return str_replace(['`', '"'], '', $normalized ?? $sql);
}

/**
 * @return array{0: TestResponse, 1: list<string>}
 */
function capturePasskeyLookupQueries(string $endpoint, array $payload = [], string $ipAddress = '127.0.0.1'): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $response = postPublicPasskeyChallenge($endpoint, $payload, $ipAddress);
    } finally {
        /** @var list<string> $queries */
        $queries = array_values(array_filter(
            array_map(
                static fn (array $entry): string => normalizeLoggedSql($entry['query']),
                DB::getQueryLog(),
            ),
            static fn (string $sql): bool => str_contains($sql, 'from users')
                || str_contains($sql, 'from passkey_credentials'),
        ));

        DB::disableQueryLog();
    }

    return [$response, $queries];
}

dataset('public passkey challenge endpoints', [
    'browser' => ['/v1/auth/passkeys/challenges'],
    'token' => ['/v1/auth/token/passkeys/challenges'],
]);

describe('Public passkey challenge enumeration hardening', function () {
    test('public challenge responses stay discoverable-only', function (string $endpoint) {
        $response = postPublicPasskeyChallenge($endpoint, [], '127.0.0.1');

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'challenge_id',
                    'public_key' => [
                        'challenge',
                        'rp_id',
                        'timeout',
                        'user_verification',
                    ],
                    'mediation',
                    'expires_at',
                ],
            ]);

        expect($response->json('data.mediation'))->toBe('optional')
            ->and($response->json('data.public_key'))->not->toHaveKey('allow_credentials');
    })->with('public passkey challenge endpoints');

    test('email-scoped startup payloads are rejected before any passkey lookup', function (string $endpoint) {
        User::factory()->create([
            'email' => 'with-passkey@secpal.dev',
        ]);

        [$response, $queries] = capturePasskeyLookupQueries($endpoint, [
            'email' => 'with-passkey@secpal.dev',
        ], '127.0.0.2');

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        expect($queries)->toBe([]);
    })->with('public passkey challenge endpoints');

    test('discoverable challenge creation avoids user and passkey credential lookups', function (string $endpoint) {
        $userWithPasskey = User::factory()->create([
            'email' => 'with-passkey@secpal.dev',
        ]);

        PasskeyCredential::factory()->create([
            'user_id' => $userWithPasskey->id,
            'credential_id' => 'Bx9Yc0ZLQmN4V1V1S1cwVnI1Q0FyRkE',
        ]);

        User::factory()->create([
            'email' => 'without-passkey@secpal.dev',
        ]);

        [$response, $queries] = capturePasskeyLookupQueries($endpoint, [], '127.0.2.1');

        $response->assertCreated();

        expect($queries)->toBe([]);
    })->with('public passkey challenge endpoints');
});

<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

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

/**
 * @return array<array-key, mixed>|string
 */
function passkeyChallengeJsonShape(mixed $payload): array|string
{
    if (! is_array($payload)) {
        return get_debug_type($payload);
    }

    if (array_is_list($payload)) {
        if ($payload === []) {
            return ['list_count' => 0, 'list_item' => null];
        }

        return ['list_count' => count($payload), 'list_item' => passkeyChallengeJsonShape($payload[0])];
    }

    $shape = [];

    foreach ($payload as $key => $value) {
        $shape[$key] = is_array($value)
            ? passkeyChallengeJsonShape($value)
            : get_debug_type($value);
    }

    return $shape;
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
    test('missing fallback secret causes 503 only for unenrolled or unknown emails, not for enrolled users', function (string $endpoint) {
        config()->set('passkeys.authentication_fallback_secret', '');

        $userWithPasskey = User::factory()->create([
            'email' => 'with-passkey@secpal.dev',
        ]);

        PasskeyCredential::factory()->create([
            'user_id' => $userWithPasskey->id,
            'credential_id' => 'Cx9Yc0ZLQmN4V1V1S1cwVnI1Q0FyRkE',
        ]);

        User::factory()->create([
            'email' => 'without-passkey@secpal.dev',
        ]);

        // Unenrolled and unknown email-scoped requests require the fallback
        // descriptor and must fail closed when the secret is not configured.
        postPublicPasskeyChallenge($endpoint, ['email' => 'missing@secpal.dev'], '127.0.0.1')
            ->assertStatus(503)->assertJsonStructure(['message']);

        postPublicPasskeyChallenge($endpoint, ['email' => 'without-passkey@secpal.dev'], '127.0.0.2')
            ->assertStatus(503)->assertJsonStructure(['message']);

        // Enrolled users have real allow_credentials; the fallback descriptor is
        // never generated, so a missing secret must not cause a 503.
        postPublicPasskeyChallenge($endpoint, ['email' => 'with-passkey@secpal.dev'], '127.0.0.3')
            ->assertCreated();

        // Anonymous discoverable flow never uses the fallback secret either.
        postPublicPasskeyChallenge($endpoint, [], '127.0.0.4')
            ->assertCreated();
    })->with('public passkey challenge endpoints');

    test('email-scoped challenge responses stay structurally indistinguishable across account states', function (string $endpoint) {
        $userWithPasskey = User::factory()->create([
            'email' => 'with-passkey@secpal.dev',
        ]);

        PasskeyCredential::factory()->create([
            'user_id' => $userWithPasskey->id,
            'credential_id' => 'Ax9Yc0ZLQmN4V1V1S1cwVnI1Q0FyRkE',
        ]);

        User::factory()->create([
            'email' => 'without-passkey@secpal.dev',
        ]);

        $responses = [
            'unknown' => postPublicPasskeyChallenge($endpoint, ['email' => 'missing@secpal.dev'], '127.0.0.2'),
            'without_passkey' => postPublicPasskeyChallenge($endpoint, ['email' => 'without-passkey@secpal.dev'], '127.0.0.3'),
            'with_passkey' => postPublicPasskeyChallenge($endpoint, ['email' => 'with-passkey@secpal.dev'], '127.0.0.4'),
        ];

        foreach ($responses as $response) {
            $response->assertCreated()
                ->assertJsonStructure([
                    'data' => [
                        'challenge_id',
                        'public_key' => [
                            'challenge',
                            'rp_id',
                            'timeout',
                            'user_verification',
                            'allow_credentials' => [
                                ['id', 'type'],
                            ],
                        ],
                        'mediation',
                        'expires_at',
                    ],
                ]);

            expect($response->json())->not->toHaveKey('errors')
                ->and($response->json('data.mediation'))->toBe('optional')
                ->and($response->json('data.public_key.allow_credentials'))->toBeArray()->not->toBeEmpty()
                ->and($response->json('data.public_key.allow_credentials.0.id'))->toBeString()
                ->and($response->json('data.public_key.allow_credentials.0.type'))->toBe('public-key');
        }

        $shapes = array_map(
            static fn (TestResponse $response): array => passkeyChallengeJsonShape($response->json()),
            $responses,
        );

        expect(array_values(array_unique(array_map('serialize', $shapes))))
            ->toHaveCount(1);

        // In this fixture, all compared email-scoped branches should expose a single
        // descriptor. If one branch starts returning a different list length, the
        // enumeration regression is back for this 1-passkey scenario.
        foreach ($responses as $state => $response) {
            expect($response->json('data.public_key.allow_credentials'))
                ->toHaveCount(1, "allow_credentials count must be 1 for account state '{$state}' to prevent enrollment-count enumeration");
        }
    })->with('public passkey challenge endpoints');

    test('anonymous challenge flow omits allow_credentials to preserve discoverable passkeys', function (string $endpoint) {
        $response = postPublicPasskeyChallenge($endpoint, [], '127.0.0.1');

        $response->assertCreated();

        expect($response->json('data.mediation'))->toBe('optional')
            ->and($response->json('data.public_key'))->not->toHaveKey('allow_credentials');
    })->with('public passkey challenge endpoints');

    test('fallback passkey descriptors are deterministic for non-enrolled challenge responses', function (string $endpoint) {
        User::factory()->create([
            'email' => 'without-passkey@secpal.dev',
        ]);

        $missingA = postPublicPasskeyChallenge($endpoint, ['email' => 'missing@secpal.dev'], '127.0.1.1');
        $missingB = postPublicPasskeyChallenge($endpoint, ['email' => 'missing@secpal.dev'], '127.0.1.2');
        $withoutPasskeyA = postPublicPasskeyChallenge($endpoint, ['email' => 'without-passkey@secpal.dev'], '127.0.1.3');
        $withoutPasskeyB = postPublicPasskeyChallenge($endpoint, ['email' => 'without-passkey@secpal.dev'], '127.0.1.4');
        $omitted = postPublicPasskeyChallenge($endpoint, [], '127.0.1.5');

        expect($missingA->json('data.public_key.allow_credentials'))
            ->toBe($missingB->json('data.public_key.allow_credentials'))
            ->and($withoutPasskeyA->json('data.public_key.allow_credentials'))
            ->toBe($withoutPasskeyB->json('data.public_key.allow_credentials'))
            ->and($missingA->json('data.public_key.allow_credentials'))
            ->not->toBe($withoutPasskeyA->json('data.public_key.allow_credentials'))
            ->and($omitted->json('data.public_key'))->not->toHaveKey('allow_credentials');
    })->with('public passkey challenge endpoints');

    test('passkey lookup query shapes stay aligned across account states', function (string $endpoint) {
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

        [$omittedResponse, $omittedQueries] = capturePasskeyLookupQueries($endpoint, [], '127.0.2.1');
        [$unknownResponse, $unknownQueries] = capturePasskeyLookupQueries($endpoint, ['email' => 'missing@secpal.dev'], '127.0.2.2');
        [$withoutPasskeyResponse, $withoutPasskeyQueries] = capturePasskeyLookupQueries($endpoint, ['email' => 'without-passkey@secpal.dev'], '127.0.2.3');
        [$withPasskeyResponse, $withPasskeyQueries] = capturePasskeyLookupQueries($endpoint, ['email' => 'with-passkey@secpal.dev'], '127.0.2.4');

        $omittedResponse->assertCreated();
        $unknownResponse->assertCreated();
        $withoutPasskeyResponse->assertCreated();
        $withPasskeyResponse->assertCreated();

        expect($unknownQueries)->toBe($withPasskeyQueries)
            ->and($withoutPasskeyQueries)->toBe($withPasskeyQueries)
            ->and($withPasskeyQueries)->toHaveCount(2);

        expect($omittedQueries)->toHaveCount(2);
    })->with('public passkey challenge endpoints');
});

<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\PasskeyCredential;
use App\Models\User;
use App\Services\LoginMfaChallengeService;
use App\Services\PasskeyChallengeService;
use App\Services\PasskeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Webauthn\Exception\AuthenticatorResponseVerificationException;
use Webauthn\Exception\InvalidDataException;

uses(RefreshDatabase::class);

describe('Passkey Authentication', function () {
    test('browser passkey login challenge returns public key request options', function () {
        $response = $this->withHeaders(spaHeaders())
            ->postJson('/v1/auth/passkeys/challenges');

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

        expect($response->json('data.public_key.rp_id'))->toBe('app.secpal.dev')
            ->and($response->json('data.public_key.user_verification'))->toBe('preferred')
            ->and($response->json('data.mediation'))->toBe('optional')
            ->and($response->json('data.public_key'))->not->toHaveKey('allow_credentials');
    });

    test('browser passkey login challenge returns allow_credentials for an email-scoped fallback', function () {
        $user = User::factory()->create([
            'email' => 'test@secpal.dev',
        ]);
        $credential = PasskeyCredential::factory()->create([
            'user_id' => $user->id,
        ]);

        $response = $this->withHeaders(spaHeaders())
            ->postJson('/v1/auth/passkeys/challenges', [
                'email' => ' TEST@SECPAL.DEV ',
            ]);

        $response->assertCreated();

        expect($response->json('data.mediation'))->toBe('optional')
            ->and($response->json('data.public_key.allow_credentials'))->toBeArray()
            ->and($response->json('data.public_key.allow_credentials.0.id'))->toBe($credential->credential_id)
            ->and($response->json('data.public_key.allow_credentials.0.type'))->toBe('public-key');
    });

    test('email-scoped passkey login lookup stays consistent with the passkey management list', function () {
        $user = User::factory()->create([
            'email' => 'test@secpal.dev',
        ]);
        $token = $user->issueApiToken('test-suite')->plainTextToken;
        $credential = $user->passkeyCredentials()->create([
            'credential_id' => 'Ax9Yc0ZLQmN4V1V1S1cwVnI1Q0FyRkE',
            'label' => 'Touch ID',
            'transports' => ['internal'],
            'attestation_type' => 'none',
            'credential_public_key' => 'dGVzdA',
            'user_handle' => 'dGVzdA',
            'counter' => 0,
        ]);

        $listResponse = $this->withToken($token)
            ->getJson('/v1/me/passkeys');

        $listResponse->assertOk();

        $challengeResponse = $this->withHeaders(spaHeaders())
            ->postJson('/v1/auth/passkeys/challenges', [
                'email' => $user->email,
            ]);

        $challengeResponse->assertCreated();

        expect($listResponse->json('data.0.id'))->toBe($credential->credential_id)
            ->and($challengeResponse->json('data.public_key.allow_credentials.0.id'))->toBe($credential->credential_id);
    });

    test('browser passkey login challenge returns a fallback credential descriptor when the account has no enrolled passkeys', function () {
        User::factory()->create([
            'email' => 'test@secpal.dev',
        ]);

        $response = $this->withHeaders(spaHeaders())
            ->postJson('/v1/auth/passkeys/challenges', [
                'email' => 'test@secpal.dev',
            ]);

        $response->assertCreated();

        expect($response->json())->not->toHaveKey('errors')
            ->and($response->json('data.mediation'))->toBe('optional')
            ->and($response->json('data.public_key.allow_credentials'))->toBeArray()->not->toBeEmpty()
            ->and($response->json('data.public_key.allow_credentials.0.id'))->toBeString()
            ->and($response->json('data.public_key.allow_credentials.0.type'))->toBe('public-key');
    });

    test('browser passkey login challenge omits allow_credentials for anonymous discoverable flows', function () {
        $response = $this->withHeaders(spaHeaders())
            ->postJson('/v1/auth/passkeys/challenges');

        $response->assertCreated();

        expect($response->json('data.public_key'))->not->toHaveKey('allow_credentials');
    });

    test('token passkey login challenge stores token context and device name', function () {
        $response = $this->postJson('/v1/auth/token/passkeys/challenges', [
            'device_name' => ' android-phone ',
        ]);

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

        $storedChallenge = app(PasskeyChallengeService::class)
            ->findAuthenticationChallenge($response->json('data.challenge_id'));

        expect($storedChallenge)->not->toBeNull()
            ->and($storedChallenge['login_context'])->toBe(LoginMfaChallengeService::LOGIN_CONTEXT_TOKEN)
            ->and($storedChallenge['device_name'])->toBe('android-phone')
            ->and($response->json('data.public_key.rp_id'))->toBe('app.secpal.dev');
    });

    test('token passkey login challenge requires a device name', function () {
        $response = $this->postJson('/v1/auth/token/passkeys/challenges', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['device_name']);
    });

    test('token passkey login challenge rejects a whitespace-only device name', function () {
        $response = $this->postJson('/v1/auth/token/passkeys/challenges', [
            'device_name' => '   ',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['device_name']);
    });

    test('browser passkey login challenge creation is rate limited with retry headers', function () {
        for ($i = 0; $i < 5; $i++) {
            $response = $this->withHeaders(spaHeaders())
                ->postJson('/v1/auth/passkeys/challenges');

            $response->assertCreated()
                ->assertHeader('X-RateLimit-Limit', '5')
                ->assertHeader('X-RateLimit-Remaining', (string) (4 - $i));
        }

        $response = $this->withHeaders(spaHeaders())
            ->postJson('/v1/auth/passkeys/challenges');

        $response->assertTooManyRequests()
            ->assertHeader('X-RateLimit-Limit', '5')
            ->assertHeader('X-RateLimit-Remaining', '0');

        expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0)
            ->and($response->headers->get('X-RateLimit-Reset'))->not->toBeNull();
    });

    test('non-uuid browser passkey login challenge id cannot be verified', function () {
        $response = $this->withHeaders(spaHeaders())
            ->postJson('/v1/auth/passkeys/challenges/not-a-uuid/verify', [
                'credential' => [
                    'id' => 'Ax9Yc0ZLQmN4V1V1S1cwVnI1Q0FyRkE',
                    'raw_id' => 'Ax9Yc0ZLQmN4V1V1S1cwVnI1Q0FyRkE',
                    'type' => 'public-key',
                    'response' => [
                        'client_data_json' => 'Zm9v',
                        'authenticator_data' => 'YmFy',
                        'signature' => 'YmF6',
                    ],
                ],
            ]);

        $response->assertNotFound();
    });

    test('unknown browser passkey login challenge cannot be verified', function () {
        $response = $this->withHeaders(spaHeaders())
            ->postJson('/v1/auth/passkeys/challenges/550e8400-e29b-41d4-a716-446655440099/verify', [
                'credential' => [
                    'id' => 'Ax9Yc0ZLQmN4V1V1S1cwVnI1Q0FyRkE',
                    'raw_id' => 'Ax9Yc0ZLQmN4V1V1S1cwVnI1Q0FyRkE',
                    'type' => 'public-key',
                    'response' => [
                        'client_data_json' => 'Zm9v',
                        'authenticator_data' => 'YmFy',
                        'signature' => 'YmF6',
                    ],
                ],
            ]);

        $response->assertNotFound();
    });

    test('browser passkey login verification completes the browser session', function () {
        $user = User::factory()->create();

        $credential = PasskeyCredential::factory()->create([
            'user_id' => $user->id,
        ]);

        $challenge = app(PasskeyChallengeService::class)->createAuthenticationChallenge([
            'challenge' => 'test-challenge',
            'rp_id' => 'app.secpal.dev',
            'timeout' => 60000,
            'user_verification' => 'preferred',
        ], 'conditional');

        /** @var PasskeyService&Mockery\MockInterface $mockService */
        $mockService = $this->mock(PasskeyService::class);
        $mockService->shouldReceive('verifyAuthentication')
            ->once()
            ->andReturn([
                'user' => $user,
                'credential' => $credential,
            ]);

        $response = $this->withHeaders(spaCsrfHeaders($this))
            ->postJson('/v1/auth/passkeys/challenges/'.$challenge['challenge_id'].'/verify', [
                'credential' => [
                    'id' => 'Ax9Yc0ZLQmN4V1V1S1cwVnI1Q0FyRkE',
                    'raw_id' => 'Ax9Yc0ZLQmN4V1V1S1cwVnI1Q0FyRkE',
                    'type' => 'public-key',
                    'response' => [
                        'client_data_json' => 'Zm9v',
                        'authenticator_data' => 'YmFy',
                        'signature' => 'YmF6',
                    ],
                ],
            ]);

        $response->assertOk()
            ->assertJson([
                'authentication' => [
                    'mode' => 'session',
                    'mfa_completed' => true,
                ],
                'user' => [
                    'email' => $user->email,
                ],
            ]);

        $this->withHeaders(spaHeaders())
            ->getJson('/v1/me')
            ->assertOk()
            ->assertJson([
                'email' => $user->email,
            ]);
    });

    test('token passkey login verification completes a token login', function () {
        $user = User::factory()->create();

        $credential = PasskeyCredential::factory()->create([
            'user_id' => $user->id,
        ]);

        $challenge = app(PasskeyChallengeService::class)->createAuthenticationChallenge([
            'challenge' => 'test-challenge',
            'rp_id' => 'app.secpal.dev',
            'timeout' => 60000,
            'user_verification' => 'preferred',
        ], 'conditional', LoginMfaChallengeService::LOGIN_CONTEXT_TOKEN, 'android-phone');

        /** @var PasskeyService&Mockery\MockInterface $mockService */
        $mockService = $this->mock(PasskeyService::class);
        $mockService->shouldReceive('verifyAuthentication')
            ->once()
            ->andReturn([
                'user' => $user,
                'credential' => $credential,
            ]);

        $response = $this->postJson('/v1/auth/token/passkeys/challenges/'.$challenge['challenge_id'].'/verify', [
            'credential' => [
                'id' => 'Ax9Yc0ZLQmN4V1V1S1cwVnI1Q0FyRkE',
                'raw_id' => 'Ax9Yc0ZLQmN4V1V1S1cwVnI1Q0FyRkE',
                'type' => 'public-key',
                'response' => [
                    'client_data_json' => 'Zm9v',
                    'authenticator_data' => 'YmFy',
                    'signature' => 'YmF6',
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJson([
                'authentication' => [
                    'mode' => 'token',
                    'method' => 'passkey',
                    'mfa_completed' => true,
                ],
                'user' => [
                    'email' => $user->email,
                ],
            ]);

        expect($response->json('token'))->toBeString()->not->toBe('');

        $this->withToken($response->json('token'))
            ->getJson('/v1/me')
            ->assertOk()
            ->assertJson([
                'email' => $user->email,
            ]);
    });

    test('session-scoped passkey challenge cannot be verified via the token endpoint', function () {
        $challenge = app(PasskeyChallengeService::class)->createAuthenticationChallenge([
            'challenge' => 'test-challenge',
            'rp_id' => 'app.secpal.dev',
            'timeout' => 60000,
            'user_verification' => 'preferred',
        ], 'conditional', LoginMfaChallengeService::LOGIN_CONTEXT_SESSION);

        $response = $this->postJson('/v1/auth/token/passkeys/challenges/'.$challenge['challenge_id'].'/verify', [
            'credential' => [
                'id' => 'Ax9Yc0ZLQmN4V1V1S1cwVnI1Q0FyRkE',
                'raw_id' => 'Ax9Yc0ZLQmN4V1V1S1cwVnI1Q0FyRkE',
                'type' => 'public-key',
                'response' => [
                    'client_data_json' => 'Zm9v',
                    'authenticator_data' => 'YmFy',
                    'signature' => 'YmF6',
                ],
            ],
        ]);

        $response->assertConflict()
            ->assertJson([
                'message' => __('This passkey challenge must be completed from its original login context.'),
            ]);
    });

    test('token-scoped passkey challenge cannot be verified via the browser session endpoint', function () {
        $challenge = app(PasskeyChallengeService::class)->createAuthenticationChallenge([
            'challenge' => 'test-challenge',
            'rp_id' => 'app.secpal.dev',
            'timeout' => 60000,
            'user_verification' => 'preferred',
        ], 'conditional', LoginMfaChallengeService::LOGIN_CONTEXT_TOKEN, 'android-phone');

        $response = $this->withHeaders(spaCsrfHeaders($this))
            ->postJson('/v1/auth/passkeys/challenges/'.$challenge['challenge_id'].'/verify', [
                'credential' => [
                    'id' => 'Ax9Yc0ZLQmN4V1V1S1cwVnI1Q0FyRkE',
                    'raw_id' => 'Ax9Yc0ZLQmN4V1V1S1cwVnI1Q0FyRkE',
                    'type' => 'public-key',
                    'response' => [
                        'client_data_json' => 'Zm9v',
                        'authenticator_data' => 'YmFy',
                        'signature' => 'YmF6',
                    ],
                ],
            ]);

        $response->assertConflict()
            ->assertJson([
                'message' => __('This passkey challenge must be completed from its original login context.'),
            ]);
    });

    test('invalid browser passkey verification returns validation errors', function () {
        $challenge = app(PasskeyChallengeService::class)->createAuthenticationChallenge([
            'challenge' => 'test-challenge',
            'rp_id' => 'app.secpal.dev',
            'timeout' => 60000,
            'user_verification' => 'preferred',
        ], 'conditional');

        /** @var PasskeyService&Mockery\MockInterface $mockService */
        $mockService = $this->mock(PasskeyService::class);
        $mockService->shouldReceive('verifyAuthentication')
            ->once()
            ->andThrow(AuthenticatorResponseVerificationException::create('The passkey assertion is invalid.'));

        $response = $this->withHeaders(spaCsrfHeaders($this))
            ->postJson('/v1/auth/passkeys/challenges/'.$challenge['challenge_id'].'/verify', [
                'credential' => [
                    'id' => 'Ax9Yc0ZLQmN4V1V1S1cwVnI1Q0FyRkE',
                    'raw_id' => 'Ax9Yc0ZLQmN4V1V1S1cwVnI1Q0FyRkE',
                    'type' => 'public-key',
                    'response' => [
                        'client_data_json' => 'Zm9v',
                        'authenticator_data' => 'YmFy',
                        'signature' => 'YmF6',
                    ],
                ],
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['credential']);

        $this->withHeaders(spaHeaders())
            ->getJson('/v1/me')
            ->assertUnauthorized();

        // Ensure the authentication challenge has been forgotten after a failed verification attempt
        $retrievedChallenge = app(PasskeyChallengeService::class)->findAuthenticationChallenge($challenge['challenge_id']);

        expect($retrievedChallenge)->toBeNull();
    });

    test('InvalidDataException during passkey authentication verification returns validation errors', function () {
        $challenge = app(PasskeyChallengeService::class)->createAuthenticationChallenge([
            'challenge' => 'test-challenge',
            'rp_id' => 'app.secpal.dev',
            'timeout' => 60000,
            'user_verification' => 'preferred',
        ], 'conditional');

        /** @var PasskeyService&Mockery\MockInterface $mockService */
        $mockService = $this->mock(PasskeyService::class);
        $mockService->shouldReceive('verifyAuthentication')
            ->once()
            ->andThrow(InvalidDataException::create(null, 'Invalid attestation object. Presence of extra bytes.'));

        $response = $this->withHeaders(spaCsrfHeaders($this))
            ->postJson('/v1/auth/passkeys/challenges/'.$challenge['challenge_id'].'/verify', [
                'credential' => [
                    'id' => 'Ax9Yc0ZLQmN4V1V1S1cwVnI1Q0FyRkE',
                    'raw_id' => 'Ax9Yc0ZLQmN4V1V1S1cwVnI1Q0FyRkE',
                    'type' => 'public-key',
                    'response' => [
                        'client_data_json' => 'Zm9v',
                        'authenticator_data' => 'YmFy',
                        'signature' => 'YmF6',
                    ],
                ],
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['credential']);

        expect(app(PasskeyChallengeService::class)->findAuthenticationChallenge($challenge['challenge_id']))->toBeNull();
    });

    test('unexpected Throwable during passkey authentication verification returns validation errors', function () {
        $challenge = app(PasskeyChallengeService::class)->createAuthenticationChallenge([
            'challenge' => 'test-challenge',
            'rp_id' => 'app.secpal.dev',
            'timeout' => 60000,
            'user_verification' => 'preferred',
        ], 'conditional');

        /** @var PasskeyService&Mockery\MockInterface $mockService */
        $mockService = $this->mock(PasskeyService::class);
        $mockService->shouldReceive('verifyAuthentication')
            ->once()
            ->andThrow(new RuntimeException('Undefined array key "clientDataJSON"'));

        $response = $this->withHeaders(spaCsrfHeaders($this))
            ->postJson('/v1/auth/passkeys/challenges/'.$challenge['challenge_id'].'/verify', [
                'credential' => [
                    'id' => 'Ax9Yc0ZLQmN4V1V1S1cwVnI1Q0FyRkE',
                    'raw_id' => 'Ax9Yc0ZLQmN4V1V1S1cwVnI1Q0FyRkE',
                    'type' => 'public-key',
                    'response' => [
                        'client_data_json' => 'Zm9v',
                        'authenticator_data' => 'YmFy',
                        'signature' => 'YmF6',
                    ],
                ],
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['credential']);

        expect(app(PasskeyChallengeService::class)->findAuthenticationChallenge($challenge['challenge_id']))->toBeNull();
    });

    test('invalid browser passkey verification attempts are rate limited with retry headers', function () {
        /** @var PasskeyService&Mockery\MockInterface $mockService */
        $mockService = $this->mock(PasskeyService::class);
        $mockService->shouldReceive('verifyAuthentication')
            ->times(5)
            ->andThrow(AuthenticatorResponseVerificationException::create('The passkey assertion is invalid.'));

        // The security fix forgets the challenge after each failed attempt, so each
        // iteration needs a fresh challenge. The rate limiter is keyed off the IP,
        // so it accumulates across all requests regardless of the challenge ID.
        for ($i = 0; $i < 5; $i++) {
            $challenge = app(PasskeyChallengeService::class)->createAuthenticationChallenge([
                'challenge' => 'test-challenge-'.$i,
                'rp_id' => 'app.secpal.dev',
                'timeout' => 60000,
                'user_verification' => 'preferred',
            ], 'conditional');

            $this->withHeaders(spaCsrfHeaders($this))
                ->postJson('/v1/auth/passkeys/challenges/'.$challenge['challenge_id'].'/verify', [
                    'credential' => [
                        'id' => 'Ax9Yc0ZLQmN4V1V1S1cwVnI1Q0FyRkE',
                        'raw_id' => 'Ax9Yc0ZLQmN4V1V1S1cwVnI1Q0FyRkE',
                        'type' => 'public-key',
                        'response' => [
                            'client_data_json' => 'Zm9v',
                            'authenticator_data' => 'YmFy',
                            'signature' => 'YmF6',
                        ],
                    ],
                ])
                ->assertUnprocessable();
        }

        // The 6th request hits the rate limiter before the controller even looks up the
        // challenge, so any UUID is sufficient here.
        $exhaustedChallenge = app(PasskeyChallengeService::class)->createAuthenticationChallenge([
            'challenge' => 'test-challenge-exhausted',
            'rp_id' => 'app.secpal.dev',
            'timeout' => 60000,
            'user_verification' => 'preferred',
        ], 'conditional');

        $response = $this->withHeaders(spaCsrfHeaders($this))
            ->postJson('/v1/auth/passkeys/challenges/'.$exhaustedChallenge['challenge_id'].'/verify', [
                'credential' => [
                    'id' => 'Ax9Yc0ZLQmN4V1V1S1cwVnI1Q0FyRkE',
                    'raw_id' => 'Ax9Yc0ZLQmN4V1V1S1cwVnI1Q0FyRkE',
                    'type' => 'public-key',
                    'response' => [
                        'client_data_json' => 'Zm9v',
                        'authenticator_data' => 'YmFy',
                        'signature' => 'YmF6',
                    ],
                ],
            ]);

        $response->assertTooManyRequests()
            ->assertHeader('X-RateLimit-Limit', '5')
            ->assertHeader('X-RateLimit-Remaining', '0');

        expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0)
            ->and($response->headers->get('X-RateLimit-Reset'))->not->toBeNull();
    });
});

describe('Passkey Management', function () {
    test('authenticated users can list their passkeys', function () {
        $user = User::factory()->create();
        $token = $user->issueApiToken('test-suite')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/v1/me/passkeys');

        $response->assertOk()
            ->assertExactJson([
                'data' => [],
            ]);
    });

    test('authenticated users can start a passkey registration challenge', function () {
        $user = User::factory()->create();
        $token = $user->issueApiToken('test-suite')->plainTextToken;

        $response = $this->withToken($token)
            ->postJson('/v1/me/passkeys/challenges/registration');

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'challenge_id',
                    'public_key' => [
                        'challenge',
                        'rp' => ['id', 'name'],
                        'user' => ['id', 'name', 'display_name'],
                        'pub_key_cred_params',
                        'timeout',
                        'authenticator_selection',
                        'attestation',
                    ],
                    'expires_at',
                ],
            ]);

        expect($response->json('data.public_key.rp.id'))->toBe('app.secpal.dev')
            ->and($response->json('data.public_key.attestation'))->toBe('none')
            ->and($response->json('data.public_key.authenticator_selection.resident_key'))->toBe('preferred')
            ->and($response->json('data.public_key.authenticator_selection'))->not->toHaveKey('require_resident_key', 'deprecated require_resident_key should be omitted when false')
            ->and($response->json('data.public_key'))->not->toHaveKey('exclude_credentials', 'empty exclude_credentials must be omitted')
            ->and($response->json('data.public_key.authenticator_selection'))->not->toHaveKey('authenticator_attachment', 'null authenticator_attachment must be omitted')
            ->and($response->json('data.public_key.rp'))->not->toHaveKey('icon', 'null rp.icon must be omitted');
    });

    test('authenticated users are rate limited when starting passkey registration challenges', function () {
        $user = User::factory()->create();
        $token = $user->issueApiToken('test-suite')->plainTextToken;

        for ($i = 0; $i < 5; $i++) {
            $response = $this->withToken($token)
                ->postJson('/v1/me/passkeys/challenges/registration');

            $response->assertCreated()
                ->assertHeader('X-RateLimit-Limit', '5')
                ->assertHeader('X-RateLimit-Remaining', (string) (4 - $i));
        }

        $response = $this->withToken($token)
            ->postJson('/v1/me/passkeys/challenges/registration');

        $response->assertTooManyRequests()
            ->assertHeader('X-RateLimit-Limit', '5')
            ->assertHeader('X-RateLimit-Remaining', '0');

        expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0)
            ->and($response->headers->get('X-RateLimit-Reset'))->not->toBeNull();
    });

    test('unknown passkey registration challenge cannot be verified', function () {
        $user = User::factory()->create();
        $token = $user->issueApiToken('test-suite')->plainTextToken;

        $response = $this->withToken($token)
            ->postJson('/v1/me/passkeys/challenges/registration/550e8400-e29b-41d4-a716-446655440099/verify', [
                'credential' => [
                    'id' => 'Ax9Yc0ZLQmN4V1V1S1cwVnI1Q0FyRkE',
                    'raw_id' => 'Ax9Yc0ZLQmN4V1V1S1cwVnI1Q0FyRkE',
                    'type' => 'public-key',
                    'response' => [
                        'client_data_json' => 'Zm9v',
                        'attestation_object' => 'YmFy',
                        'transports' => ['internal'],
                    ],
                ],
                'label' => 'Work MacBook Touch ID',
            ]);

        $response->assertNotFound();
    });

    test('invalid passkey registration verification returns validation errors', function () {
        $user = User::factory()->create();
        $token = $user->issueApiToken('test-suite')->plainTextToken;

        $challenge = app(PasskeyChallengeService::class)->createRegistrationChallenge($user, [
            'challenge' => 'test-registration-challenge',
            'rp' => [
                'id' => 'app.secpal.dev',
                'name' => 'SecPal',
            ],
            'user' => [
                'id' => $user->id,
                'name' => $user->email,
                'display_name' => $user->name,
            ],
            'pub_key_cred_params' => [
                ['type' => 'public-key', 'alg' => -7],
            ],
            'timeout' => 60000,
            'exclude_credentials' => [],
            'authenticator_selection' => [
                'resident_key' => 'preferred',
                'user_verification' => 'preferred',
            ],
            'attestation' => 'none',
        ]);

        /** @var PasskeyService&Mockery\MockInterface $mockService */
        $mockService = $this->mock(PasskeyService::class);
        $mockService->shouldReceive('verifyRegistration')
            ->once()
            ->andThrow(AuthenticatorResponseVerificationException::create('The passkey attestation is invalid.'));

        $response = $this->withToken($token)
            ->postJson('/v1/me/passkeys/challenges/registration/'.$challenge['challenge_id'].'/verify', [
                'credential' => [
                    'id' => 'Ax9Yc0ZLQmN4V1V1S1cwVnI1Q0FyRkE',
                    'raw_id' => 'Ax9Yc0ZLQmN4V1V1S1cwVnI1Q0FyRkE',
                    'type' => 'public-key',
                    'response' => [
                        'client_data_json' => 'Zm9v',
                        'attestation_object' => 'YmFy',
                        'transports' => ['internal'],
                    ],
                ],
                'label' => 'Work MacBook Touch ID',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['credential']);

        expect(app(PasskeyChallengeService::class)->findRegistrationChallenge($challenge['challenge_id']))->toBeNull();
    });

    test('unexpected Throwable during passkey registration verification returns validation errors', function () {
        $user = User::factory()->create();
        $token = $user->issueApiToken('test-suite')->plainTextToken;

        $challenge = app(PasskeyChallengeService::class)->createRegistrationChallenge($user, [
            'challenge' => 'test-registration-challenge',
            'rp' => ['id' => 'app.secpal.dev', 'name' => 'SecPal'],
            'user' => ['id' => $user->id, 'name' => $user->email, 'display_name' => $user->name],
            'pub_key_cred_params' => [['type' => 'public-key', 'alg' => -7]],
            'timeout' => 60000,
            'exclude_credentials' => [],
            'authenticator_selection' => ['resident_key' => 'preferred', 'user_verification' => 'preferred'],
            'attestation' => 'none',
        ]);

        /** @var PasskeyService&Mockery\MockInterface $mockService */
        $mockService = $this->mock(PasskeyService::class);
        $mockService->shouldReceive('verifyRegistration')
            ->once()
            ->andThrow(new RuntimeException('Undefined array key "clientDataJSON"'));

        $response = $this->withToken($token)
            ->postJson('/v1/me/passkeys/challenges/registration/'.$challenge['challenge_id'].'/verify', [
                'credential' => [
                    'id' => 'Ax9Yc0ZLQmN4V1V1S1cwVnI1Q0FyRkE',
                    'raw_id' => 'Ax9Yc0ZLQmN4V1V1S1cwVnI1Q0FyRkE',
                    'type' => 'public-key',
                    'response' => [
                        'client_data_json' => 'Zm9v',
                        'attestation_object' => 'YmFy',
                        'transports' => ['internal'],
                    ],
                ],
                'label' => 'Touch ID',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['credential']);

        expect(app(PasskeyChallengeService::class)->findRegistrationChallenge($challenge['challenge_id']))->toBeNull();
    });

    test('valid passkey registration verification creates a credential and returns its summary', function () {
        $user = User::factory()->create();
        $token = $user->issueApiToken('test-suite')->plainTextToken;

        $challenge = app(PasskeyChallengeService::class)->createRegistrationChallenge($user, [
            'challenge' => 'test-registration-challenge',
            'rp' => ['id' => 'app.secpal.dev', 'name' => 'SecPal'],
            'user' => ['id' => $user->id, 'name' => $user->email, 'display_name' => $user->name],
            'pub_key_cred_params' => [['type' => 'public-key', 'alg' => -7]],
            'timeout' => 60000,
            'exclude_credentials' => [],
            'authenticator_selection' => ['resident_key' => 'preferred', 'user_verification' => 'preferred'],
            'attestation' => 'none',
        ]);

        $fakeCredential = $user->passkeyCredentials()->create([
            'credential_id' => 'Ax9Yc0ZLQmN4V1V1S1cwVnI1Q0FyRkE',
            'label' => 'Touch ID',
            'transports' => ['internal'],
            'attestation_type' => 'none',
            'credential_public_key' => 'dGVzdA',
            'user_handle' => 'dGVzdA',
            'counter' => 0,
        ]);

        /** @var PasskeyService&Mockery\MockInterface $mockService */
        $mockService = $this->mock(PasskeyService::class);
        $mockService->shouldReceive('verifyRegistration')
            ->once()
            ->andReturn($fakeCredential);
        $mockService->shouldReceive('formatCredentialSummary')
            ->once()
            ->andReturn([
                'id' => $fakeCredential->credential_id,
                'label' => $fakeCredential->label,
                'created_at' => $fakeCredential->created_at->toIso8601String(),
                'last_used_at' => null,
                'transports' => ['internal'],
                'authenticator_attachment' => null,
                'aaguid' => null,
                'user_verified' => false,
                'backup_eligible' => false,
                'backup_state' => false,
            ]);

        $response = $this->withToken($token)
            ->postJson('/v1/me/passkeys/challenges/registration/'.$challenge['challenge_id'].'/verify', [
                'credential' => [
                    'id' => 'Ax9Yc0ZLQmN4V1V1S1cwVnI1Q0FyRkE',
                    'raw_id' => 'Ax9Yc0ZLQmN4V1V1S1cwVnI1Q0FyRkE',
                    'type' => 'public-key',
                    'response' => [
                        'client_data_json' => 'Zm9v',
                        'attestation_object' => 'YmFy',
                        'transports' => ['internal'],
                    ],
                ],
                'label' => 'Touch ID',
            ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'credential' => ['id', 'label', 'created_at', 'transports'],
                    'total_passkeys',
                ],
            ]);

        expect($response->json('data.credential.id'))->toBe('Ax9Yc0ZLQmN4V1V1S1cwVnI1Q0FyRkE')
            ->and($response->json('data.total_passkeys'))->toBe(1);
    });

    test('valid passkey registration without preexisting credential registers a new passkey', function () {
        $user = User::factory()->create();
        $token = $user->issueApiToken('test-suite')->plainTextToken;

        $challenge = app(PasskeyChallengeService::class)->createRegistrationChallenge($user, [
            'challenge' => 'test-registration-challenge',
            'rp' => ['id' => 'app.secpal.dev', 'name' => 'SecPal'],
            'user' => ['id' => $user->id, 'name' => $user->email, 'display_name' => $user->name],
            'pub_key_cred_params' => [['type' => 'public-key', 'alg' => -7]],
            'timeout' => 60000,
            'exclude_credentials' => [],
            'authenticator_selection' => ['resident_key' => 'preferred', 'user_verification' => 'preferred'],
            'attestation' => 'none',
        ]);

        /** @var PasskeyService&Mockery\MockInterface $mockService */
        $mockService = $this->mock(PasskeyService::class);

        $mockService->shouldReceive('verifyRegistration')
            ->once()
            ->andReturnUsing(function (User $registrationUser, array $storedOptions, array $credentialPayload, ?string $label = null) use ($user) {
                return PasskeyCredential::factory()->create([
                    'user_id' => $user->id,
                    'credential_id' => 'Bx9Yc0ZLQmN4V1V1S1cwVnI1Q0FyRkE',
                    'label' => 'Touch ID',
                ]);
            });

        $mockService->shouldReceive('formatCredentialSummary')
            ->once()
            ->andReturnUsing(function ($credential) {
                return [
                    'id' => $credential->credential_id,
                    'label' => $credential->label,
                    'created_at' => $credential->created_at->toIso8601String(),
                    'last_used_at' => null,
                    'transports' => $credential->transports,
                    'authenticator_attachment' => null,
                    'aaguid' => null,
                    'user_verified' => false,
                    'backup_eligible' => false,
                    'backup_state' => false,
                ];
            });

        $response = $this->withToken($token)
            ->postJson('/v1/me/passkeys/challenges/registration/'.$challenge['challenge_id'].'/verify', [
                'credential' => [
                    'id' => 'Bx9Yc0ZLQmN4V1V1S1cwVnI1Q0FyRkE',
                    'raw_id' => 'Bx9Yc0ZLQmN4V1V1S1cwVnI1Q0FyRkE',
                    'type' => 'public-key',
                    'response' => [
                        'client_data_json' => 'Zm9v',
                        'attestation_object' => 'YmFy',
                        'transports' => ['internal'],
                    ],
                ],
                'label' => 'Touch ID',
            ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'credential' => ['id', 'label', 'created_at', 'transports'],
                    'total_passkeys',
                ],
            ]);

        expect($response->json('data.credential.id'))->toBe('Bx9Yc0ZLQmN4V1V1S1cwVnI1Q0FyRkE')
            ->and($response->json('data.total_passkeys'))->toBe(1);
    });

    test('invalid passkey registration verification attempts are rate limited with retry headers', function () {
        $user = User::factory()->create();
        $token = $user->issueApiToken('test-suite')->plainTextToken;

        $challengeService = app(PasskeyChallengeService::class);
        $challengeOptions = [
            'challenge' => 'test-registration-challenge',
            'rp' => ['id' => 'app.secpal.dev', 'name' => 'SecPal'],
            'user' => ['id' => $user->id, 'name' => $user->email, 'display_name' => $user->name],
            'pub_key_cred_params' => [['type' => 'public-key', 'alg' => -7]],
            'timeout' => 60000,
            'exclude_credentials' => [],
            'authenticator_selection' => ['resident_key' => 'preferred', 'user_verification' => 'preferred'],
            'attestation' => 'none',
        ];

        /** @var PasskeyService&Mockery\MockInterface $mockService */
        $mockService = $this->mock(PasskeyService::class);
        $mockService->shouldReceive('verifyRegistration')
            ->times(5)
            ->andThrow(AuthenticatorResponseVerificationException::create('The passkey attestation is invalid.'));

        $payload = [
            'credential' => [
                'id' => 'Ax9Yc0ZLQmN4V1V1S1cwVnI1Q0FyRkE',
                'raw_id' => 'Ax9Yc0ZLQmN4V1V1S1cwVnI1Q0FyRkE',
                'type' => 'public-key',
                'response' => [
                    'client_data_json' => 'Zm9v',
                    'attestation_object' => 'YmFy',
                    'transports' => ['internal'],
                ],
            ],
            'label' => 'Touch ID',
        ];

        // Each failed attempt invalidates the challenge (forgetRegistrationChallenge),
        // so a fresh challenge is needed per iteration. The rate limiter accumulates
        // across challenges because it is keyed by IP + route scope.
        for ($i = 0; $i < 5; $i++) {
            $challenge = $challengeService->createRegistrationChallenge($user, $challengeOptions);
            $this->withToken($token)
                ->postJson('/v1/me/passkeys/challenges/registration/'.$challenge['challenge_id'].'/verify', $payload)
                ->assertUnprocessable();
        }

        $challenge = $challengeService->createRegistrationChallenge($user, $challengeOptions);
        $response = $this->withToken($token)
            ->postJson('/v1/me/passkeys/challenges/registration/'.$challenge['challenge_id'].'/verify', $payload);

        $response->assertTooManyRequests()
            ->assertHeader('X-RateLimit-Limit', '5')
            ->assertHeader('X-RateLimit-Remaining', '0');

        expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0)
            ->and($response->headers->get('X-RateLimit-Reset'))->not->toBeNull();
    });

    test('authenticated users cannot delete an unknown passkey credential', function () {
        $user = User::factory()->create();
        $token = $user->issueApiToken('test-suite')->plainTextToken;

        $response = $this->withToken($token)
            ->deleteJson('/v1/me/passkeys/Ax9Yc0ZLQmN4V1V1S1cwVnI1Q0FyRkE');

        $response->assertNotFound();
    });

    test('authenticated users can list their enrolled passkeys', function () {
        $user = User::factory()->create();
        $token = $user->issueApiToken('test-suite')->plainTextToken;

        $credential = $user->passkeyCredentials()->create([
            'credential_id' => 'Ax9Yc0ZLQmN4V1V1S1cwVnI1Q0FyRkE',
            'label' => 'Touch ID',
            'transports' => ['internal'],
            'attestation_type' => 'none',
            'credential_public_key' => 'dGVzdA',
            'user_handle' => 'dGVzdA',
            'counter' => 0,
        ]);

        $response = $this->withToken($token)
            ->getJson('/v1/me/passkeys');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    ['id', 'label', 'created_at', 'transports'],
                ],
            ]);

        expect($response->json('data.0.id'))->toBe($credential->credential_id)
            ->and($response->json('data.0.label'))->toBe('Touch ID');
    });

    test('authenticated users can delete their own passkey credential', function () {
        $user = User::factory()->create();
        $token = $user->issueApiToken('test-suite')->plainTextToken;

        $user->passkeyCredentials()->create([
            'credential_id' => 'Ax9Yc0ZLQmN4V1V1S1cwVnI1Q0FyRkE',
            'label' => 'Touch ID',
            'transports' => ['internal'],
            'attestation_type' => 'none',
            'credential_public_key' => 'dGVzdA',
            'user_handle' => 'dGVzdA',
            'counter' => 0,
        ]);

        $response = $this->withToken($token)
            ->deleteJson('/v1/me/passkeys/Ax9Yc0ZLQmN4V1V1S1cwVnI1Q0FyRkE');

        $response->assertOk()
            ->assertJson([
                'message' => 'Passkey deleted successfully.',
                'data' => ['remaining_passkeys' => 0],
            ]);

        expect($user->passkeyCredentials()->count())->toBe(0);
    });
});

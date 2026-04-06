<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\User;
use App\Services\PasskeyChallengeService;
use App\Services\PasskeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Webauthn\Exception\AuthenticatorResponseVerificationException;

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
            ->and($response->json('data.mediation'))->toBe('conditional');
    });

    test('browser passkey login challenge creation is rate limited with retry headers', function () {
        for ($i = 0; $i < 5; $i++) {
            $this->withHeaders(spaHeaders())
                ->postJson('/v1/auth/passkeys/challenges')
                ->assertCreated();
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
                'credential' => new App\Models\PasskeyCredential,
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
    });

    test('invalid browser passkey verification attempts are rate limited with retry headers', function () {
        $challenge = app(PasskeyChallengeService::class)->createAuthenticationChallenge([
            'challenge' => 'test-challenge',
            'rp_id' => 'app.secpal.dev',
            'timeout' => 60000,
            'user_verification' => 'preferred',
        ], 'conditional');

        /** @var PasskeyService&Mockery\MockInterface $mockService */
        $mockService = $this->mock(PasskeyService::class);
        $mockService->shouldReceive('verifyAuthentication')
            ->times(5)
            ->andThrow(AuthenticatorResponseVerificationException::create('The passkey assertion is invalid.'));

        for ($i = 0; $i < 5; $i++) {
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
                        'exclude_credentials',
                        'authenticator_selection',
                        'attestation',
                    ],
                    'expires_at',
                ],
            ]);

        expect($response->json('data.public_key.rp.id'))->toBe('app.secpal.dev')
            ->and($response->json('data.public_key.attestation'))->toBe('none');
    });

    test('authenticated users are rate limited when starting passkey registration challenges', function () {
        $user = User::factory()->create();
        $token = $user->issueApiToken('test-suite')->plainTextToken;

        for ($i = 0; $i < 5; $i++) {
            $this->withToken($token)
                ->postJson('/v1/me/passkeys/challenges/registration')
                ->assertCreated();
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

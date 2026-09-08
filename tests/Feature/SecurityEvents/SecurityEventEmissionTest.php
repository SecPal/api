<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Contracts\SecurityEventEmitter;
use App\Models\User;
use App\SecurityEvents\SecurityEvent;
use App\Services\BoundedSecurityEventEmitter;
use App\Services\PasskeyChallengeService;
use App\Services\PasskeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use Webauthn\Exception\AuthenticatorResponseVerificationException;

uses(RefreshDatabase::class);

/**
 * @param  list<SecurityEvent>  $events
 */
function captureSecurityEvents(array &$events): void
{
    $emitter = Mockery::mock(SecurityEventEmitter::class);
    $emitter->shouldReceive('emit')->andReturnUsing(function (SecurityEvent $event) use (&$events): void {
        $events[] = $event;
    });

    app()->instance(SecurityEventEmitter::class, $emitter);
}

it('emits one privacy-minimized event for a denied primary credential decision', function (): void {
    User::factory()->create([
        'email' => 'person@example.test',
        'password' => Hash::make('CorrectPassword123!'),
    ]);

    $events = [];
    captureSecurityEvents($events);

    $response = $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
        ->postJson('/v1/auth/token', [
            'email' => 'person@example.test',
            'password' => 'WrongPassword123!',
            'device_name' => 'fixture-device',
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);

    expect($events)->toHaveCount(1)
        ->and($events[0]->toArray())
        ->toMatchArray([
            'schema_version' => 1,
            'event_name' => 'authentication.failed',
            'outcome' => 'failure',
            'reason' => 'invalid_credentials',
            'source_ip' => '192.0.2.10',
            'metadata' => [
                'authentication_method' => 'password',
                'login_context' => 'token',
            ],
        ]);
});

it('excludes raw PII secrets headers cookies and request bodies from serialized events', function (): void {
    $email = 'sensitive-person@example.test';
    $password = 'SyntheticPassword123!';
    $bearer = 'synthetic-bearer-token-value';
    $cookie = 'synthetic-session-cookie-value';
    $resetToken = 'synthetic-reset-token-value';
    $mfaSecret = 'JBSWY3DPEHPK3PXP';
    $bodyMarker = 'synthetic-request-body-marker';
    $requestId = 'caller-controlled-request-id';

    User::factory()->create([
        'email' => $email,
        'password' => Hash::make('DifferentPassword123!'),
    ]);

    $events = [];
    captureSecurityEvents($events);

    $this->withHeaders([
        'Authorization' => 'Bearer '.$bearer,
        'Cookie' => 'secpal_session='.$cookie,
        'X-Reset-Token' => $resetToken,
        'X-MFA-Secret' => $mfaSecret,
        'X-Request-ID' => $requestId,
    ])->postJson('/v1/auth/token', [
        'email' => $email,
        'username' => 'synthetic-username',
        'password' => $password,
        'request_body_marker' => $bodyMarker,
    ])->assertUnprocessable();

    expect($events)->toHaveCount(1);

    $serialized = $events[0]->toJson();

    foreach ([$email, 'synthetic-username', $password, $bearer, $cookie, $resetToken, $mfaSecret, $bodyMarker, $requestId] as $forbidden) {
        expect($serialized)->not->toContain($forbidden);
    }
});

it('uses framework trusted-client address resolution and rejects forwarding spoofing', function (): void {
    config()->set('trustedproxy.proxies', []);

    $events = [];
    captureSecurityEvents($events);

    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.44'])
        ->withHeaders([
            'X-Forwarded-For' => '198.51.100.99',
            'X-Real-IP' => '198.51.100.98',
            'Forwarded' => 'for=198.51.100.97',
        ])->postJson('/v1/auth/token', [
            'email' => 'unknown@example.test',
            'password' => 'WrongPassword123!',
        ])->assertUnprocessable();

    expect($events)->toHaveCount(1)
        ->and($events[0]->toArray()['source_ip'])->toBe('192.0.2.44');
});

it('accepts forwarded client addresses only from configured trusted proxies', function (): void {
    config()->set('trustedproxy.proxies', ['10.0.0.10']);

    $events = [];
    captureSecurityEvents($events);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.10'])
        ->withHeaders(['X-Forwarded-For' => '198.51.100.42'])
        ->postJson('/v1/auth/token', [
            'email' => 'unknown@example.test',
            'password' => 'WrongPassword123!',
        ])->assertUnprocessable();

    expect($events)->toHaveCount(1)
        ->and($events[0]->toArray()['source_ip'])->toBe('198.51.100.42');
});

it('emits bounded MFA reset-token and bearer-token rejection semantics', function (): void {
    $events = [];
    captureSecurityEvents($events);

    $this->withToken('synthetic-invalid-bearer-token')
        ->getJson('/v1/me')
        ->assertUnauthorized();

    $this->postJson('/v1/auth/password/reset', [
        'email' => 'missing@example.test',
        'token' => 'synthetic-invalid-reset-token',
        'password' => 'ReplacementPassword123!',
        'password_confirmation' => 'ReplacementPassword123!',
    ])->assertBadRequest()
        ->assertExactJson(['message' => 'Invalid or expired reset token']);

    expect($events)->toHaveCount(2)
        ->and($events[0]->toArray())->not->toHaveKey('actor_reference')
        ->toMatchArray([
            'event_name' => 'authentication.token_rejected',
            'outcome' => 'denied',
            'reason' => 'invalid_or_expired',
            'metadata' => ['authentication_method' => 'bearer_token'],
        ])
        ->and($events[1]->toArray())->toMatchArray([
            'event_name' => 'password_reset.token_rejected',
            'outcome' => 'denied',
            'reason' => 'invalid_or_expired',
            'metadata' => ['reset_phase' => 'confirmation'],
        ]);
});

it('emits a distinct MFA failure at the final verification decision', function (): void {
    $user = User::factory()->create([
        'email' => 'mfa-person@example.test',
        'password' => Hash::make('CorrectPassword123!'),
    ]);
    $user->createTwoFactorAuth();
    expect($user->confirmTwoFactorAuth($user->makeTwoFactorCode()))->toBeTrue();

    $events = [];
    captureSecurityEvents($events);

    $challengeResponse = $this->postJson('/v1/auth/token', [
        'email' => 'mfa-person@example.test',
        'password' => 'CorrectPassword123!',
        'device_name' => 'fixture-device',
    ])->assertAccepted();

    $this->postJson('/v1/auth/mfa-challenges/'.$challengeResponse->json('challenge.id').'/verify', [
        'method' => 'totp',
        'code' => '000000',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);

    expect($events)->toHaveCount(1)
        ->and($events[0]->toArray())->toMatchArray([
            'event_name' => 'authentication.mfa_failed',
            'outcome' => 'failure',
            'reason' => 'invalid_mfa_code',
            'metadata' => [
                'authentication_method' => 'totp',
                'login_context' => 'token',
            ],
        ]);
});

it('emits a passkey-specific failure without challenge or credential material', function (): void {
    $events = [];
    captureSecurityEvents($events);

    $challenge = app(PasskeyChallengeService::class)->createAuthenticationChallenge([
        'challenge' => 'synthetic-passkey-challenge',
        'rp_id' => 'app.secpal.dev',
        'timeout' => 60000,
        'user_verification' => 'preferred',
    ], 'conditional');

    /** @var PasskeyService&MockInterface $passkeyService */
    $passkeyService = $this->mock(PasskeyService::class);
    $passkeyService->shouldReceive('verifyAuthentication')
        ->once()
        ->andThrow(AuthenticatorResponseVerificationException::create('Synthetic invalid assertion.'));

    $this->withHeaders(spaCsrfHeaders($this))
        ->postJson('/v1/auth/passkeys/challenges/'.$challenge['challenge_id'].'/verify', [
            'credential' => [
                'id' => 'synthetic-credential-id',
                'raw_id' => 'synthetic-raw-credential-id',
                'type' => 'public-key',
                'response' => [
                    'client_data_json' => 'synthetic-client-data',
                    'authenticator_data' => 'synthetic-authenticator-data',
                    'signature' => 'synthetic-signature',
                ],
            ],
        ])->assertUnprocessable()
        ->assertJsonValidationErrors(['credential']);

    expect($events)->toHaveCount(1)
        ->and($events[0]->toArray())->toMatchArray([
            'event_name' => 'authentication.passkey_failed',
            'outcome' => 'failure',
            'reason' => 'invalid_passkey_credential',
            'metadata' => [
                'authentication_method' => 'passkey',
                'login_context' => 'session',
            ],
        ])
        ->and($events[0]->toJson())->not->toContain(
            'synthetic-passkey-challenge',
            'synthetic-credential-id',
            'synthetic-client-data',
            'synthetic-signature',
        );
});

it('emits a passkey-specific failure when malformed assertion data causes an unexpected exception', function (): void {
    $events = [];
    captureSecurityEvents($events);

    $challenge = app(PasskeyChallengeService::class)->createAuthenticationChallenge([
        'challenge' => 'synthetic-passkey-challenge',
        'rp_id' => 'app.secpal.dev',
        'timeout' => 60000,
        'user_verification' => 'preferred',
    ], 'conditional');

    /** @var PasskeyService&MockInterface $passkeyService */
    $passkeyService = $this->mock(PasskeyService::class);
    $passkeyService->shouldReceive('verifyAuthentication')
        ->once()
        ->andThrow(new RuntimeException('Synthetic malformed assertion.'));

    $this->withHeaders(spaCsrfHeaders($this))
        ->postJson('/v1/auth/passkeys/challenges/'.$challenge['challenge_id'].'/verify', [
            'credential' => [
                'id' => 'synthetic-credential-id',
                'raw_id' => 'synthetic-raw-credential-id',
                'type' => 'public-key',
                'response' => [
                    'client_data_json' => 'synthetic-client-data',
                    'authenticator_data' => 'synthetic-authenticator-data',
                    'signature' => 'synthetic-signature',
                ],
            ],
        ])->assertUnprocessable()
        ->assertJsonValidationErrors(['credential']);

    expect($events)->toHaveCount(1)
        ->and($events[0]->toArray())->toMatchArray([
            'event_name' => 'authentication.passkey_failed',
            'outcome' => 'failure',
            'reason' => 'invalid_passkey_credential',
            'metadata' => [
                'authentication_method' => 'passkey',
                'login_context' => 'session',
            ],
        ]);
});

it('emits one explicit defensive signal when application login throttling denies a request', function (): void {
    $email = 'throttled-person@example.test';
    clearLoginRateLimiter($email);

    User::factory()->create([
        'email' => $email,
        'password' => Hash::make('CorrectPassword123!'),
    ]);

    $events = [];
    captureSecurityEvents($events);

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/v1/auth/token', [
            'email' => $email,
            'password' => 'WrongPassword123!',
        ])->assertUnprocessable();
    }

    $this->postJson('/v1/auth/token', [
        'email' => $email,
        'password' => 'WrongPassword123!',
    ])->assertTooManyRequests();

    expect($events)->toHaveCount(6)
        ->and($events[5]->toArray())->toMatchArray([
            'event_name' => 'authentication.rate_limited',
            'outcome' => 'denied',
            'reason' => 'rate_limited',
            'metadata' => [
                'authentication_method' => 'password',
                'login_context' => 'token',
            ],
        ]);
});

it('keeps known and unknown reset-token responses identical while pseudonymizing correlation', function (): void {
    User::factory()->create(['email' => 'known-reset@example.test']);

    $events = [];
    captureSecurityEvents($events);

    $payload = [
        'token' => 'synthetic-reset-token',
        'password' => 'ReplacementPassword123!',
        'password_confirmation' => 'ReplacementPassword123!',
    ];

    $knownResponse = $this->postJson('/v1/auth/password/reset', [
        ...$payload,
        'email' => 'known-reset@example.test',
    ]);
    $unknownResponse = $this->postJson('/v1/auth/password/reset', [
        ...$payload,
        'email' => 'unknown-reset@example.test',
    ]);

    expect($knownResponse->getStatusCode())->toBe($unknownResponse->getStatusCode())
        ->and($knownResponse->json())->toBe($unknownResponse->json())
        ->and($events)->toHaveCount(2)
        ->and($events[0]->toArray()['actor_reference'])->not->toBe($events[1]->toArray()['actor_reference']);

    foreach ($events as $event) {
        expect($event->toJson())
            ->not->toContain('known-reset@example.test')
            ->not->toContain('unknown-reset@example.test')
            ->not->toContain('synthetic-reset-token');
    }
});

it('emits a password-reset rate-limit signal without changing the public denial', function (
    string $endpoint,
    array $payload,
    string $phase,
): void {
    Mail::fake();

    $events = [];
    captureSecurityEvents($events);

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $response = $this->postJson($endpoint, $payload);

        if ($phase === 'request') {
            $response->assertOk();
        } else {
            $response->assertBadRequest();
        }
    }

    $this->postJson($endpoint, $payload)->assertTooManyRequests();

    $event = $events[array_key_last($events)];

    expect($event->toArray())->toMatchArray([
        'event_name' => 'password_reset.rate_limited',
        'outcome' => 'denied',
        'reason' => 'rate_limited',
        'metadata' => ['reset_phase' => $phase],
    ]);
})->with([
    'request phase' => [
        '/v1/auth/password/reset-request',
        ['email' => 'reset-rate-limit@example.test'],
        'request',
    ],
    'confirmation phase' => [
        '/v1/auth/password/reset',
        [
            'email' => 'reset-rate-limit@example.test',
            'token' => 'synthetic-invalid-reset-token',
            'password' => 'ReplacementPassword123!',
            'password_confirmation' => 'ReplacementPassword123!',
        ],
        'confirmation',
    ],
]);

it('does not change authentication behavior when local event output fails', function (): void {
    User::factory()->create([
        'email' => 'person@example.test',
        'password' => Hash::make('CorrectPassword123!'),
    ]);

    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('info')->once()->andThrow(new RuntimeException('local sink unavailable'));

    $logs = Mockery::mock(LogManager::class, function (MockInterface $mock) use ($logger): void {
        $mock->shouldReceive('channel')->once()->andReturn($logger);
    });

    app()->instance(SecurityEventEmitter::class, new BoundedSecurityEventEmitter(
        $logs,
        app(Illuminate\Cache\RateLimiter::class),
    ));

    $this->postJson('/v1/auth/token', [
        'email' => 'person@example.test',
        'password' => 'WrongPassword123!',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\SecurityEvents\SecurityActorReference;
use App\SecurityEvents\SecurityEvent;
use App\SecurityEvents\SecurityEventName;
use App\SecurityEvents\SecurityEventReason;
use App\Services\BoundedSecurityEventEmitter;
use Illuminate\Http\Request;
use Illuminate\Log\LogManager;
use Illuminate\Support\Str;
use Opis\JsonSchema\Validator;
use Psr\Log\LoggerInterface;

function securityEventFixtureDirectory(): string
{
    return dirname(__DIR__, 3).'/docs/security-events/v1';
}

/**
 * @return array<string, mixed>
 */
function validSecurityEventPayload(): array
{
    return [
        'schema_version' => 1,
        'event_name' => 'authentication.failed',
        'occurred_at' => '2026-01-02T03:04:05.678Z',
        'outcome' => 'failure',
        'reason' => 'invalid_credentials',
        'source_ip' => '192.0.2.10',
        'correlation_id' => '018cc251-7800-7def-9000-0123456789ab',
        'actor_reference' => 'hmac-sha256:v1:'.str_repeat('a', 64),
        'metadata' => [
            'authentication_method' => 'password',
            'login_context' => 'token',
        ],
    ];
}

it('publishes a closed current schema and parser fixtures', function (): void {
    $directory = securityEventFixtureDirectory();
    $schemaPath = $directory.'/schema.json';
    $fixturesPath = $directory.'/events.jsonl';

    expect($schemaPath)->toBeFile()
        ->and($fixturesPath)->toBeFile();

    $schema = json_decode((string) file_get_contents($schemaPath), false, 512, JSON_THROW_ON_ERROR);
    $validator = new Validator(null, 100, false);
    $lines = preg_split('/\R/', trim((string) file_get_contents($fixturesPath)));

    expect($lines)->toBeArray()->not->toBeEmpty();

    foreach ($lines as $line) {
        $fixture = json_decode($line, false, 512, JSON_THROW_ON_ERROR);

        expect($validator->validate($fixture, $schema)->isValid())->toBeTrue()
            ->and(SecurityEvent::fromArray((array) json_decode($line, true, 512, JSON_THROW_ON_ERROR))->toArray())
            ->toBe((array) json_decode($line, true, 512, JSON_THROW_ON_ERROR));
    }

    $schemaNames = (array) $schema->properties->event_name->enum;
    $fixtureNames = array_map(
        static fn (string $line): string => (string) json_decode($line, false, 512, JSON_THROW_ON_ERROR)->event_name,
        $lines,
    );
    $runtimeNames = array_map(
        static fn (SecurityEventName $name): string => $name->value,
        SecurityEventName::cases(),
    );

    sort($schemaNames);
    sort($fixtureNames);
    sort($runtimeNames);

    expect($schemaNames)->toBe($runtimeNames)
        ->and($fixtureNames)->toBe($runtimeNames);
});

it('rejects unknown versions malformed shapes and undeclared fields', function (string $mutation): void {
    $payload = validSecurityEventPayload();

    match ($mutation) {
        'unknown_version' => $payload['schema_version'] = 2,
        'missing_field' => $payload = array_diff_key($payload, ['source_ip' => true]),
        'unknown_event' => $payload['event_name'] = 'authentication.future_event',
        'reason_mismatch' => $payload['reason'] = 'invalid_mfa_code',
        'arbitrary_metadata' => $payload['metadata']['request_body'] = 'unsafe',
        'additional_field' => $payload['email'] = 'person@example.test',
        'non_canonical_ip' => $payload['source_ip'] = '192.000.002.010',
    };

    expect(fn (): SecurityEvent => SecurityEvent::fromArray($payload))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'unknown version' => 'unknown_version',
    'missing required field' => 'missing_field',
    'unknown event name' => 'unknown_event',
    'event and reason mismatch' => 'reason_mismatch',
    'arbitrary metadata' => 'arbitrary_metadata',
    'additional top-level field' => 'additional_field',
    'non-canonical IP' => 'non_canonical_ip',
]);

it('makes the published schema reject unknown versions and undeclared fields', function (string $mutation): void {
    $schema = json_decode(
        (string) file_get_contents(securityEventFixtureDirectory().'/schema.json'),
        false,
        512,
        JSON_THROW_ON_ERROR,
    );
    $payload = validSecurityEventPayload();

    if ($mutation === 'version') {
        $payload['schema_version'] = 2;
    } else {
        $payload['authorization'] = 'synthetic-secret';
    }

    $data = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

    expect((new Validator(null, 100, false))->validate($data, $schema)->isValid())->toBeFalse();
})->with([
    'unknown version' => 'version',
    'undeclared field' => 'field',
]);

it('uses deferred local logging without an external security backend dependency', function (): void {
    $channel = config('logging.channels.security_events');
    $recorderSource = (string) file_get_contents(dirname(__DIR__, 3).'/app/Services/SecurityEventRecorder.php');
    $emitterSource = (string) file_get_contents(dirname(__DIR__, 3).'/app/Services/BoundedSecurityEventEmitter.php');

    expect($channel)->toMatchArray([
        'driver' => 'daily',
        'level' => 'info',
    ])->and($recorderSource)->toContain('defer(')
        ->and($emitterSource)
        ->not->toContain('Http::', 'Guzzle', 'CrowdSec', 'SIEM', 'curl_', 'Queue::');
});

it('writes the exact event JSON as one local log line', function (): void {
    $basePath = sys_get_temp_dir().'/secpal-security-event-'.Str::uuid().'.log';
    config()->set('logging.channels.security_events.path', $basePath);
    app(LogManager::class)->forgetChannel('security_events');

    $request = Request::create('/v1/auth/token', 'POST', server: ['REMOTE_ADDR' => '192.0.2.30']);
    $event = SecurityEvent::forRequest(
        $request,
        SecurityEventName::AuthenticationTokenRejected,
        SecurityEventReason::InvalidOrExpired,
        ['authentication_method' => 'bearer_token'],
    );

    try {
        app(BoundedSecurityEventEmitter::class)->emit($event);
        app(LogManager::class)->forgetChannel('security_events');

        $paths = glob(substr($basePath, 0, -4).'-*.log');

        expect($paths)->toBeArray()->toHaveCount(1)
            ->and((string) file_get_contents($paths[0]))->toBe($event->toJson().PHP_EOL);
    } finally {
        foreach (glob(substr($basePath, 0, -4).'-*.log') ?: [] as $path) {
            unlink($path);
        }
    }
});

it('derives stable domain-separated actor references without exposing identifiers', function (): void {
    config()->set('app.key', 'base64:nRWNo2CgugcDYn5VJsEzigv2nowyJLSArqfRhlB+USo=');

    $first = SecurityActorReference::fromIdentifier('018cc251-7800-7def-9000-0123456789ab');
    $same = SecurityActorReference::fromIdentifier('018cc251-7800-7def-9000-0123456789ab');
    $different = SecurityActorReference::fromIdentifier('018cc251-7800-7def-9000-0123456789ac');

    expect($first->value())->toBe($same->value())
        ->not->toBe($different->value())
        ->not->toContain('018cc251')
        ->toMatch('/\Ahmac-sha256:v1:[a-f0-9]{64}\z/');
});

it('bounds repeated output independently from authentication rate limiting', function (): void {
    config()->set('security-events.rate_bound.per_fingerprint', 3);
    config()->set('security-events.rate_bound.global', 10);
    config()->set('security-events.rate_bound.decay_seconds', 60);

    $written = 0;
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('info')->times(3)->andReturnUsing(function () use (&$written): void {
        $written++;
    });

    $logs = Mockery::mock(LogManager::class);
    $logs->shouldReceive('channel')->times(3)->andReturn($logger);

    $emitter = new BoundedSecurityEventEmitter($logs, app(Illuminate\Cache\RateLimiter::class));
    $request = Request::create('/v1/auth/token', 'POST', server: ['REMOTE_ADDR' => '192.0.2.20']);
    $actor = SecurityActorReference::fromIdentifier('018cc251-7800-7def-9000-0123456789ab');

    for ($attempt = 0; $attempt < 50; $attempt++) {
        $emitter->emit(SecurityEvent::forRequest(
            $request,
            SecurityEventName::AuthenticationFailed,
            SecurityEventReason::InvalidCredentials,
            ['authentication_method' => 'password', 'login_context' => 'token'],
            $actor,
        ));
    }

    expect($written)->toBe(3);
});

it('applies a global output bound when an attacker varies event fingerprints', function (): void {
    config()->set('security-events.rate_bound.per_fingerprint', 5);
    config()->set('security-events.rate_bound.global', 4);
    config()->set('security-events.rate_bound.decay_seconds', 60);

    $written = 0;
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('info')->times(4)->andReturnUsing(function () use (&$written): void {
        $written++;
    });

    $logs = Mockery::mock(LogManager::class);
    $logs->shouldReceive('channel')->times(4)->andReturn($logger);

    $emitter = new BoundedSecurityEventEmitter($logs, app(Illuminate\Cache\RateLimiter::class));

    for ($attempt = 1; $attempt <= 50; $attempt++) {
        $request = Request::create(
            '/v1/auth/token',
            'POST',
            server: ['REMOTE_ADDR' => '192.0.2.'.(($attempt % 200) + 1)],
        );

        $emitter->emit(SecurityEvent::forRequest(
            $request,
            SecurityEventName::AuthenticationTokenRejected,
            SecurityEventReason::InvalidOrExpired,
            ['authentication_method' => 'bearer_token'],
        ));
    }

    expect($written)->toBe(4);
});

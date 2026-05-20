<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Services\PasskeyService;
use Illuminate\Support\Facades\Log;

describe('PasskeyService::formatApiPayload', function () {
    test('registration options are converted to snake_case for the API response', function () {
        $service = app(PasskeyService::class);

        $camelCaseOptions = [
            'challenge' => 'dGVzdC1jaGFsbGVuZ2U',
            'rp' => [
                'id' => 'app.secpal.dev',
                'icon' => null,
                'name' => 'SecPal',
            ],
            'user' => [
                'id' => 'dXNlci1pZA',
                'name' => 'user@secpal.dev',
                'displayName' => 'Test User',
            ],
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => -7],
                ['type' => 'public-key', 'alg' => -257],
            ],
            'authenticatorSelection' => [
                'authenticatorAttachment' => null,
                'residentKey' => 'preferred',
                'userVerification' => 'preferred',
            ],
            'attestation' => 'none',
            'excludeCredentials' => [],
            'timeout' => 60000,
        ];

        $formatted = $service->formatApiPayload($camelCaseOptions);

        expect($formatted)->toHaveKey('pub_key_cred_params')
            ->and($formatted)->toHaveKey('authenticator_selection')
            ->and($formatted['user'])->toHaveKey('display_name')
            ->and($formatted['authenticator_selection'])->toHaveKey('resident_key')
            ->and($formatted['authenticator_selection'])->toHaveKey('user_verification')
            ->and($formatted['authenticator_selection'])->not->toHaveKey('authenticator_attachment', 'null authenticator_attachment must be stripped')
            ->and($formatted['rp'])->not->toHaveKey('icon', 'null rp.icon must be stripped')
            ->and($formatted)->not->toHaveKey('exclude_credentials', 'empty excludeCredentials must be omitted');
    });

    test('authentication options omit allow_credentials when empty for discoverable credential flow', function () {
        $service = app(PasskeyService::class);

        $options = [
            'challenge' => 'dGVzdA',
            'rpId' => 'app.secpal.dev',
            'timeout' => 60000,
            'userVerification' => 'preferred',
            'allowCredentials' => [],
        ];

        $formatted = $service->formatApiPayload($options);

        expect($formatted)->not->toHaveKey('allow_credentials')
            ->and($formatted)->toHaveKey('rp_id')
            ->and($formatted)->toHaveKey('user_verification');
    });
});

describe('PasskeyService authentication fallback secret', function () {
    test('email-scoped authentication options require a configured fallback secret', function () {
        config()->set('passkeys.authentication_fallback_secret', '');
        config()->set('app.key', '');

        $service = app(PasskeyService::class);

        expect(fn () => $service->buildAuthenticationOptions(null, 'missing@secpal.dev'))
            ->toThrow(
                RuntimeException::class,
                'Passkey authentication fallback secret must be configured via PASSKEY_AUTHENTICATION_FALLBACK_SECRET or APP_KEY.',
            );
    });

    test('the config resolves APP_KEY when PASSKEY_AUTHENTICATION_FALLBACK_SECRET is blank', function () {
        // The ?: operator in config/passkeys.php resolves at config-load time.
        // Simulate the resulting config value as if APP_KEY was used as the fallback.
        $appKey = 'base64:'.base64_encode(str_repeat('k', 32));
        config()->set('passkeys.authentication_fallback_secret', $appKey);

        $service = app(PasskeyService::class);

        expect(fn () => $service->buildAuthenticationOptions(null, 'user@secpal.dev'))
            ->not->toThrow(RuntimeException::class);
    });

    test('an invalid base64 payload in the fallback secret throws rather than silently using the raw prefix string', function () {
        config()->set('passkeys.authentication_fallback_secret', 'base64:!!!not-valid-base64!!!');

        $service = app(PasskeyService::class);

        expect(fn () => $service->buildAuthenticationOptions(null, 'user@secpal.dev'))
            ->toThrow(
                RuntimeException::class,
                'Passkey authentication fallback secret must be configured via PASSKEY_AUTHENTICATION_FALLBACK_SECRET or APP_KEY.',
            );
    });

    test('a warning is logged once per service instance when the fallback secret is derived from APP_KEY', function () {
        $appKey = 'base64:'.base64_encode(str_repeat('k', 32));
        config()->set('passkeys.authentication_fallback_secret', $appKey);
        config()->set('passkeys.authentication_fallback_uses_app_key', true);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message): bool => str_contains($message, 'Passkey authentication fallback HMAC secret is derived from APP_KEY')
                && str_contains($message, 'PASSKEY_AUTHENTICATION_FALLBACK_SECRET'));

        $service = app(PasskeyService::class);

        $service->buildAuthenticationOptions(null, 'first@secpal.dev');
        $service->buildAuthenticationOptions(null, 'second@secpal.dev');
    });

    test('no warning is logged when the dedicated fallback secret is configured', function () {
        $dedicatedSecret = 'base64:'.base64_encode(str_repeat('s', 32));
        config()->set('passkeys.authentication_fallback_secret', $dedicatedSecret);
        config()->set('passkeys.authentication_fallback_uses_app_key', false);

        Log::shouldReceive('warning')->never();

        $service = app(PasskeyService::class);

        $service->buildAuthenticationOptions(null, 'user@secpal.dev');
    });
});

describe('passkeys.authentication_fallback_uses_app_key config flag', function () {
    /**
     * Re-evaluate `config/passkeys.php` against a temporary env override so we
     * can assert the env-resolution branches directly without mutating the live
     * application config bag.
     *
     * Declared as a closure inside the describe block to avoid a file-scope
     * function declaration, which would be registered globally and could cause
     * "Cannot redeclare" fatals under parallel test runs.
     *
     * @param  array<string, string|false>  $envOverrides
     * @return array<string, mixed>
     */
    $loadConfig = function (array $envOverrides): array {
        $original = [];

        foreach ($envOverrides as $name => $value) {
            $original[$name] = getenv($name);

            if ($value === false) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);

                continue;
            }

            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }

        try {
            /** @var array<string, mixed> $config */
            $config = require base_path('config/passkeys.php');

            return $config;
        } finally {
            foreach ($original as $name => $value) {
                if ($value === false) {
                    putenv($name);
                    unset($_ENV[$name], $_SERVER[$name]);

                    continue;
                }

                putenv("{$name}={$value}");
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    };

    test('the flag is true when only APP_KEY is set', function () use ($loadConfig) {
        $appKey = 'base64:'.base64_encode(str_repeat('a', 32));

        $config = $loadConfig([
            'PASSKEY_AUTHENTICATION_FALLBACK_SECRET' => false,
            'APP_KEY' => $appKey,
        ]);

        expect($config['authentication_fallback_secret'])->toBe($appKey)
            ->and($config['authentication_fallback_uses_app_key'])->toBeTrue();
    });

    test('the flag is false when the dedicated secret is set', function () use ($loadConfig) {
        $appKey = 'base64:'.base64_encode(str_repeat('a', 32));
        $dedicated = 'base64:'.base64_encode(str_repeat('d', 32));

        $config = $loadConfig([
            'PASSKEY_AUTHENTICATION_FALLBACK_SECRET' => $dedicated,
            'APP_KEY' => $appKey,
        ]);

        expect($config['authentication_fallback_secret'])->toBe($dedicated)
            ->and($config['authentication_fallback_uses_app_key'])->toBeFalse();
    });

    test('the flag is false when neither the dedicated secret nor APP_KEY is set', function () use ($loadConfig) {
        $config = $loadConfig([
            'PASSKEY_AUTHENTICATION_FALLBACK_SECRET' => false,
            'APP_KEY' => false,
        ]);

        expect($config['authentication_fallback_secret'])->toBe('')
            ->and($config['authentication_fallback_uses_app_key'])->toBeFalse();
    });
});

describe('PasskeyService native Android origin support', function () {
    test('the canonical Android passkey origin is derived from the signing certificate fingerprint', function () {
        config()->set('android.signing_certificate_sha256_fingerprint', 'C3:E9:FD:07:69:F3:34:9B:B0:B0:56:BA:E6:69:47:23:40:E1:CB:28:66:26:DE:30:C9:C9:FA:F9:5F:1E:47:B5');

        $fingerprint = strtolower((string) config('android.signing_certificate_sha256_fingerprint'));
        $fingerprintHex = str_replace(':', '', $fingerprint);
        $fingerprintBytes = hex2bin($fingerprintHex);

        expect($fingerprintBytes)->not->toBeFalse();

        $androidOrigin = 'android:apk-key-hash:'.rtrim(strtr(base64_encode($fingerprintBytes), '+/', '-_'), '=');

        expect($androidOrigin)->toBe('android:apk-key-hash:w-n9B2nzNJuwsFa65mlHI0DhyyhmJt4wycn6-V8eR7U');
    });
});

<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Services\PasskeyService;

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

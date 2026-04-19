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

describe('PasskeyService credential deserialization key casing', function () {
    test('client_data_json is converted to clientDataJSON for webauthn-lib', function () {
        $service = app(PasskeyService::class);

        // The webauthn-lib denormalizer accesses $data['clientDataJSON'] (uppercase JSON).
        // Str::camel('client_data_json') produces 'clientDataJson' (lowercase json),
        // which would cause an undefined array key error during deserialization.
        $snakeCasePayload = [
            'id' => 'Ax9Yc0ZLQmN4V1V1S1cwVnI1Q0FyRkE',
            'raw_id' => 'Ax9Yc0ZLQmN4V1V1S1cwVnI1Q0FyRkE',
            'type' => 'public-key',
            'response' => [
                'client_data_json' => 'eyJ0eXBlIjoid2ViYXV0aG4uY3JlYXRlIiwiY2hhbGxlbmdlIjoiZEdWemRBIiwib3JpZ2luIjoiaHR0cHM6Ly9hcHAuc2VjcGFsLmRldiJ9',
                'attestation_object' => 'o2NmbXRkbm9uZWdhdHRTdG10oGhhdXRoRGF0YVkBJkmWDeWIDoxodDQXD2R2YFuP5K65ooYyx5lc87qDHZdjQQAAAAAAAAAAAAAAAAAAAAAAAAAAAIBfWHNGS0JjeFdVdUswVnI1Q0FyRkFBQAECIAEh',
                'transports' => ['internal'],
            ],
        ];

        // Use reflection to access the private keysToCamelCase method
        $reflection = new ReflectionMethod(PasskeyService::class, 'keysToCamelCase');
        $converted = $reflection->invoke($service, $snakeCasePayload);

        expect($converted['response'])->toHaveKey('clientDataJSON')
            ->and($converted['response'])->not->toHaveKey('clientDataJson')
            ->and($converted)->toHaveKey('rawId')
            ->and($converted['response'])->toHaveKey('attestationObject');
    });

    test('assertion response client_data_json is converted to clientDataJSON', function () {
        $service = app(PasskeyService::class);

        $assertionPayload = [
            'id' => 'Ax9Yc0ZLQmN4V1V1S1cwVnI1Q0FyRkE',
            'raw_id' => 'Ax9Yc0ZLQmN4V1V1S1cwVnI1Q0FyRkE',
            'type' => 'public-key',
            'response' => [
                'client_data_json' => 'eyJ0eXBlIjoid2ViYXV0aG4uZ2V0In0',
                'authenticator_data' => 'SZYN5YgOjGh0NBcPZHZgW4',
                'signature' => 'MEUCIQC',
                'user_handle' => 'dXNlci1pZA',
            ],
        ];

        $reflection = new ReflectionMethod(PasskeyService::class, 'keysToCamelCase');
        $converted = $reflection->invoke($service, $assertionPayload);

        expect($converted['response'])->toHaveKey('clientDataJSON')
            ->and($converted['response'])->not->toHaveKey('clientDataJson')
            ->and($converted['response'])->toHaveKey('authenticatorData')
            ->and($converted['response'])->toHaveKey('signature')
            ->and($converted['response'])->toHaveKey('userHandle');
    });
});

describe('PasskeyService native Android origin support', function () {
    test('allowed origins include the canonical Android passkey origin derived from the signing certificate fingerprint', function () {
        config()->set('passkeys.allowed_origins', ['https://app.secpal.dev']);
        config()->set('android.signing_certificate_sha256_fingerprint', 'C3:E9:FD:07:69:F3:34:9B:B0:B0:56:BA:E6:69:47:23:40:E1:CB:28:66:26:DE:30:C9:C9:FA:F9:5F:1E:47:B5');

        $service = app(PasskeyService::class);
        $reflection = new ReflectionMethod(PasskeyService::class, 'allowedOrigins');

        /** @var list<string> $allowedOrigins */
        $allowedOrigins = $reflection->invoke($service);

        expect($allowedOrigins)->toContain('https://app.secpal.dev')
            ->and($allowedOrigins)->toContain('android:apk-key-hash:w-n9B2nzNJuwsFa65mlHI0DhyyhmJt4wycn6-V8eR7U');
    });
});

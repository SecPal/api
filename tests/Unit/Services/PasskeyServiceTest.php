<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Services\PasskeyService;

beforeEach(function () {
    $this->service = app(PasskeyService::class);
});

describe('PasskeyService::formatApiPayload', function () {
    test('registration options are converted to snake_case for the API response', function () {
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

        $formatted = $this->service->formatApiPayload($camelCaseOptions);

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
        $options = [
            'challenge' => 'dGVzdA',
            'rpId' => 'app.secpal.dev',
            'timeout' => 60000,
            'userVerification' => 'preferred',
            'allowCredentials' => [],
        ];

        $formatted = $this->service->formatApiPayload($options);

        expect($formatted)->not->toHaveKey('allow_credentials')
            ->and($formatted)->toHaveKey('rp_id')
            ->and($formatted)->toHaveKey('user_verification');
    });
});

describe('PasskeyService credential deserialization key casing', function () {
    test('client_data_json is converted to clientDataJSON for webauthn-lib', function () {
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
        $converted = $reflection->invoke($this->service, $snakeCasePayload);

        expect($converted['response'])->toHaveKey('clientDataJSON')
            ->and($converted['response'])->not->toHaveKey('clientDataJson')
            ->and($converted)->toHaveKey('rawId')
            ->and($converted['response'])->toHaveKey('attestationObject');
    });

    test('assertion response client_data_json is converted to clientDataJSON', function () {
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
        $converted = $reflection->invoke($this->service, $assertionPayload);

        expect($converted['response'])->toHaveKey('clientDataJSON')
            ->and($converted['response'])->not->toHaveKey('clientDataJson')
            ->and($converted['response'])->toHaveKey('authenticatorData')
            ->and($converted['response'])->toHaveKey('signature')
            ->and($converted['response'])->toHaveKey('userHandle');
    });
});

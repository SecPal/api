<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\User;
use App\Services\PasskeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

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
                'residentKey' => 'required',
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
            ->and($formatted['authenticator_selection']['resident_key'])->toBe('required')
            ->and($formatted['authenticator_selection']['require_resident_key'])->toBeTrue('require_resident_key must be true alongside resident_key=required')
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

describe('PasskeyService::buildAuthenticationOptions', function () {
    test('public authentication options stay discoverable-only without user identification', function () {
        $service = app(PasskeyService::class);

        $formatted = $service->formatApiPayload($service->buildAuthenticationOptions());

        expect($formatted)->toHaveKey('challenge')
            ->and($formatted)->toHaveKey('rp_id')
            ->and($formatted)->toHaveKey('timeout')
            ->and($formatted)->toHaveKey('user_verification')
            ->and($formatted)->not->toHaveKey('allow_credentials');
    });
});

describe('PasskeyService::buildRegistrationOptions', function () {
    test('registration options require discoverable resident credentials by default', function () {
        $service = app(PasskeyService::class);
        $user = User::factory()->create();

        $formatted = $service->formatApiPayload($service->buildRegistrationOptions($user));

        expect($formatted)->toHaveKey('authenticator_selection')
            ->and($formatted['authenticator_selection']['resident_key'])->toBe('required', 'discoverable-only login requires resident_key=required for enrollment')
            ->and($formatted['authenticator_selection']['require_resident_key'])->toBeTrue('legacy require_resident_key must be true for resident_key=required');
    });

    test('an invalid resident_key configuration falls back to the discoverable-only default', function () {
        config()->set('passkeys.resident_key', 'not-a-valid-policy');
        config()->set('passkeys.require_resident_key', false);

        $service = app(PasskeyService::class);
        $user = User::factory()->create();

        $formatted = $service->formatApiPayload($service->buildRegistrationOptions($user));

        expect($formatted['authenticator_selection']['resident_key'])->toBe('required')
            ->and($formatted['authenticator_selection']['require_resident_key'])->toBeTrue('require_resident_key must be coerced back to true when resident_key is required');
    });

    test('an explicit preferred resident_key still emits require_resident_key by default', function () {
        config()->set('passkeys.resident_key', 'preferred');
        // require_resident_key left at config default (true)

        $service = app(PasskeyService::class);
        $user = User::factory()->create();

        $formatted = $service->formatApiPayload($service->buildRegistrationOptions($user));

        expect($formatted['authenticator_selection']['resident_key'])->toBe('preferred')
            ->and($formatted['authenticator_selection']['require_resident_key'])->toBeTrue('preferred resident_key must still emit require_resident_key=true unless PASSKEY_REQUIRE_RESIDENT_KEY=false is explicitly set');
    });

    test('an explicit preferred resident_key with require_resident_key opted in propagates both flags', function () {
        config()->set('passkeys.resident_key', 'preferred');
        config()->set('passkeys.require_resident_key', true);

        $service = app(PasskeyService::class);
        $user = User::factory()->create();

        $formatted = $service->formatApiPayload($service->buildRegistrationOptions($user));

        expect($formatted['authenticator_selection']['resident_key'])->toBe('preferred')
            ->and($formatted['authenticator_selection']['require_resident_key'])->toBeTrue('require_resident_key override must propagate even when resident_key is preferred');
    });

    test('a discouraged resident_key never emits require_resident_key true', function () {
        config()->set('passkeys.resident_key', 'discouraged');

        $service = app(PasskeyService::class);
        $user = User::factory()->create();

        $formatted = $service->formatApiPayload($service->buildRegistrationOptions($user));

        expect($formatted['authenticator_selection']['resident_key'])->toBe('discouraged')
            ->and($formatted['authenticator_selection'])->not->toHaveKey('require_resident_key', 'discouraged resident_key must never emit require_resident_key=true — that pairing is a WebAuthn spec contradiction');
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

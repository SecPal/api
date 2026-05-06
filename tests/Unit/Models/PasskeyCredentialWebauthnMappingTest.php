<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\PasskeyCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Webauthn\CredentialRecord;

uses(RefreshDatabase::class);

describe('PasskeyCredential WebAuthn mapping', function () {
    test('toPublicKeyCredentialSource declares CredentialRecord return type', function () {
        $method = new ReflectionMethod(PasskeyCredential::class, 'toPublicKeyCredentialSource');
        $returnType = $method->getReturnType();

        expect($returnType)->not->toBeNull()
            ->and($returnType?->getName())->toBe(CredentialRecord::class);
    });

    test('toPublicKeyCredentialSource returns a CredentialRecord instance', function () {
        $credential = PasskeyCredential::factory()->create();

        $record = $credential->toPublicKeyCredentialSource();

        expect($record)->toBeInstanceOf(CredentialRecord::class)
            ->and($record->publicKeyCredentialId)->not->toBe('')
            ->and($record->type)->toBe('public-key');
    });
});

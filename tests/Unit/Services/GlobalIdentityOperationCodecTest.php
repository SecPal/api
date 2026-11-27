<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Exceptions\GlobalIdentityKeyException;
use App\Services\GlobalIdentity\GlobalIdentityKeyReference;
use App\Services\GlobalIdentity\GlobalIdentityOperationCodec;
use App\Services\GlobalIdentity\GlobalIdentityOperationContext;
use App\Services\GlobalIdentity\GlobalIdentityOperationType as Operation;
use App\Services\GlobalIdentity\GlobalIdentityWorkingKeyContext;
use App\Services\GlobalIdentity\GlobalIdentityWorkingKeyPurpose as Purpose;

it('authenticates the complete invitation binding and separates token and recipient envelopes', function (): void {
    $codec = new GlobalIdentityOperationCodec;
    $material = str_repeat("\x1a", 32);
    $key = new GlobalIdentityKeyReference('31da8d35-a5ce-480c-ba01-febadec4d4d3', 1);
    $tokenKey = new GlobalIdentityWorkingKeyContext(Purpose::OperationDeliverySecret, $key);
    $recipientKey = new GlobalIdentityWorkingKeyContext(Purpose::InvitationAddressEncryption, $key);
    $id = '9a7c68db-5f41-414b-805c-76a0f4a79e5a';
    $user = '29990a9a-a680-4e3e-be9a-626506c7f4ad';
    $invitation = new GlobalIdentityOperationContext(Operation::Invitation, $id, null, null, $id, 2);
    $token = sodium_bin2base64(str_repeat("\x23", 32), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    $sealedToken = $codec->sealToken($invitation, $token, $tokenKey, $material);
    $sealedRecipient = $codec->sealRecipient($invitation, 'First+Tag@Faß.DE', $recipientKey, $material);
    expect($codec->openToken($invitation, $sealedToken, $material))->toBe($token)
        ->and($codec->openRecipient($invitation, $sealedRecipient, $material)->transport())->toBe('First+Tag@xn--fa-hia.de')
        ->and($codec->sealToken($invitation, $token, $tokenKey, $material)['nonce'])->not->toBe($sealedToken['nonce']);

    $differentId = '9850e874-0f4a-4087-830a-5601ae4d629e';
    foreach ([
        new GlobalIdentityOperationContext(Operation::Invitation, $id, $user, null, $id, 2),
        new GlobalIdentityOperationContext(Operation::Invitation, $id, null, null, $id, 3),
        new GlobalIdentityOperationContext(Operation::Invitation, $differentId, null, null, $differentId, 2),
        new GlobalIdentityOperationContext(Operation::PendingEmailChange, $id, $user, 1),
    ] as $wrongContext) {
        expect(fn () => $codec->openToken($wrongContext, $sealedToken, $material))->toThrow(GlobalIdentityKeyException::class);
    }
    expect(fn () => $codec->openRecipient($invitation, $sealedToken, $material))->toThrow(GlobalIdentityKeyException::class)
        ->and(fn () => $codec->openToken($invitation, $sealedRecipient, $material))->toThrow(GlobalIdentityKeyException::class)
        ->and(fn () => $codec->openToken($invitation, $sealedToken, str_repeat("\x12", 32)))->toThrow(GlobalIdentityKeyException::class);
});

it('binds reset delivery to one immutable user and email version', function (): void {
    $codec = new GlobalIdentityOperationCodec;
    $key = new GlobalIdentityWorkingKeyContext(
        Purpose::OperationDeliverySecret,
        new GlobalIdentityKeyReference('31da8d35-a5ce-480c-ba01-febadec4d4d3', 1),
    );
    $material = str_repeat("\x1a", 32);
    $id = '9a7c68db-5f41-414b-805c-76a0f4a79e5a';
    $user = '29990a9a-a680-4e3e-be9a-626506c7f4ad';
    $reset = new GlobalIdentityOperationContext(Operation::PasswordReset, $id, $user, 1);
    $token = sodium_bin2base64(str_repeat("\x23", 32), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    $sealed = $codec->sealToken($reset, $token, $key, $material);
    expect($codec->openToken($reset, $sealed, $material))->toBe($token);
    foreach ([
        new GlobalIdentityOperationContext(Operation::PasswordReset, $id, $user, 2),
        new GlobalIdentityOperationContext(Operation::PasswordReset, $id, $id, 1),
        new GlobalIdentityOperationContext(Operation::EmailVerification, $id, $user, 1),
    ] as $wrongContext) {
        expect(fn () => $codec->openToken($wrongContext, $sealed, $material))->toThrow(GlobalIdentityKeyException::class);
    }
});

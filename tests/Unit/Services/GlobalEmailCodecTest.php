<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Exceptions\GlobalIdentityKeyException;
use App\Services\GlobalIdentity\GlobalEmailAddress;
use App\Services\GlobalIdentity\GlobalEmailCodec;
use App\Services\GlobalIdentity\GlobalIdentityKeyReference;
use App\Services\GlobalIdentity\GlobalIdentityWorkingKeyContext;
use App\Services\GlobalIdentity\GlobalIdentityWorkingKeyPurpose as Purpose;

it('has stable versioned blind-index and envelope vectors', function (): void {
    $nonceSource = new Random\Randomizer(new Random\Engine\PcgOneseq128XslRr64(42));
    $codec = new GlobalEmailCodec(new Random\Randomizer(new Random\Engine\PcgOneseq128XslRr64(42)));
    $address = GlobalEmailAddress::parse('Lo.Cal+Tag@Faß.DE');
    $context = new GlobalIdentityWorkingKeyContext(
        Purpose::EmailEncryption,
        new GlobalIdentityKeyReference('31da8d35-a5ce-480c-ba01-febadec4d4d3', 1),
    );
    $userId = '9a7c68db-5f41-414b-805c-76a0f4a79e5a';
    $key = str_repeat("\x07", 32);
    $aad = hex2bin('0000000131000000175843484143484132302d504f4c59313330352d494554460000002a73656370616c2f676c6f62616c2d6964656e746974792f656d61696c2d656e6372797074696f6e2f76310000002433316461386433352d613563652d343830632d626130312d6665626164656334643464330000000131000000047573657200000009656d61696c5f656e630000002439613763363864622d356634312d343134622d383035632d373661306634613739653561');
    $nonce = $nonceSource->getBytes(24);
    $expected = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt('Lo.Cal+Tag@Faß.DE', $aad, $nonce, $key);
    $envelope = $codec->encrypt(strtoupper($userId), $address, $context, $key);

    expect(bin2hex($codec->lookupIndex($address, $key)))
        ->toBe('912b9aaf5b7b0111d85ecac05bfae2a74feb18943a5a29c1c9956151006be60b')
        ->and(sodium_base642bin($envelope['nonce'], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING))->toBe($nonce)
        ->and(sodium_base642bin($envelope['ciphertext_and_tag'], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING))->toBe($expected)
        ->and($codec->decrypt($userId, $envelope, $key)->original())->toBe($address->original());

    $retry = $codec->encrypt($userId, $address, $context, $key);
    expect($retry['nonce'])->not->toBe($envelope['nonce'])
        ->and($retry['ciphertext_and_tag'])->not->toBe($envelope['ciphertext_and_tag']);
});

it('fails closed when the envelope, record, key or authenticated metadata is tampered', function (): void {
    $codec = new GlobalEmailCodec;
    $context = new GlobalIdentityWorkingKeyContext(
        Purpose::EmailEncryption,
        new GlobalIdentityKeyReference('31da8d35-a5ce-480c-ba01-febadec4d4d3', 1),
    );
    $userId = '9a7c68db-5f41-414b-805c-76a0f4a79e5a';
    $key = str_repeat("\x07", 32);
    $envelope = $codec->encrypt($userId, GlobalEmailAddress::parse('test@secpal.app'), $context, $key);
    $ciphertext = sodium_base642bin($envelope['ciphertext_and_tag'], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    $ciphertext[strlen($ciphertext) - 1] = $ciphertext[strlen($ciphertext) - 1] ^ "\x01";
    $mutations = [
        ['format_version' => 2],
        ['algorithm' => 'unknown'],
        ['key_id' => $userId],
        ['key_version' => 2],
        ['nonce' => $envelope['nonce'].'='],
        ['nonce' => 'aA'],
        ['ciphertext_and_tag' => sodium_bin2base64($ciphertext, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)],
        ['unexpected' => true],
    ];
    foreach ($mutations as $mutation) {
        expect(fn () => $codec->decrypt($userId, array_replace($envelope, $mutation), $key))
            ->toThrow(GlobalIdentityKeyException::class);
    }
    expect(fn () => $codec->decrypt($context->reference->id, $envelope, $key))->toThrow(GlobalIdentityKeyException::class)
        ->and(fn () => $codec->decrypt($userId, $envelope, str_repeat("\x06", 32)))->toThrow(GlobalIdentityKeyException::class);
});

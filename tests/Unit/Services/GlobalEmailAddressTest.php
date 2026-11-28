<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Exceptions\GlobalIdentityKeyException;
use App\Services\GlobalIdentity\GlobalEmailAddress;

it('preserves the validated original and canonicalizes lookup and transport independently', function (): void {
    $address = GlobalEmailAddress::parse("\u{0085}\u{2009}Lo.Cal+Tag@Faß.DE\u{3000}");

    expect($address->original())->toBe('Lo.Cal+Tag@Faß.DE')
        ->and($address->normalized())->toBe('lo.cal+tag@xn--fa-hia.de')
        ->and($address->transport())->toBe('Lo.Cal+Tag@xn--fa-hia.de');
});

it('rejects invalid addresses without including the input in the error', function (string $email): void {
    expect(fn () => GlobalEmailAddress::parse($email))
        ->toThrow(GlobalIdentityKeyException::class, 'Invalid global email address.');
})->with([
    "\xff@secpal.app",
    'ü@secpal.app',
    'some..one@secpal.app',
    '"someone"@secpal.app',
    'someone(comment)@secpal.app',
    "some\u{00a0}one@secpal.app",
    "someone@sec\u{2000}pal.app",
    'someone@secpal.app.',
    "someone@secpal.app\u{3002}",
    'someone@sec_pal.app',
    'someone@@secpal.app',
    'someone@',
    'someone@-'.'secpal.app',
]);

it('enforces the normalized octet limits', function (): void {
    $local = str_repeat('a', 64);
    $label = str_repeat('b', 63);
    $domain = $label.'.'.$label.'.'.str_repeat('c', 57).'.app';
    expect(strlen(GlobalEmailAddress::parse($local.'@'.$domain)->normalized()))->toBe(254);

    foreach ([$local.'a@secpal.app', 'someone@'.$label.'b.app', $local.'@'.$domain.'b'] as $email) {
        expect(fn () => GlobalEmailAddress::parse($email))->toThrow(GlobalIdentityKeyException::class);
    }
});

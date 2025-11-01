<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Support\BlindIndex;

describe('BlindIndex', function () {
    describe('Email Normalization', function () {
        it('normalizes email to lowercase', function () {
            expect(BlindIndex::normEmail('Test@Example.COM'))->toBe('test@example.com');
        });

        it('trims whitespace from email', function () {
            expect(BlindIndex::normEmail('  test@example.com  '))->toBe('test@example.com');
        });

        it('is deterministic for same email', function () {
            $email1 = BlindIndex::normEmail('User@Domain.de');
            $email2 = BlindIndex::normEmail('user@domain.de');

            expect($email1)->toBe($email2);
        });
    });

    describe('Phone Normalization', function () {
        it('strips all non-digits from phone', function () {
            expect(BlindIndex::normPhone('+49 (123) 456-789'))->toBe('49123456789');
        });

        it('is deterministic for same phone', function () {
            $phone1 = BlindIndex::normPhone('+49 123 456789');
            $phone2 = BlindIndex::normPhone('49123456789');

            expect($phone1)->toBe($phone2);
        });

        it('handles various phone formats', function () {
            expect(BlindIndex::normPhone('+1-800-555-0100'))->toBe('18005550100')
                ->and(BlindIndex::normPhone('(555) 123-4567'))->toBe('5551234567')
                ->and(BlindIndex::normPhone('555.123.4567'))->toBe('5551234567');
        });
    });

    describe('Badge Normalization', function () {
        it('strips all non-digits from badge', function () {
            expect(BlindIndex::normBadge('ABC-12345'))->toBe('12345');
        });

        it('is deterministic', function () {
            $badge1 = BlindIndex::normBadge('ID#12345');
            $badge2 = BlindIndex::normBadge('12345');

            expect($badge1)->toBe($badge2);
        });
    });

    describe('HMAC Generation', function () {
        it('generates 32-byte binary output', function () {
            $idxKey = random_bytes(32);
            $normalized = 'test@example.com';

            $hmac = BlindIndex::hmac($normalized, $idxKey);

            expect($hmac)->toBeString()
                ->and(strlen($hmac))->toBe(32);
        });

        it('is deterministic with same input and key', function () {
            $idxKey = random_bytes(32);
            $normalized = 'test@example.com';

            $hmac1 = BlindIndex::hmac($normalized, $idxKey);
            $hmac2 = BlindIndex::hmac($normalized, $idxKey);

            expect($hmac1)->toBe($hmac2);
        });

        it('produces different output for different tenant keys', function () {
            $idxKey1 = random_bytes(32);
            $idxKey2 = random_bytes(32);
            $normalized = 'test@example.com';

            $hmac1 = BlindIndex::hmac($normalized, $idxKey1);
            $hmac2 = BlindIndex::hmac($normalized, $idxKey2);

            expect($hmac1)->not->toBe($hmac2);
        });

        it('produces different output for different values', function () {
            $idxKey = random_bytes(32);

            $hmac1 = BlindIndex::hmac('test1@example.com', $idxKey);
            $hmac2 = BlindIndex::hmac('test2@example.com', $idxKey);

            expect($hmac1)->not->toBe($hmac2);
        });

        it('throws exception for short index key', function () {
            $shortKey = random_bytes(16); // Too short

            expect(fn () => BlindIndex::hmac('test', $shortKey))
                ->toThrow(\InvalidArgumentException::class, 'Index key must be at least 32 bytes');
        });
    });

    describe('Index Verification', function () {
        it('verifies matching indexes', function () {
            $idxKey = random_bytes(32);
            $email = 'Test@Example.COM';

            $normalized = BlindIndex::normEmail($email);
            $storedIdx = BlindIndex::hmac($normalized, $idxKey);

            $result = BlindIndex::verify(
                'test@example.com',
                $storedIdx,
                $idxKey,
                [BlindIndex::class, 'normEmail']
            );

            expect($result)->toBeTrue();
        });

        it('rejects non-matching indexes', function () {
            $idxKey = random_bytes(32);
            $email = 'test@example.com';

            $normalized = BlindIndex::normEmail($email);
            $storedIdx = BlindIndex::hmac($normalized, $idxKey);

            $result = BlindIndex::verify(
                'different@example.com',
                $storedIdx,
                $idxKey,
                [BlindIndex::class, 'normEmail']
            );

            expect($result)->toBeFalse();
        });
    });
});

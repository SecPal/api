<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services\GlobalIdentity;

use App\Exceptions\GlobalIdentityKeyException;
use SensitiveParameter;

final readonly class GlobalEmailAddress
{
    public const NORMALIZATION_VERSION = 1;

    // Unicode White_Space, including the ASCII whitespace controls.
    private const WHITESPACE = '[\x{0009}-\x{000D}\x{0020}\x{0085}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]';

    private const LOCAL_PART = <<<'REGEX'
        /^[A-Za-z0-9!#$%&'*+\-\/=?^_\x60{|}~]+(?:\.[A-Za-z0-9!#$%&'*+\-\/=?^_\x60{|}~]+)*$/D
        REGEX;

    private function __construct(
        #[SensitiveParameter] private string $original,
        #[SensitiveParameter] private string $transport,
    ) {}

    public static function parse(#[SensitiveParameter] string $input): self
    {
        if (preg_match('//u', $input) !== 1) {
            throw new GlobalIdentityKeyException('Invalid global email address.');
        }

        $original = preg_replace('/\A'.self::WHITESPACE.'+|'.self::WHITESPACE.'+\z/u', '', $input);
        if ($original === null || substr_count($original, '@') !== 1 || preg_match('/'.self::WHITESPACE.'/u', $original) !== 0) {
            throw new GlobalIdentityKeyException('Invalid global email address.');
        }

        [$local, $domain] = explode('@', $original, 2);
        if (strlen($local) > 64 || preg_match(self::LOCAL_PART, $local) !== 1 || $domain === '') {
            throw new GlobalIdentityKeyException('Invalid global email address.');
        }

        $ascii = idn_to_ascii(
            $domain,
            IDNA_USE_STD3_RULES | IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ,
            INTL_IDNA_VARIANT_UTS46,
            $result,
        );
        if ($ascii === false || ! is_array($result) || ($result['errors'] ?? null) !== 0 || str_ends_with($ascii, '.')
            || strlen($ascii) > 253 || strlen($local) + 1 + strlen($ascii) > 254) {
            throw new GlobalIdentityKeyException('Invalid global email address.');
        }

        // ICU rejects invalid labels and every A-label longer than 63 octets above.
        return new self($original, $local.'@'.strtolower($ascii));
    }

    public function original(): string
    {
        return $this->original;
    }

    public function normalized(): string
    {
        return strtolower($this->transport);
    }

    public function transport(): string
    {
        return $this->transport;
    }

    /** @return array{} */
    public function __debugInfo(): array
    {
        return [];
    }
}

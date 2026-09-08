<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\SecurityEvents;

use InvalidArgumentException;

final readonly class SecurityActorReference
{
    private const DERIVATION_DOMAIN = "secpal:security-event:actor:v1\0";

    private const PREFIX = 'hmac-sha256:v1:';

    private function __construct(private string $value) {}

    public static function fromIdentifier(string $identifier): self
    {
        $key = config('app.key');

        if (! is_string($key) || $key === '') {
            throw new InvalidArgumentException('The application key is unavailable for actor-reference derivation.');
        }

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            if ($decoded === false || $decoded === '') {
                throw new InvalidArgumentException('The application key is invalid for actor-reference derivation.');
            }

            $key = $decoded;
        }

        return new self(self::PREFIX.hash_hmac('sha256', self::DERIVATION_DOMAIN.$identifier, $key));
    }

    public static function fromValue(string $value): self
    {
        if (preg_match('/\A'.preg_quote(self::PREFIX, '/').'[a-f0-9]{64}\z/', $value) !== 1) {
            throw new InvalidArgumentException('The actor reference is malformed.');
        }

        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }
}

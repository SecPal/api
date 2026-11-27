<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services\GlobalIdentity;

final class IdentityDisplay
{
    /** @var array<string, string> */
    private array $addresses = [];

    public function __construct(private readonly GlobalEmailRepository $email) {}

    public function address(string $userId, int $version): string
    {
        return $this->addresses[$userId.':'.$version] ??= $this->email->withEmail(
            $userId,
            $version,
            false,
            static fn (#[\SensitiveParameter] string $email): string => $email,
        );
    }

    /** @return array{} */
    public function __debugInfo(): array
    {
        return [];
    }
}

<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services\GlobalIdentity;

use App\Exceptions\GlobalIdentityKeyException;

final readonly class IdentityOperationSubject implements GlobalIdentityOperationSubject
{
    public function __construct(private GlobalEmailRepository $email) {}

    public function isCurrent(GlobalIdentityOperationContext $operation): bool
    {
        return $operation->userId !== null && $operation->emailVersion !== null
            && $this->email->checkEmailVersionForDispatch($operation->userId, $operation->emailVersion);
    }

    public function recipient(GlobalIdentityOperationContext $operation): string
    {
        if ($operation->userId === null || $operation->emailVersion === null) {
            throw new GlobalIdentityKeyException('Identity operation recipient is unavailable.');
        }

        return $this->email->withEmail(
            $operation->userId,
            $operation->emailVersion,
            true,
            static fn (#[\SensitiveParameter] string $email): string => $email,
        );
    }
}

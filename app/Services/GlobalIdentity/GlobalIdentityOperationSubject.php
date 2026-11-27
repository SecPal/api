<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services\GlobalIdentity;

interface GlobalIdentityOperationSubject
{
    /** Verify the subject and exact version using the operation's PostgreSQL transaction. */
    public function isCurrent(GlobalIdentityOperationContext $operation): bool;

    /** Resolve the authorized original recipient during preparation outside the transaction. */
    public function recipient(GlobalIdentityOperationContext $operation): string;
}

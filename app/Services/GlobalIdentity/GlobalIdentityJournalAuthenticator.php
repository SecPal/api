<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services\GlobalIdentity;

use SensitiveParameter;

interface GlobalIdentityJournalAuthenticator
{
    /** Authenticate the canonical checkpoint with the independently retained journal authority. */
    public function verifies(string $checkpoint, #[SensitiveParameter] string $authenticator): bool;
}

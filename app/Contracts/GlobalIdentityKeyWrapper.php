<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Contracts;

use App\Services\GlobalIdentity\GlobalIdentityKeyReference;
use App\Services\GlobalIdentity\GlobalIdentityWorkingKeyContext;
use App\Services\GlobalIdentity\WrappedGlobalIdentityKey;
use SensitiveParameter;

interface GlobalIdentityKeyWrapper
{
    public function wrap(
        #[SensitiveParameter] string $key,
        GlobalIdentityWorkingKeyContext $workingKey,
        GlobalIdentityKeyReference $root,
    ): WrappedGlobalIdentityKey;

    public function unwrap(#[SensitiveParameter] WrappedGlobalIdentityKey $wrapped, GlobalIdentityWorkingKeyContext $workingKey): string;
}

<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services\GlobalIdentity;

use SensitiveParameter;

interface GlobalIdentityDeliveryRenderer
{
    public function render(GlobalIdentityOperationContext $operation, #[SensitiveParameter] string $token): string;
}

<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Enums;

enum LegalHoldAuditOperation: string
{
    case Create = 'create';
    case Attach = 'attach';
    case Detach = 'detach';
    case Release = 'release';
}

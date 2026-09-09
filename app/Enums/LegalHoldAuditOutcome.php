<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Enums;

enum LegalHoldAuditOutcome: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}

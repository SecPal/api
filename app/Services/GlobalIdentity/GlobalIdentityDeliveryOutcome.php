<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services\GlobalIdentity;

enum GlobalIdentityDeliveryOutcome: string
{
    case Accepted = 'accepted';
    case RetryableRejection = 'retryable_rejection';
    case TerminalRejection = 'terminal_rejection';
    case Unknown = 'unknown';
    case Absent = 'absent';
}

<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services\GlobalIdentity;

enum GlobalIdentityDeliveryState: string
{
    case Pending = 'pending';
    case Claimed = 'claimed';
    case DispatchCommitted = 'dispatch_committed';
    case Delivered = 'delivered';
    case Retryable = 'retryable_failed';
    case Unknown = 'delivery_unknown';
    case Terminal = 'terminal';
}

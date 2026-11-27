<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services\GlobalIdentity;

enum GlobalIdentityTerminalReason: string
{
    case Consumed = 'consumed';
    case Expired = 'expired';
    case Revoked = 'revoked';
    case Cancelled = 'cancelled';
    case Superseded = 'superseded';
    case IntegrityFailure = 'integrity_failure';
    case TransportRejected = 'transport_rejected';
    case RestoreTombstone = 'restore_tombstone';
    case SecurityRevoked = 'security_revoked';
    case KeyUnavailable = 'key_unavailable';
}

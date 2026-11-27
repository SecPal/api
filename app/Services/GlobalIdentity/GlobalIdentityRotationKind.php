<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services\GlobalIdentity;

enum GlobalIdentityRotationKind: string
{
    case Root = 'root';
    case Working = 'working';
    case DeliveryCompromise = 'delivery_compromise';
    case DeliveryLoss = 'delivery_loss';
}

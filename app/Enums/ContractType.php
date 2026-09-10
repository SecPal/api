<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Enums;

enum ContractType: string
{
    case Permanent = 'permanent';
    case Temporary = 'temporary';
    case OneTime = 'one_time';
    case Recurring = 'recurring';
}

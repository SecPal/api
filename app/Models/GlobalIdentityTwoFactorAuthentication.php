<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Models;

use App\Casts\GlobalIdentityMfaMaterial;
use Laragear\TwoFactor\Models\TwoFactorAuthentication;

final class GlobalIdentityTwoFactorAuthentication extends TwoFactorAuthentication
{
    protected $table = 'two_factor_authentications';

    protected $hidden = ['shared_secret', 'recovery_codes', 'safe_devices'];

    protected function casts(): array
    {
        return [
            'shared_secret' => GlobalIdentityMfaMaterial::class,
            'recovery_codes' => GlobalIdentityMfaMaterial::class,
        ];
    }
}

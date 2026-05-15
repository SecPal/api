<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Requests\Concerns;

use App\Support\AddressDataConfig;

trait InteractsWithAddressLimit
{
    public function limitResolved(): int
    {
        $default = AddressDataConfig::int('address_data.default_limit', 20);
        $limit = $this->integer('limit', $default);
        $cap = AddressDataConfig::int('address_data.max_limit', 50);

        return max(1, min($limit, $cap));
    }
}

<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantKey;
use Illuminate\Support\Facades\DB;

final class TenantProvisioner
{
    public function create(): TenantKey
    {
        return DB::transaction(function (): TenantKey {
            $tenant = Tenant::query()->create();

            return $tenant->key()->create(TenantKey::generateEnvelopeKeys($tenant->id));
        });
    }
}

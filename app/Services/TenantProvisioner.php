<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantKey;

final class TenantProvisioner
{
    public function __construct(private readonly TenantKekRegistry $kek) {}

    public function create(): TenantKey
    {
        return $this->kek->withLease(function (): TenantKey {
            $this->kek->initialize();

            return $this->attach(Tenant::query()->create());
        }, exclusive: true);
    }

    public function setup(): ?TenantKey
    {
        return $this->kek->withLease(function (): ?TenantKey {
            $this->kek->initialize();

            return TenantKey::query()->exists() ? null : $this->attach(Tenant::query()->create());
        }, exclusive: true);
    }

    private function attach(Tenant $tenant): TenantKey
    {
        return $tenant->key()->create(TenantKey::generateEnvelopeKeys($tenant->id));
    }
}

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
            $this->kek->initialize(requireUninitialized: ! Tenant::query()->exists());

            return $this->attach(Tenant::query()->create());
        }, exclusive: true);
    }

    public function setup(): ?TenantKey
    {
        return $this->kek->withLease(function (): ?TenantKey {
            if (Tenant::query()->exists()) {
                $this->kek->verifyTenantInventory();

                return null;
            }

            $this->kek->initialize(requireUninitialized: true);

            return $this->attach(Tenant::query()->create());
        }, exclusive: true);
    }

    private function attach(Tenant $tenant): TenantKey
    {
        return $tenant->key()->create(TenantKey::generateEnvelopeKeys($tenant->id));
    }
}

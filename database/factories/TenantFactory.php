<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\TenantKey;
use App\Services\TenantKekRegistry;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Tenant> */
final class TenantFactory extends Factory
{
    public function definition(): array
    {
        return [];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Tenant $tenant): void {
            $kek = app(TenantKekRegistry::class);
            $kek->withLease(function () use ($tenant, $kek): void {
                if (app()->environment('testing')) {
                    TenantKey::ensureKekExists();
                }
                $kek->initialize();
                $tenant->key()->create(TenantKey::generateEnvelopeKeys($tenant->id));
            }, exclusive: true);
        });
    }
}

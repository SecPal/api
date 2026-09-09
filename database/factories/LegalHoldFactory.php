<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LegalHoldStatus;
use App\Models\LegalHold;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<LegalHold> */
class LegalHoldFactory extends Factory
{
    protected $model = LegalHold::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => TenantKey::factory(),
            'case_reference' => 'CASE-'.Str::upper(Str::random(12)),
            'status' => LegalHoldStatus::Active,
            'justification' => fake()->sentence(),
            'created_by_user_id' => fn (array $attributes): string => User::factory()
                ->create(['tenant_id' => $attributes['tenant_id']])
                ->id,
            'created_by_identity_id' => function (array $attributes): string {
                $creatorId = $attributes['created_by_user_id'] ?? null;

                if (! is_string($creatorId)) {
                    throw new \LogicException('Legal hold factories require a creator identity.');
                }

                return $creatorId;
            },
            'released_at' => null,
            'released_by_user_id' => null,
            'released_by_identity_id' => null,
            'release_justification' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => [
            'status' => LegalHoldStatus::Active,
            'released_at' => null,
            'released_by_user_id' => null,
            'released_by_identity_id' => null,
            'release_justification' => null,
        ]);
    }

    public function released(): static
    {
        return $this->state(fn (): array => [
            'status' => LegalHoldStatus::Released,
            'released_at' => now(),
            'released_by_user_id' => null,
            'released_by_identity_id' => null,
            'release_justification' => fake()->sentence(),
        ])->afterMaking(function (LegalHold $hold): void {
            $releaser = User::factory()->create(['tenant_id' => $hold->tenant_id]);
            $hold->released_by_user_id = $releaser->id;
            $hold->released_by_identity_id = $releaser->id;
        });
    }
}

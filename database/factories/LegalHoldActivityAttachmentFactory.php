<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Activity;
use App\Models\LegalHold;
use App\Models\LegalHoldActivityAttachment;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LegalHoldActivityAttachment> */
class LegalHoldActivityAttachmentFactory extends Factory
{
    protected $model = LegalHoldActivityAttachment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => TenantKey::factory(),
            'legal_hold_id' => fn (array $attributes): string => LegalHold::factory()
                ->active()
                ->create(['tenant_id' => $attributes['tenant_id']])
                ->id,
            'activity_id' => fn (array $attributes): int => Activity::factory()
                ->create(['tenant_id' => $attributes['tenant_id']])
                ->id,
            'activity_identity_id' => function (array $attributes): int {
                $activityId = $attributes['activity_id'] ?? null;

                if (! is_int($activityId)) {
                    throw new \LogicException('Legal hold attachment factories require an Activity identity.');
                }

                return $activityId;
            },
            'attached_by_user_id' => fn (array $attributes): string => User::factory()
                ->create(['tenant_id' => $attributes['tenant_id']])
                ->id,
            'attached_by_identity_id' => function (array $attributes): string {
                $actorId = $attributes['attached_by_user_id'] ?? null;

                if (! is_string($actorId)) {
                    throw new \LogicException('Legal hold attachment factories require an actor identity.');
                }

                return $actorId;
            },
            'attached_at' => now()->subMinute(),
            'detached_at' => null,
            'detached_by_user_id' => null,
            'detached_by_identity_id' => null,
            'detachment_justification' => null,
        ];
    }

    public function attached(): static
    {
        return $this->state(fn (): array => [
            'detached_at' => null,
            'detached_by_user_id' => null,
            'detached_by_identity_id' => null,
            'detachment_justification' => null,
        ]);
    }

    public function detached(): static
    {
        return $this->state(fn (): array => [
            'detached_at' => now(),
            'detached_by_user_id' => null,
            'detached_by_identity_id' => null,
            'detachment_justification' => fake()->sentence(),
        ])->afterMaking(function (LegalHoldActivityAttachment $attachment): void {
            $detacher = User::factory()->create(['tenant_id' => $attachment->tenant_id]);
            $attachment->detached_by_user_id = $detacher->id;
            $attachment->detached_by_identity_id = $detacher->id;
        });
    }
}

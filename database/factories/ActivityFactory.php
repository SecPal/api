<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Database\Factories;

use App\Models\Activity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for Activity model (custom activity log with hash chain).
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Activity>
 */
class ActivityFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Activity>
     */
    protected $model = Activity::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => null, // Must be set explicitly in tests
            'organizational_unit_id' => null,
            'log_name' => 'default',
            'description' => fake()->sentence(),
            'subject_type' => null,
            'subject_id' => null,
            'causer_type' => null,
            'causer_id' => null,
            'properties' => null,
            'batch_uuid' => null,
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'previous_hash' => null,
            'event_hash' => null,
            'merkle_root' => null,
            'merkle_batch_id' => null,
            'merkle_proof' => null,
            'ots_proof' => null,
            'ots_submitted_at' => null,
            'ots_confirmed_at' => null,
            'is_orphaned_genesis' => false,
            'orphaned_reason' => null,
            'orphaned_at' => null,
        ];
    }
}

<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AndroidEnrollmentSession;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<AndroidEnrollmentSession>
 */
class AndroidEnrollmentSessionFactory extends Factory
{
    protected $model = AndroidEnrollmentSession::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $plainToken = Str::random(64);

        return [
            'tenant_id' => TenantKey::factory(),
            'created_by' => User::factory()->state(fn (array $attributes): array => [
                'tenant_id' => $attributes['tenant_id'],
            ]),
            'device_label' => fake()->words(3, true),
            'enrollment_mode' => 'device_owner',
            'update_channel' => 'managed_device',
            'release_metadata_url' => 'https://apk.secpal.app/android/channels/managed_device/latest.json',
            'provisioning_profile' => [
                'kiosk_mode_enabled' => true,
                'lock_task_enabled' => true,
                'allow_phone' => false,
                'allow_sms' => false,
                'prefer_gesture_navigation' => true,
                'allowed_packages' => [],
            ],
            'bootstrap_token' => Hash::make($plainToken),
            'bootstrap_token_lookup_hash' => hash('sha256', $plainToken),
            'bootstrap_token_expires_at' => now()->addMinutes(15),
            'notes' => fake()->sentence(),
        ];
    }
}

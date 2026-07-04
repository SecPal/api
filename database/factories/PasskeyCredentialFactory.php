<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use ParagonIE\ConstantTime\Base64UrlSafe;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PasskeyCredential>
 */
class PasskeyCredentialFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'credential_id' => Str::random(32),
            'label' => fake()->words(2, true),
            'transports' => ['internal'],
            'authenticator_attachment' => null,
            'aaguid' => null,
            'attestation_type' => 'none',
            'credential_public_key' => Base64UrlSafe::encodeUnpadded(random_bytes(64)),
            'user_handle' => Base64UrlSafe::encodeUnpadded(random_bytes(16)),
            'counter' => 0,
            'user_verified' => false,
            'backup_eligible' => false,
            'backup_state' => false,
            'last_used_at' => null,
        ];
    }
}

<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Database\Factories;

use App\Models\TenantKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for creating TenantKey model instances for testing.
 *
 * @extends Factory<TenantKey>
 */
final class TenantKeyFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<TenantKey>
     */
    protected $model = TenantKey::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Ensure KEK exists for testing
        if (! file_exists(TenantKey::getKekPath())) {
            TenantKey::generateKek();
        }

        // Generate envelope keys using the model's static method
        return TenantKey::generateEnvelopeKeys();
    }

    /**
     * Configure the factory with a specific key version.
     */
    public function version(int $version): static
    {
        return $this->state(fn (array $attributes) => [
            'key_version' => $version,
        ]);
    }
}

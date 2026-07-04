<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Person;
use App\Models\TenantKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Person>
 */
final class PersonFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Person>
     */
    protected $model = Person::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Resolve the latest tenant so factory defaults follow the current test context.
        $tenant = TenantKey::query()->latest('id')->first();
        if (! $tenant) {
            TenantKey::ensureKekExists();
            $keys = TenantKey::generateEnvelopeKeys();
            $tenant = TenantKey::create($keys);
        }

        return [
            'tenant_id' => $tenant->id,
            'email_plain' => fake()->unique()->safeEmail(),
            'phone_plain' => fake()->phoneNumber(),
        ];
    }

    /**
     * Indicate that the person should have a specific email.
     */
    public function withEmail(string $email): static
    {
        return $this->state(fn (array $attributes) => [
            'email_plain' => $email,
        ]);
    }
}

<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Database\Factories;

use App\Services\GlobalIdentity\GlobalIdentityAccounts;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * Note: the latest TenantKey is resolved for each user creation, which can
     * cause N+1 issues when creating many users. For bulk operations, explicitly
     * pass tenant_id: User::factory()->create(['tenant_id' => $tenantId])
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $tenant = \App\Models\TenantKey::query()->latest('id')->first();

        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'preferred_locale' => null,
            'tenant_id' => $tenant !== null ? $tenant->id : \App\Models\TenantKey::factory(),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /** @param Collection<int, \App\Models\User> $results */
    protected function store(Collection $results): void
    {
        if (app()->environment('testing')) {
            \Tests\Support\GlobalIdentityTestFixture::prepare();
        }
        foreach ($results as $model) {
            $address = $model->getAttribute('email');
            if (! is_string($address)) {
                throw new \LogicException('An identity fixture requires a synthetic email.');
            }
            $model->offsetUnset('email');
            $account = app(GlobalIdentityAccounts::class)->create($model, $address);
            $model->setRawAttributes($account->getAttributes(), true);
            $model->setConnection($account->getConnectionName());
            $model->exists = true;
            $model->wasRecentlyCreated = true;
            $this->createChildren($model);
        }
    }
}

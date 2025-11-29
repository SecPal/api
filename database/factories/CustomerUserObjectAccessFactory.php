<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Database\Factories;

use App\Models\CustomerUserObjectAccess;
use App\Models\SecPalObject;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for creating CustomerUserObjectAccess model instances for testing.
 *
 * @extends Factory<CustomerUserObjectAccess>
 */
class CustomerUserObjectAccessFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<CustomerUserObjectAccess>
     */
    protected $model = CustomerUserObjectAccess::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fake()->randomNumber(5),
            'user_id' => User::factory(),
            'object_id' => SecPalObject::factory(),
            'allowed_actions' => fake()->randomElements(
                ['view_guard_book', 'view_incidents', 'view_patrol_logs', 'view_reports', 'export_reports'],
                fake()->numberBetween(1, 4)
            ),
        ];
    }

    /**
     * Configure the factory for a specific user.
     */
    public function forUser(User|string $user): static
    {
        $userId = $user instanceof User ? $user->id : $user;

        return $this->state(fn (array $attributes) => [
            'user_id' => $userId,
        ]);
    }

    /**
     * Configure the factory for a specific object.
     */
    public function forObject(SecPalObject|string $object): static
    {
        $objectId = $object instanceof SecPalObject ? $object->id : $object;

        return $this->state(fn (array $attributes) => [
            'object_id' => $objectId,
        ]);
    }

    /**
     * Configure the factory with a specific tenant.
     */
    public function forTenant(int $tenantId): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => $tenantId,
        ]);
    }

    /**
     * Configure the factory with full read access (all view actions).
     */
    public function fullReadAccess(): static
    {
        return $this->state(fn (array $attributes) => [
            'allowed_actions' => ['view_guard_book', 'view_incidents', 'view_patrol_logs', 'view_reports'],
        ]);
    }

    /**
     * Configure the factory with guard book view access only.
     */
    public function viewGuardBookOnly(): static
    {
        return $this->state(fn (array $attributes) => [
            'allowed_actions' => ['view_guard_book'],
        ]);
    }

    /**
     * Configure the factory with specific allowed actions.
     *
     * @param  array<string>  $actions
     */
    public function withActions(array $actions): static
    {
        return $this->state(fn (array $attributes) => [
            'allowed_actions' => $actions,
        ]);
    }
}

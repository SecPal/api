<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerUserAccess;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for creating CustomerUserAccess model instances for testing.
 *
 * @extends Factory<CustomerUserAccess>
 */
class CustomerUserAccessFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<CustomerUserAccess>
     */
    protected $model = CustomerUserAccess::class;

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
            'customer_id' => Customer::factory(),
            'access_level' => fake()->randomElement(['corporate_wide', 'regional', 'local']),
            'include_descendants' => fake()->boolean(70), // 70% include descendants
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
     * Configure the factory for a specific customer.
     */
    public function forCustomer(Customer|string $customer): static
    {
        $customerId = $customer instanceof Customer ? $customer->id : $customer;

        return $this->state(fn (array $attributes) => [
            'customer_id' => $customerId,
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
     * Configure the factory for corporate-wide access.
     */
    public function corporateWide(): static
    {
        return $this->state(fn (array $attributes) => [
            'access_level' => 'corporate_wide',
            'include_descendants' => true,
        ]);
    }

    /**
     * Configure the factory for regional access.
     */
    public function regional(): static
    {
        return $this->state(fn (array $attributes) => [
            'access_level' => 'regional',
            'include_descendants' => true,
        ]);
    }

    /**
     * Configure the factory for local access.
     */
    public function local(): static
    {
        return $this->state(fn (array $attributes) => [
            'access_level' => 'local',
            'include_descendants' => false,
        ]);
    }

    /**
     * Configure the factory to include descendants.
     */
    public function withDescendants(): static
    {
        return $this->state(fn (array $attributes) => [
            'include_descendants' => true,
        ]);
    }

    /**
     * Configure the factory to exclude descendants.
     */
    public function withoutDescendants(): static
    {
        return $this->state(fn (array $attributes) => [
            'include_descendants' => false,
        ]);
    }
}

<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Database\Factories;

use App\Models\OrganizationalUnit;
use App\Models\User;
use App\Models\UserInternalOrganizationalScope;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserInternalOrganizationalScope>
 */
class UserInternalOrganizationalScopeFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<UserInternalOrganizationalScope>
     */
    protected $model = UserInternalOrganizationalScope::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'organizational_unit_id' => OrganizationalUnit::factory(),
            'access_level' => fake()->randomElement(UserInternalOrganizationalScope::ACCESS_LEVELS),
            'include_descendants' => true,
        ];
    }

    /**
     * Configure the factory with read access level.
     */
    public function readAccess(): static
    {
        return $this->state(fn (array $attributes) => [
            'access_level' => 'read',
        ]);
    }

    /**
     * Configure the factory with write access level.
     */
    public function writeAccess(): static
    {
        return $this->state(fn (array $attributes) => [
            'access_level' => 'write',
        ]);
    }

    /**
     * Configure the factory with manage access level.
     */
    public function manageAccess(): static
    {
        return $this->state(fn (array $attributes) => [
            'access_level' => 'manage',
        ]);
    }

    /**
     * Configure the factory with admin access level.
     */
    public function adminAccess(): static
    {
        return $this->state(fn (array $attributes) => [
            'access_level' => 'admin',
        ]);
    }

    /**
     * Configure the factory to not include descendants.
     */
    public function withoutDescendants(): static
    {
        return $this->state(fn (array $attributes) => [
            'include_descendants' => false,
        ]);
    }

    /**
     * Configure the factory for a specific user.
     */
    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }

    /**
     * Configure the factory for a specific organizational unit.
     */
    public function forUnit(OrganizationalUnit $unit): static
    {
        return $this->state(fn (array $attributes) => [
            'organizational_unit_id' => $unit->id,
        ]);
    }
}

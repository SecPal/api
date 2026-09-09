<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ContentLocale;
use App\Enums\WorkInstructionStatus;
use App\Models\TenantKey;
use App\Models\User;
use App\Models\WorkInstruction;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<WorkInstruction> */
class WorkInstructionFactory extends Factory
{
    protected $model = WorkInstruction::class;

    public function configure(): static
    {
        return $this->afterCreating(function (WorkInstruction $instruction): void {
            if ($instruction->status === WorkInstructionStatus::Published
                && $instruction->published_by_user_id === null) {
                $instruction->published_by_user_id = User::factory()->create([
                    'tenant_id' => $instruction->tenant_id,
                ])->id;
            }

            if ($instruction->status === WorkInstructionStatus::Archived) {
                $instruction->published_by_user_id ??= User::factory()->create([
                    'tenant_id' => $instruction->tenant_id,
                ])->id;
                $instruction->archived_by_user_id ??= User::factory()->create([
                    'tenant_id' => $instruction->tenant_id,
                ])->id;
            }

            if ($instruction->isDirty()) {
                $instruction->save();
            }
        });
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => TenantKey::factory(),
            'instruction_number' => 'WI-'.Str::upper(Str::random(12)),
            'title' => fake()->sentence(4),
            'body' => fake()->paragraphs(3, true),
            'locale' => fake()->randomElement(ContentLocale::cases()),
            'status' => WorkInstructionStatus::Draft,
            'published_at' => null,
            'published_by_user_id' => null,
            'archived_at' => null,
            'archived_by_user_id' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => WorkInstructionStatus::Draft,
            'published_at' => null,
            'published_by_user_id' => null,
            'archived_at' => null,
            'archived_by_user_id' => null,
        ]);
    }

    public function inReview(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => WorkInstructionStatus::InReview,
            'published_at' => null,
            'published_by_user_id' => null,
            'archived_at' => null,
            'archived_by_user_id' => null,
        ]);
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => WorkInstructionStatus::Published,
            'published_at' => now()->subMinute(),
            'published_by_user_id' => null,
            'archived_at' => null,
            'archived_by_user_id' => null,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => WorkInstructionStatus::Archived,
            'published_at' => now()->subDay(),
            'published_by_user_id' => null,
            'archived_at' => now(),
            'archived_by_user_id' => null,
        ]);
    }
}

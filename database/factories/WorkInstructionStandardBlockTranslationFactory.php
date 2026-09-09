<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ContentLocale;
use App\Models\WorkInstructionStandardBlock;
use App\Models\WorkInstructionStandardBlockTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WorkInstructionStandardBlockTranslation> */
class WorkInstructionStandardBlockTranslationFactory extends Factory
{
    protected $model = WorkInstructionStandardBlockTranslation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'work_instruction_standard_block_id' => WorkInstructionStandardBlock::factory(),
            'locale' => fake()->randomElement(ContentLocale::cases()),
            'title' => fake()->sentence(4),
            'body' => fake()->paragraphs(2, true),
        ];
    }
}

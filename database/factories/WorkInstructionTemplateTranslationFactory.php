<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ContentLocale;
use App\Models\WorkInstructionTemplate;
use App\Models\WorkInstructionTemplateTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WorkInstructionTemplateTranslation> */
class WorkInstructionTemplateTranslationFactory extends Factory
{
    protected $model = WorkInstructionTemplateTranslation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'work_instruction_template_id' => WorkInstructionTemplate::factory(),
            'tenant_id' => fn (array $attributes): int => WorkInstructionTemplate::query()
                ->whereKey($attributes['work_instruction_template_id'])
                ->firstOrFail()
                ->tenant_id,
            'locale' => fake()->randomElement(ContentLocale::cases()),
            'title' => fake()->sentence(4),
            'body' => fake()->paragraphs(3, true),
        ];
    }
}

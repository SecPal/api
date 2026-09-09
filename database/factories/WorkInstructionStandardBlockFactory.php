<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Database\Factories;

use App\Models\WorkInstructionStandardBlock;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<WorkInstructionStandardBlock> */
class WorkInstructionStandardBlockFactory extends Factory
{
    protected $model = WorkInstructionStandardBlock::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['key' => 'standard-block-'.Str::lower(Str::random(16))];
    }
}

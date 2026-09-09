<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Database\Factories;

use App\Models\TenantKey;
use App\Models\WorkInstructionTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WorkInstructionTemplate> */
class WorkInstructionTemplateFactory extends Factory
{
    protected $model = WorkInstructionTemplate::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['tenant_id' => TenantKey::factory()];
    }
}

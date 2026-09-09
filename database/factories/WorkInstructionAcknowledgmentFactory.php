<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Employee;
use App\Models\TenantKey;
use App\Models\User;
use App\Models\WorkInstruction;
use App\Models\WorkInstructionAcknowledgment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WorkInstructionAcknowledgment> */
class WorkInstructionAcknowledgmentFactory extends Factory
{
    protected $model = WorkInstructionAcknowledgment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => TenantKey::factory(),
            'work_instruction_id' => fn (array $attributes): string => WorkInstruction::factory()
                ->published()
                ->create(['tenant_id' => $attributes['tenant_id']])
                ->id,
            'employee_id' => fn (array $attributes): string => Employee::factory()
                ->create(['tenant_id' => $attributes['tenant_id']])
                ->id,
            'acknowledged_by_user_id' => fn (array $attributes): string => User::factory()
                ->create(['tenant_id' => $attributes['tenant_id']])
                ->id,
            'acknowledged_at' => now(),
        ];
    }
}

<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Services;

use App\Models\Activity;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Contracts\Support\Arrayable;

class ActivityCauserContextService
{
    public function preserveForEmployee(Employee $employee, User $user): void
    {
        Activity::query()
            ->where('tenant_id', $employee->tenant_id)
            ->where('causer_type', User::class)
            ->where('causer_id', $user->id)
            ->get()
            ->each(function (Activity $activity) use ($employee): void {
                $activity->forceFill([
                    'properties' => array_merge($this->propertiesAsArray($activity->properties), [
                        'causer_employee_id' => $employee->id,
                        'causer_employee_organizational_unit_id' => $employee->organizational_unit_id,
                        'causer_employee_management_level' => $employee->management_level,
                    ]),
                ])->saveQuietly();
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function propertiesAsArray(mixed $properties): array
    {
        if ($properties instanceof Arrayable) {
            $properties = $properties->toArray();
        }

        if (! is_array($properties)) {
            return [];
        }

        $normalized = [];

        foreach ($properties as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }
}

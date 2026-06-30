<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Services;

use App\Models\Activity;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ActivityCauserContextService
{
    public function preserveForEmployee(Employee $employee, User $user): void
    {
        // Mirror the write-time capture invariant (see Activity::captureCauserEmployeeContext):
        // an employee only attests context for activities recorded in its own organizational
        // unit. Backfilling across units (or onto global activities) would stamp a false
        // organizational-unit/rank snapshot and desync activity-log index/detail visibility.
        if ($employee->organizational_unit_id === null) {
            return;
        }

        Activity::query()
            ->where('tenant_id', $employee->tenant_id)
            ->where('causer_type', User::class)
            ->where('causer_id', $user->id)
            ->where('organizational_unit_id', $employee->organizational_unit_id)
            ->where(function ($query): void {
                $query->whereNull('causer_employee_id')
                    ->orWhereNull('causer_employee_organizational_unit_id')
                    ->orWhereNull('causer_employee_management_level');
            })
            ->select([
                'id',
                'causer_employee_id',
                'causer_employee_organizational_unit_id',
                'causer_employee_management_level',
            ])
            ->chunkById(500, function (Collection $activities) use ($employee): void {
                $activities->each(function (Activity $activity) use ($employee): void {
                    $attributes = [];

                    if ($activity->causer_employee_id === null) {
                        $attributes['causer_employee_id'] = $employee->id;
                    }

                    if ($activity->causer_employee_organizational_unit_id === null) {
                        $attributes['causer_employee_organizational_unit_id'] = $employee->organizational_unit_id;
                    }

                    if ($activity->causer_employee_management_level === null) {
                        $attributes['causer_employee_management_level'] = $employee->management_level;
                    }

                    if ($attributes !== []) {
                        DB::table('activity_log')
                            ->where('id', $activity->id)
                            ->update($attributes);
                    }
                });
            });
    }
}

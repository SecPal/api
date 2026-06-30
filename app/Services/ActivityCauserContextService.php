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
        Activity::query()
            ->where('tenant_id', $employee->tenant_id)
            ->where('causer_type', User::class)
            ->where('causer_id', $user->id)
            ->select('id')
            ->chunkById(500, function (Collection $activities) use ($employee): void {
                $activityIds = $activities->pluck('id')->all();

                if ($activityIds === []) {
                    return;
                }

                DB::table('activity_log')
                    ->whereIn('id', $activityIds)
                    ->update([
                        'causer_employee_id' => $employee->id,
                        'causer_employee_organizational_unit_id' => $employee->organizational_unit_id,
                        'causer_employee_management_level' => $employee->management_level,
                    ]);
            });
    }
}

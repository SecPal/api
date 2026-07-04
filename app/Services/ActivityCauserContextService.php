<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace App\Services;

use App\Models\Employee;
use App\Models\User;

class ActivityCauserContextService
{
    public function preserveForEmployee(Employee $employee, User $user): void
    {
        // Historical activity rows without dedicated causer context columns do not
        // have a trustworthy per-event rank source. Deprovisioning must not stamp
        // deletion-time employee state onto old events.
    }
}

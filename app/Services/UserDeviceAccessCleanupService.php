<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserDeviceAccessCleanupService
{
    public function deletePushRegistrations(User $user): void
    {
        DB::table('push_device_registrations')
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->delete();
    }
}

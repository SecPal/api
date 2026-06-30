<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserDeviceAccessCleanupService
{
    public function revokePendingAndroidEnrollmentSessionsAndDeletePushRegistrations(User $user, string $revocationReason): void
    {
        DB::table('push_device_registrations')
            ->where('user_id', $user->id)
            ->delete();

        $revokedAt = now();

        DB::table('android_enrollment_sessions')
            ->where('created_by', $user->id)
            ->whereNull('revoked_at')
            ->whereNull('exchanged_at')
            ->update([
                'revoked_at' => $revokedAt,
                'revocation_reason' => $revocationReason,
                'updated_at' => $revokedAt,
            ]);
    }
}

<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE android_enrollment_sessions DROP CONSTRAINT IF EXISTS android_enrollment_sessions_created_by_foreign');
        DB::statement('ALTER TABLE android_enrollment_sessions ALTER COLUMN created_by DROP NOT NULL');
        DB::statement('ALTER TABLE android_enrollment_sessions ADD CONSTRAINT android_enrollment_sessions_created_by_foreign FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL');
    }

    public function down(): void
    {
        DB::table('android_enrollment_sessions')
            ->whereNull('created_by')
            ->delete();

        DB::statement('ALTER TABLE android_enrollment_sessions DROP CONSTRAINT IF EXISTS android_enrollment_sessions_created_by_foreign');
        DB::statement('ALTER TABLE android_enrollment_sessions ALTER COLUMN created_by SET NOT NULL');
        DB::statement('ALTER TABLE android_enrollment_sessions ADD CONSTRAINT android_enrollment_sessions_created_by_foreign FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE');
    }
};

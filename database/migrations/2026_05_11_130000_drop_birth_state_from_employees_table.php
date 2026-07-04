<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employees')) {
            return;
        }

        if (! Schema::hasColumn('employees', 'birth_state')) {
            return;
        }

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn('birth_state');
        });
    }

    public function down(): void
    {
        // Intentionally irreversible in 0.x: do not recreate removed compatibility columns.
    }
};

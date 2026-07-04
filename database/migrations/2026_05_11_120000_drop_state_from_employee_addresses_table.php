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
        if (! Schema::hasTable('employee_addresses')) {
            return;
        }

        if (! Schema::hasColumn('employee_addresses', 'state')) {
            return;
        }

        Schema::table('employee_addresses', function (Blueprint $table): void {
            $table->dropColumn('state');
        });
    }

    public function down(): void
    {
        // Intentionally irreversible in 0.x: do not recreate removed compatibility columns.
    }
};

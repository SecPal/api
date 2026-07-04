<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('employee_onboarding_tokens', function (Blueprint $table) {
            $table->string('token_lookup_hash', 64)->nullable()->after('token');
            $table->index('token_lookup_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employee_onboarding_tokens', function (Blueprint $table) {
            $table->dropIndex(['token_lookup_hash']);
            $table->dropColumn('token_lookup_hash');
        });
    }
};

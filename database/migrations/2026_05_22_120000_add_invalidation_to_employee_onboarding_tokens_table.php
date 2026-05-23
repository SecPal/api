<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add columns that capture security-driven invalidations of magic links.
     *
     * Distinct from `completed_at` (= successful onboarding) and `expires_at`
     * (= natural TTL): an invalidated token is one we burned because the
     * recipient failed an identity proof (wrong date of birth, name too
     * different from the HR record, …). Recording these separately preserves
     * the audit trail and lets us answer "did this link ever succeed?" vs
     * "was it killed by a failed attempt?".
     */
    public function up(): void
    {
        Schema::table('employee_onboarding_tokens', function (Blueprint $table) {
            $table->timestamp('invalidated_at')->nullable()->after('completed_user_agent');
            $table->ipAddress('invalidated_from_ip')->nullable()->after('invalidated_at');
            $table->string('invalidated_user_agent', 500)->nullable()->after('invalidated_from_ip');
            $table->string('invalidation_reason', 64)->nullable()->after('invalidated_user_agent');

            $table->index('invalidated_at');
        });
    }

    public function down(): void
    {
        Schema::table('employee_onboarding_tokens', function (Blueprint $table) {
            $table->dropIndex(['invalidated_at']);
            $table->dropColumn([
                'invalidated_at',
                'invalidated_from_ip',
                'invalidated_user_agent',
                'invalidation_reason',
            ]);
        });
    }
};

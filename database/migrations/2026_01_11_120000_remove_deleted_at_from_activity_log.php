<?php

/**
 * SPDX-FileCopyrightText: 2026 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remove deleted_at column from activity_log table.
 *
 * The SoftDeletes trait was removed in favor of direct hard delete
 * for GDPR Article 17 compliance (right to erasure - "unverzüglich").
 *
 * Issue #443 implemented direct archiving with hard delete, making
 * the deleted_at column obsolete.
 *
 * @see Issue #447 Remove SoftDeletes from Activity model
 * @see Issue #443 GDPR-compliant direct archiving
 * @see Epic #385 Activity Logging & Audit Trail Strategy (Phase 4)
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->softDeletes();
        });
    }
};

<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add event column to activity_log table.
 *
 * The event column stores the Eloquent event name (created, updated, deleted, etc.)
 * and is required by Spatie Laravel Activity Log v4 for automatic model event logging.
 *
 * @see Issue #388 PR-3: Configure 3-tier security levels & auto-logging
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            // Add event column after batch_uuid (Spatie default position)
            $table->string('event')->nullable()->after('batch_uuid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropColumn('event');
        });
    }
};

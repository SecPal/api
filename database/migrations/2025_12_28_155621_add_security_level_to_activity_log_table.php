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
 * Add security_level column to activity_log table.
 *
 * Security levels:
 * - Level 1 (Basic): Hash Chain only, 3 year retention
 * - Level 2 (Enhanced): Hash Chain + Merkle Tree, 5 year retention
 * - Level 3 (Maximum): Hash Chain + Merkle Tree + OpenTimestamp, 7 year retention
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            // Add security_level column (1, 2, or 3) with default of 1
            $table->smallInteger('security_level')->default(1)->after('description');
            $table->index(['tenant_id', 'security_level', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'security_level', 'created_at']);
            $table->dropColumn('security_level');
        });
    }
};

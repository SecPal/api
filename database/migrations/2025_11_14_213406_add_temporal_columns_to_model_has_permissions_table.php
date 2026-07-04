<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Add temporal constraints to direct permission assignments.
     * Mirrors the temporal functionality of model_has_roles (see migration 2025_11_08_143609).
     */
    public function up(): void
    {
        Schema::table('model_has_permissions', function (Blueprint $table) {
            // Temporal permission assignment columns
            $table->timestamp('valid_from')->nullable()->after('model_type');
            $table->timestamp('valid_until')->nullable()->after('valid_from');

            // Audit trail columns
            $table->foreignUuid('assigned_by')->nullable()->after('valid_until')->constrained('users')->onDelete('set null');
            $table->text('reason')->nullable()->after('assigned_by');

            // Standard timestamps
            $table->timestamps();

            // Index for efficient expiration queries
            $table->index(['valid_until'], 'model_has_permissions_expiration_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('model_has_permissions', function (Blueprint $table) {
            $table->dropIndex('model_has_permissions_expiration_index');
            $table->dropTimestamps();
            $table->dropForeign(['assigned_by']);
            $table->dropColumn([
                'valid_from',
                'valid_until',
                'assigned_by',
                'reason',
            ]);
        });
    }
};

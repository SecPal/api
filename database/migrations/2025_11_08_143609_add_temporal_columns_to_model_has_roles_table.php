<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

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
        Schema::table('model_has_roles', function (Blueprint $table) {
            // Temporal role assignment columns
            $table->timestamp('valid_from')->nullable()->after('role_id');
            $table->timestamp('valid_until')->nullable()->after('valid_from');
            $table->boolean('auto_revoke')->default(true)->after('valid_until');

            // Audit trail columns
            $table->uuid('assigned_by')->nullable()->after('auto_revoke');
            $table->text('reason')->nullable()->after('assigned_by');

            // Standard timestamps
            $table->timestamps();

            // Index for efficient expiration queries
            $table->index(['valid_until', 'auto_revoke'], 'model_has_roles_expiration_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('model_has_roles', function (Blueprint $table) {
            $table->dropIndex('model_has_roles_expiration_index');
            $table->dropTimestamps();
            $table->dropColumn([
                'valid_from',
                'valid_until',
                'auto_revoke',
                'assigned_by',
                'reason',
            ]);
        });
    }
};

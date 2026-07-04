<?php

/*
 * SPDX-FileCopyrightText: 2026 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution
 */

declare(strict_types=1);

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
        Schema::table('model_has_roles', function (Blueprint $table): void {
            $table->index(
                ['model_id', 'model_type', 'tenant_id', 'valid_from', 'valid_until'],
                'model_has_roles_active_lookup_index'
            );
        });

        Schema::table('model_has_permissions', function (Blueprint $table): void {
            $table->index(
                ['model_id', 'model_type', 'tenant_id', 'permission_id', 'valid_from', 'valid_until'],
                'model_has_permissions_active_lookup_index'
            );
        });

        Schema::table('customer_assignments', function (Blueprint $table): void {
            $table->index(
                ['user_id', 'tenant_id', 'valid_from', 'valid_until'],
                'customer_assignments_active_lookup_index'
            );
        });

        Schema::table('site_assignments', function (Blueprint $table): void {
            $table->index(
                ['user_id', 'tenant_id', 'valid_from', 'valid_until'],
                'site_assignments_active_lookup_index'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('site_assignments', function (Blueprint $table): void {
            $table->dropIndex('site_assignments_active_lookup_index');
        });

        Schema::table('customer_assignments', function (Blueprint $table): void {
            $table->dropIndex('customer_assignments_active_lookup_index');
        });

        Schema::table('model_has_permissions', function (Blueprint $table): void {
            $table->dropIndex('model_has_permissions_active_lookup_index');
        });

        Schema::table('model_has_roles', function (Blueprint $table): void {
            $table->dropIndex('model_has_roles_active_lookup_index');
        });
    }
};

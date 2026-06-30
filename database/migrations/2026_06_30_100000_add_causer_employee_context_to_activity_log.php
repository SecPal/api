<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table): void {
            $table->uuid('causer_employee_id')->nullable();
            $table->uuid('causer_employee_organizational_unit_id')->nullable();
            $table->smallInteger('causer_employee_management_level')->nullable();

            $table->index(
                ['tenant_id', 'causer_employee_organizational_unit_id', 'causer_employee_management_level'],
                'activity_log_causer_employee_context_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table): void {
            $table->dropIndex('activity_log_causer_employee_context_idx');
            $table->dropColumn([
                'causer_employee_id',
                'causer_employee_organizational_unit_id',
                'causer_employee_management_level',
            ]);
        });
    }
};

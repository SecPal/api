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
        Schema::table('organizational_units', function (Blueprint $table): void {
            $table->boolean('is_legal_entity')->default(false);
            $table->boolean('is_establishment')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizational_units', function (Blueprint $table): void {
            $table->dropColumn(['is_legal_entity', 'is_establishment']);
        });
    }
};

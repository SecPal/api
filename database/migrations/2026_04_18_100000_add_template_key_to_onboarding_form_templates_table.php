<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onboarding_form_templates', function (Blueprint $table): void {
            $table->string('template_key')->nullable()->after('name');
            $table->unique(['template_key', 'tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::table('onboarding_form_templates', function (Blueprint $table): void {
            $table->dropUnique(['template_key', 'tenant_id']);
            $table->dropColumn('template_key');
        });
    }
};

<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
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
        Schema::create('onboarding_form_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->nullable()->constrained('tenant_keys')->cascadeOnDelete();
            // NULL tenant_id = system-wide templates
            // Non-null tenant_id = custom tenant forms

            $table->string('name'); // "Personalfragebogen", "Bankverbindung"
            $table->text('description')->nullable();

            // Form Definition (JSON Schema)
            $table->json('form_schema'); // Fields, validation rules, etc.

            // Behavior
            $table->boolean('is_required')->default(true);
            $table->boolean('is_system_template')->default(false); // System templates cannot be deleted
            $table->integer('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['tenant_id', 'is_required']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('onboarding_form_templates');
    }
};

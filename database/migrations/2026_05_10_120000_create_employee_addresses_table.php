<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_addresses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('tenant_keys')->cascadeOnDelete();

            $table->text('street_enc')->nullable();
            $table->text('house_number_enc')->nullable();
            $table->text('postal_code_enc')->nullable();
            $table->text('city_enc')->nullable();
            $table->text('supplement_enc')->nullable();
            $table->string('country', 2)->nullable();
            $table->string('state', 100)->nullable();

            $table->date('resided_from')->nullable();
            $table->date('resided_until')->nullable();

            $table->timestamps();

            $table->index(['employee_id', 'resided_until']);
            $table->index('tenant_id');
        });

        DB::statement('ALTER TABLE employee_addresses ADD CONSTRAINT employee_addresses_resided_dates_chk CHECK (
            resided_from IS NULL OR resided_until IS NULL OR resided_until >= resided_from
        )');

        DB::statement(
            'CREATE UNIQUE INDEX employee_addresses_one_current_per_employee ON employee_addresses (employee_id) WHERE (resided_until IS NULL)'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS employee_addresses_one_current_per_employee');
        Schema::dropIfExists('employee_addresses');
    }
};

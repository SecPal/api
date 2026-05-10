<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn([
                'address_street_enc',
                'address_house_number_enc',
                'address_postal_code_enc',
                'address_city_enc',
                'address_supplement_enc',
                'address_country',
                'address_state',
                'address_history',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->text('address_street_enc')->nullable()->after('nationalities');
            $table->text('address_house_number_enc')->nullable()->after('address_street_enc');
            $table->text('address_postal_code_enc')->nullable()->after('address_house_number_enc');
            $table->text('address_city_enc')->nullable()->after('address_postal_code_enc');
            $table->text('address_supplement_enc')->nullable()->after('address_city_enc');
            $table->string('address_country', 2)->nullable()->after('address_supplement_enc');
            $table->string('address_state', 100)->nullable()->after('address_country');
            $table->json('address_history')->nullable()->after('address_state');
        });
    }
};

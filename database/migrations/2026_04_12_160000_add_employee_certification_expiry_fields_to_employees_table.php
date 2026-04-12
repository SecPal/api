<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

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
        Schema::table('employees', function (Blueprint $table) {
            $table->text('firearms_license_number_enc')->nullable()->after('work_permit_copy_deleted_at');
            $table->date('firearms_license_expiry')->nullable()->after('firearms_license_number_enc');
            $table->string('firearms_license_issued_by', 255)->nullable()->after('firearms_license_expiry');
            $table->string('first_aid_cert_number', 255)->nullable()->after('firearms_license_issued_by');
            $table->date('first_aid_cert_date')->nullable()->after('first_aid_cert_number');
            $table->date('first_aid_cert_expiry')->nullable()->after('first_aid_cert_date');
            $table->date('fire_safety_cert_date')->nullable()->after('first_aid_cert_expiry');
            $table->date('fire_safety_cert_expiry')->nullable()->after('fire_safety_cert_date');
            $table->date('evacuation_cert_date')->nullable()->after('fire_safety_cert_expiry');
            $table->date('evacuation_cert_expiry')->nullable()->after('evacuation_cert_date');
            $table->json('additional_certifications')->nullable()->after('evacuation_cert_expiry');

            $table->index('firearms_license_expiry', 'idx_employees_firearms_license_expiry');
            $table->index('first_aid_cert_expiry', 'idx_employees_first_aid_cert_expiry');
            $table->index('fire_safety_cert_expiry', 'idx_employees_fire_safety_cert_expiry');
            $table->index('evacuation_cert_expiry', 'idx_employees_evacuation_cert_expiry');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex('idx_employees_firearms_license_expiry');
            $table->dropIndex('idx_employees_first_aid_cert_expiry');
            $table->dropIndex('idx_employees_fire_safety_cert_expiry');
            $table->dropIndex('idx_employees_evacuation_cert_expiry');

            $table->dropColumn([
                'firearms_license_number_enc',
                'firearms_license_expiry',
                'firearms_license_issued_by',
                'first_aid_cert_number',
                'first_aid_cert_date',
                'first_aid_cert_expiry',
                'fire_safety_cert_date',
                'fire_safety_cert_expiry',
                'evacuation_cert_date',
                'evacuation_cert_expiry',
                'additional_certifications',
            ]);
        });
    }
};

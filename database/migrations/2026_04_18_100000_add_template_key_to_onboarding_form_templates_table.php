<?php

declare(strict_types=1);

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
        Schema::table('onboarding_form_templates', function (Blueprint $table): void {
            $table->string('template_key')->nullable();
        });

        // Backfill stable keys for existing system templates before adding unique constraints.
        $systemKeys = [
            'Personal Information Form' => 'personal_information_form',
            'Bank Account Details' => 'bank_account_details',
            'Emergency Contact' => 'emergency_contact',
            'Tax Identification Number' => 'tax_identification_number',
        ];

        foreach ($systemKeys as $name => $key) {
            DB::table('onboarding_form_templates')
                ->where('name', $name)
                ->whereNull('tenant_id')
                ->where('is_system_template', true)
                ->whereNull('template_key')
                ->update(['template_key' => $key]);
        }

        // Partial unique index for system templates (tenant_id IS NULL).
        DB::statement(
            'CREATE UNIQUE INDEX onboarding_form_templates_template_key_system_unique
             ON onboarding_form_templates (template_key)
             WHERE tenant_id IS NULL'
        );
        // Partial unique index for tenant-owned templates (tenant_id IS NOT NULL).
        DB::statement(
            'CREATE UNIQUE INDEX onboarding_form_templates_template_key_tenant_unique
             ON onboarding_form_templates (template_key, tenant_id)
             WHERE tenant_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS onboarding_form_templates_template_key_system_unique');
        DB::statement('DROP INDEX IF EXISTS onboarding_form_templates_template_key_tenant_unique');

        Schema::table('onboarding_form_templates', function (Blueprint $table): void {
            $table->dropColumn('template_key');
        });
    }
};

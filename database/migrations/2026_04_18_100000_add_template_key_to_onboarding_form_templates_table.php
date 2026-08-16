<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
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
        // Resolve each row to a single primary key first to prevent multiple-row assignments
        // if sort_order or names ever overlap across system templates.
        $systemTemplates = [
            ['template_key' => 'personal_information_form', 'sort_order' => 1, 'names' => ['Personal Information Form']],
            ['template_key' => 'bank_account_details', 'sort_order' => 2, 'names' => ['Bank Account Details']],
            ['template_key' => 'emergency_contact', 'sort_order' => 3, 'names' => ['Emergency Contact']],
            ['template_key' => 'tax_identification_number', 'sort_order' => 4, 'names' => ['Tax Identification Number']],
        ];

        foreach ($systemTemplates as $systemTemplate) {
            $id = DB::table('onboarding_form_templates')
                ->whereNull('tenant_id')
                ->where('is_system_template', true)
                ->whereNull('template_key')
                ->where(function (Builder $query) use ($systemTemplate): void {
                    $query->where('sort_order', $systemTemplate['sort_order'])
                        ->orWhereIn('name', $systemTemplate['names']);
                })
                ->value('id');

            if ($id !== null) {
                DB::table('onboarding_form_templates')
                    ->where('id', $id)
                    ->update(['template_key' => $systemTemplate['template_key']]);
            }
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

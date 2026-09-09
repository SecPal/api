<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('The work instruction persistence model requires PostgreSQL.');
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id'], 'users_tenant_id_id_unique');
        });
        Schema::table('employees', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id'], 'employees_tenant_id_id_unique');
        });

        Schema::create('work_instructions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenant_keys')->cascadeOnDelete();
            $table->string('instruction_number', 64);
            $table->string('title');
            $table->text('body');
            $table->string('locale', 2);
            $table->string('status', 16)->default('draft');
            $table->timestampTz('published_at')->nullable();
            $table->uuid('published_by_user_id')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->uuid('archived_by_user_id')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id'], 'work_instructions_tenant_id_id_unique');
            $table->unique(['tenant_id', 'instruction_number'], 'work_instructions_tenant_number_unique');
            $table->index(['tenant_id', 'status'], 'work_instructions_tenant_status_index');
            $table->index(['tenant_id', 'locale'], 'work_instructions_tenant_locale_index');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE work_instructions
            ADD CONSTRAINT work_instructions_locale_check
            CHECK (locale IN ('de', 'en')),
            ADD CONSTRAINT work_instructions_status_check
            CHECK (status IN ('draft', 'in_review', 'published', 'archived')),
            ADD CONSTRAINT work_instructions_lifecycle_timestamps_check
            CHECK (
                (status IN ('draft', 'in_review') AND published_at IS NULL AND archived_at IS NULL)
                OR (status = 'published' AND published_at IS NOT NULL AND archived_at IS NULL)
                OR (status = 'archived' AND published_at IS NOT NULL AND archived_at IS NOT NULL AND archived_at >= published_at)
            ),
            ADD CONSTRAINT work_instructions_published_by_tenant_user_foreign
            FOREIGN KEY (tenant_id, published_by_user_id)
            REFERENCES users (tenant_id, id)
            ON DELETE SET NULL (published_by_user_id),
            ADD CONSTRAINT work_instructions_archived_by_tenant_user_foreign
            FOREIGN KEY (tenant_id, archived_by_user_id)
            REFERENCES users (tenant_id, id)
            ON DELETE SET NULL (archived_by_user_id)
            SQL);

        Schema::create('work_instruction_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenant_keys')->cascadeOnDelete();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id'], 'work_instruction_templates_tenant_id_id_unique');
        });

        Schema::create('work_instruction_template_translations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->uuid('work_instruction_template_id');
            $table->string('locale', 2);
            $table->string('title');
            $table->text('body');
            $table->timestampsTz();

            $table->foreign(
                ['tenant_id', 'work_instruction_template_id'],
                'wi_template_translations_tenant_owner_foreign'
            )->references(['tenant_id', 'id'])->on('work_instruction_templates')->cascadeOnDelete();
            $table->unique(
                ['tenant_id', 'work_instruction_template_id', 'locale'],
                'wi_template_translations_owner_locale_unique'
            );
        });
        DB::statement(<<<'SQL'
            ALTER TABLE work_instruction_template_translations
            ADD CONSTRAINT wi_template_translations_locale_check
            CHECK (locale IN ('de', 'en'))
            SQL);

        Schema::create('work_instruction_standard_blocks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('key', 128)->unique('wi_standard_blocks_key_unique');
            $table->timestampsTz();
        });

        Schema::create('work_instruction_standard_block_translations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('work_instruction_standard_block_id');
            $table->string('locale', 2);
            $table->string('title');
            $table->text('body');
            $table->timestampsTz();

            $table->foreign(
                'work_instruction_standard_block_id',
                'wi_block_translations_owner_foreign'
            )->references('id')->on('work_instruction_standard_blocks')->cascadeOnDelete();
            $table->unique(
                ['work_instruction_standard_block_id', 'locale'],
                'wi_block_translations_owner_locale_unique'
            );
        });
        DB::statement(<<<'SQL'
            ALTER TABLE work_instruction_standard_block_translations
            ADD CONSTRAINT wi_block_translations_locale_check
            CHECK (locale IN ('de', 'en'))
            SQL);

        Schema::create('work_instruction_acknowledgments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->uuid('work_instruction_id');
            $table->uuid('employee_id');
            $table->uuid('acknowledged_by_user_id')->nullable();
            $table->timestampTz('acknowledged_at');
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenant_keys')->cascadeOnDelete();
            $table->foreign(
                ['tenant_id', 'work_instruction_id'],
                'wi_acknowledgments_tenant_instruction_foreign'
            )->references(['tenant_id', 'id'])->on('work_instructions')->restrictOnDelete();
            $table->foreign(
                ['tenant_id', 'employee_id'],
                'wi_acknowledgments_tenant_employee_foreign'
            )->references(['tenant_id', 'id'])->on('employees')->restrictOnDelete();
            $table->unique(
                ['tenant_id', 'work_instruction_id', 'employee_id'],
                'wi_acknowledgments_identity_unique'
            );
            $table->index(['tenant_id', 'employee_id'], 'wi_acknowledgments_tenant_employee_index');
        });
        DB::statement(<<<'SQL'
            ALTER TABLE work_instruction_acknowledgments
            ADD CONSTRAINT wi_acknowledgments_tenant_actor_foreign
            FOREIGN KEY (tenant_id, acknowledged_by_user_id)
            REFERENCES users (tenant_id, id)
            ON DELETE SET NULL (acknowledged_by_user_id)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('work_instruction_acknowledgments');
        Schema::dropIfExists('work_instruction_standard_block_translations');
        Schema::dropIfExists('work_instruction_standard_blocks');
        Schema::dropIfExists('work_instruction_template_translations');
        Schema::dropIfExists('work_instruction_templates');
        Schema::dropIfExists('work_instructions');

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropUnique('employees_tenant_id_id_unique');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_tenant_id_id_unique');
        });
    }
};

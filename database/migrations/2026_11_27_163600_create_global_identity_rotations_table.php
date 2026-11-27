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
        Schema::create('global_identity_rotations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->jsonb('scope');
            $table->string('phase', 24);
            $table->string('resume_phase', 24)->nullable();
            $table->string('cursor', 64)->nullable();
            $table->bigInteger('processed')->default(0);
            $table->timestampsTz();
        });
        DB::statement(<<<'SQL'
            ALTER TABLE global_identity_rotations ADD CONSTRAINT global_identity_rotation_checkpoint CHECK (
                jsonb_typeof(scope) = 'object' AND processed >= 0
                AND phase IN ('rewrap', 'rewrite', 'backfill', 'promote', 'drain', 'revoke', 'activate', 'replace', 'verify', 'cleanup', 'paused', 'complete')
                AND (phase = 'paused') = (resume_phase IS NOT NULL)
                AND (resume_phase IS NULL OR resume_phase IN ('rewrap', 'rewrite', 'backfill', 'promote', 'drain', 'verify', 'cleanup'))
            )
            SQL);
        DB::statement("CREATE UNIQUE INDEX global_identity_single_rotation ON global_identity_rotations ((true)) WHERE phase NOT IN ('complete', 'paused')");
        Schema::create('global_identity_rotation_reissues', function (Blueprint $table): void {
            $table->foreignUuid('rotation_id')->constrained('global_identity_rotations');
            $table->foreignUuid('operation_id')->unique()->constrained('global_identity_operations');
            $table->jsonb('recipient_enc')->nullable();
            $table->primary(['rotation_id', 'operation_id']);
        });
        Schema::table('global_identity_operations', function (Blueprint $table): void {
            $table->uuid('incident_id')->nullable()->index();
            $table->uuid('replaces_operation_id')->nullable()->unique();
            $table->index(['delivery_key_id', 'delivery_key_version', 'id'], 'global_identity_delivery_key_references');
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE global_identity_operations ADD CONSTRAINT global_identity_incident_reference
                CHECK ((incident_id IS NULL AND replaces_operation_id IS NULL)
                    OR (incident_id IS NOT NULL AND replaces_operation_id IS NOT NULL AND replaces_operation_id <> id));
            CREATE OR REPLACE FUNCTION global_identity_incident_reference_immutable() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF ROW(NEW.incident_id, NEW.replaces_operation_id) IS DISTINCT FROM ROW(OLD.incident_id, OLD.replaces_operation_id) THEN
                    RAISE EXCEPTION 'Global identity incident reference is immutable' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END
            $$;
            CREATE TRIGGER global_identity_incident_reference_before_update
            BEFORE UPDATE ON global_identity_operations FOR EACH ROW
            EXECUTE FUNCTION global_identity_incident_reference_immutable();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER global_identity_incident_reference_before_update ON global_identity_operations;
            DROP FUNCTION global_identity_incident_reference_immutable();
            ALTER TABLE global_identity_operations DROP CONSTRAINT global_identity_incident_reference;
            SQL);
        Schema::table('global_identity_operations', function (Blueprint $table): void {
            $table->dropIndex('global_identity_delivery_key_references');
            $table->dropColumn(['incident_id', 'replaces_operation_id']);
        });
        Schema::dropIfExists('global_identity_rotation_reissues');
        Schema::dropIfExists('global_identity_rotations');
    }
};

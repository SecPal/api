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
            throw new RuntimeException('The legal hold persistence model requires PostgreSQL.');
        }

        Schema::table('activity_log', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id'], 'activity_log_tenant_id_id_unique');
        });

        Schema::create('legal_holds', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenant_keys')->cascadeOnDelete();
            $table->string('case_reference', 64);
            $table->string('status', 16)->default('active');
            $table->string('justification', 2000);
            $table->uuid('created_by_user_id')->nullable();
            $table->uuid('created_by_identity_id');
            $table->timestampTz('released_at')->nullable();
            $table->uuid('released_by_user_id')->nullable();
            $table->uuid('released_by_identity_id')->nullable();
            $table->string('release_justification', 2000)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['tenant_id', 'id'], 'legal_holds_tenant_id_id_unique');
            $table->unique(['tenant_id', 'case_reference'], 'legal_holds_tenant_case_reference_unique');
            $table->index(['tenant_id', 'status'], 'legal_holds_tenant_status_index');
            $table->index(['tenant_id', 'created_by_user_id'], 'legal_holds_tenant_creator_index');
            $table->index(['tenant_id', 'released_by_user_id'], 'legal_holds_tenant_releaser_index');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE legal_holds
            ADD CONSTRAINT legal_holds_status_check
            CHECK (status IN ('active', 'released')),
            ADD CONSTRAINT legal_holds_case_reference_check
            CHECK (char_length(btrim(case_reference)) BETWEEN 1 AND 64),
            ADD CONSTRAINT legal_holds_justification_check
            CHECK (char_length(btrim(justification)) BETWEEN 1 AND 2000),
            ADD CONSTRAINT legal_holds_lifecycle_check
            CHECK (
                (
                    status = 'active'
                    AND released_at IS NULL
                    AND released_by_user_id IS NULL
                    AND released_by_identity_id IS NULL
                    AND release_justification IS NULL
                )
                OR (
                    status = 'released'
                    AND released_at IS NOT NULL
                    AND released_at >= created_at
                    AND released_by_identity_id IS NOT NULL
                    AND char_length(btrim(release_justification)) BETWEEN 1 AND 2000
                )
            ),
            ADD CONSTRAINT legal_holds_creator_tenant_user_foreign
            FOREIGN KEY (created_by_user_id)
            REFERENCES users (id)
            ON DELETE SET NULL,
            ADD CONSTRAINT legal_holds_releaser_tenant_user_foreign
            FOREIGN KEY (released_by_user_id)
            REFERENCES users (id)
            ON DELETE SET NULL
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_legal_hold_actor_reference(
                actor_id uuid,
                owner_tenant_id bigint
            )
            RETURNS boolean
            LANGUAGE plpgsql
            AS $$
            BEGIN
                PERFORM 1
                FROM users
                WHERE id = actor_id AND tenant_id = owner_tenant_id
                FOR KEY SHARE;

                RETURN FOUND;
            END;
            $$;

            CREATE OR REPLACE FUNCTION enforce_legal_hold_is_active(
                hold_id uuid,
                owner_tenant_id bigint
            )
            RETURNS boolean
            LANGUAGE plpgsql
            AS $$
            BEGIN
                PERFORM 1
                FROM legal_holds
                WHERE id = hold_id
                    AND tenant_id = owner_tenant_id
                    AND status = 'active'
                FOR UPDATE;

                RETURN FOUND;
            END;
            $$;

            CREATE OR REPLACE FUNCTION enforce_legal_hold_history()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.created_by_user_id IS NULL
                        OR NEW.created_by_identity_id IS DISTINCT FROM NEW.created_by_user_id
                        OR NOT enforce_legal_hold_actor_reference(
                            NEW.created_by_user_id,
                            NEW.tenant_id
                        ) THEN
                        RAISE EXCEPTION 'legal hold creator identity must match a current user'
                            USING ERRCODE = '23514';
                    END IF;

                    IF NEW.status = 'released'
                        AND (
                            NEW.released_by_user_id IS NULL
                            OR NEW.released_by_identity_id IS DISTINCT FROM NEW.released_by_user_id
                            OR NOT enforce_legal_hold_actor_reference(
                                NEW.released_by_user_id,
                                NEW.tenant_id
                            )
                        ) THEN
                        RAISE EXCEPTION 'legal hold release identity must match a current user'
                            USING ERRCODE = '23514';
                    END IF;

                    RETURN NEW;
                END IF;

                IF NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.tenant_id IS DISTINCT FROM OLD.tenant_id
                    OR NEW.case_reference IS DISTINCT FROM OLD.case_reference
                    OR NEW.justification IS DISTINCT FROM OLD.justification
                    OR NEW.created_by_identity_id IS DISTINCT FROM OLD.created_by_identity_id
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'legal hold case identity and creation evidence are immutable'
                        USING ERRCODE = '23514';
                END IF;

                IF NEW.created_by_user_id IS DISTINCT FROM OLD.created_by_user_id
                    AND NOT (
                        OLD.created_by_user_id IS NOT NULL
                        AND NEW.created_by_user_id IS NULL
                        AND pg_trigger_depth() > 1
                    ) THEN
                    RAISE EXCEPTION 'legal hold creator relation is immutable'
                        USING ERRCODE = '23514';
                END IF;

                IF OLD.status = 'released' THEN
                    IF NEW.status IS DISTINCT FROM OLD.status
                        OR NEW.released_at IS DISTINCT FROM OLD.released_at
                        OR NEW.released_by_identity_id IS DISTINCT FROM OLD.released_by_identity_id
                        OR NEW.release_justification IS DISTINCT FROM OLD.release_justification
                        OR (
                            NEW.released_by_user_id IS DISTINCT FROM OLD.released_by_user_id
                            AND NOT (
                                OLD.released_by_user_id IS NOT NULL
                                AND NEW.released_by_user_id IS NULL
                                AND pg_trigger_depth() > 1
                            )
                        ) THEN
                        RAISE EXCEPTION 'released legal hold evidence is immutable'
                            USING ERRCODE = '23514';
                    END IF;
                ELSIF NEW.status = 'released'
                    AND (
                        NEW.released_by_user_id IS NULL
                        OR NEW.released_by_identity_id IS DISTINCT FROM NEW.released_by_user_id
                        OR NOT enforce_legal_hold_actor_reference(
                            NEW.released_by_user_id,
                            NEW.tenant_id
                        )
                    ) THEN
                    RAISE EXCEPTION 'legal hold release identity must match a current user'
                        USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER legal_holds_history_immutable
            BEFORE INSERT OR UPDATE ON legal_holds
            FOR EACH ROW
            EXECUTE FUNCTION enforce_legal_hold_history();
            SQL);

        Schema::create('legal_hold_activity_attachments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->uuid('legal_hold_id');
            $table->unsignedBigInteger('activity_id')->nullable();
            $table->unsignedBigInteger('activity_identity_id');
            $table->uuid('attached_by_user_id')->nullable();
            $table->uuid('attached_by_identity_id');
            $table->timestampTz('attached_at');
            $table->timestampTz('detached_at')->nullable();
            $table->uuid('detached_by_user_id')->nullable();
            $table->uuid('detached_by_identity_id')->nullable();
            $table->string('detachment_justification', 2000)->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenant_keys')->cascadeOnDelete();
            $table->foreign(
                ['tenant_id', 'legal_hold_id'],
                'legal_hold_attachments_tenant_hold_foreign'
            )->references(['tenant_id', 'id'])->on('legal_holds')->restrictOnDelete();
            $table->index(['tenant_id', 'legal_hold_id'], 'legal_hold_attachments_tenant_hold_index');
            $table->index(['tenant_id', 'activity_id'], 'legal_hold_attachments_tenant_activity_index');
            $table->index(['tenant_id', 'attached_by_user_id'], 'legal_hold_attachments_tenant_attacher_index');
            $table->index(['tenant_id', 'detached_by_user_id'], 'legal_hold_attachments_tenant_detacher_index');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE legal_hold_activity_attachments
            ADD CONSTRAINT legal_hold_attachments_lifecycle_check
            CHECK (
                (
                    detached_at IS NULL
                    AND detached_by_user_id IS NULL
                    AND detached_by_identity_id IS NULL
                    AND detachment_justification IS NULL
                )
                OR (
                    detached_at IS NOT NULL
                    AND detached_at >= attached_at
                    AND detached_by_identity_id IS NOT NULL
                    AND char_length(btrim(detachment_justification)) BETWEEN 1 AND 2000
                )
            ),
            ADD CONSTRAINT legal_hold_attachments_attacher_tenant_user_foreign
            FOREIGN KEY (attached_by_user_id)
            REFERENCES users (id)
            ON DELETE SET NULL,
            ADD CONSTRAINT legal_hold_attachments_detacher_tenant_user_foreign
            FOREIGN KEY (detached_by_user_id)
            REFERENCES users (id)
            ON DELETE SET NULL
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE legal_hold_activity_attachments
            ADD CONSTRAINT legal_hold_attachments_tenant_activity_foreign
            FOREIGN KEY (tenant_id, activity_id)
            REFERENCES activity_log (tenant_id, id)
            ON DELETE SET NULL (activity_id)
            SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX legal_hold_attachments_active_identity_unique
            ON legal_hold_activity_attachments (tenant_id, legal_hold_id, activity_identity_id)
            WHERE detached_at IS NULL
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_legal_hold_attachment_history()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NOT enforce_legal_hold_is_active(
                        NEW.legal_hold_id,
                        NEW.tenant_id
                    ) THEN
                        RAISE EXCEPTION 'attachments require an active legal hold'
                            USING ERRCODE = '23514';
                    END IF;

                    IF NEW.activity_id IS NULL
                        OR NEW.activity_identity_id IS DISTINCT FROM NEW.activity_id THEN
                        RAISE EXCEPTION 'attachment activity identity must match a current activity'
                            USING ERRCODE = '23514';
                    END IF;

                    IF NEW.attached_by_user_id IS NULL
                        OR NEW.attached_by_identity_id IS DISTINCT FROM NEW.attached_by_user_id
                        OR NOT enforce_legal_hold_actor_reference(
                            NEW.attached_by_user_id,
                            NEW.tenant_id
                        ) THEN
                        RAISE EXCEPTION 'attachment actor identity must match a current user'
                            USING ERRCODE = '23514';
                    END IF;

                    IF NEW.detached_at IS NOT NULL
                        AND (
                            NEW.detached_by_user_id IS NULL
                            OR NEW.detached_by_identity_id IS DISTINCT FROM NEW.detached_by_user_id
                            OR NOT enforce_legal_hold_actor_reference(
                                NEW.detached_by_user_id,
                                NEW.tenant_id
                            )
                        ) THEN
                        RAISE EXCEPTION 'detachment actor identity must match a current user'
                            USING ERRCODE = '23514';
                    END IF;

                    RETURN NEW;
                END IF;

                IF NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.tenant_id IS DISTINCT FROM OLD.tenant_id
                    OR NEW.legal_hold_id IS DISTINCT FROM OLD.legal_hold_id
                    OR NEW.activity_identity_id IS DISTINCT FROM OLD.activity_identity_id
                    OR NEW.attached_by_identity_id IS DISTINCT FROM OLD.attached_by_identity_id
                    OR NEW.attached_at IS DISTINCT FROM OLD.attached_at
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'attachment identity and creation evidence are immutable'
                        USING ERRCODE = '23514';
                END IF;

                IF NEW.activity_id IS DISTINCT FROM OLD.activity_id
                    AND NOT (
                        OLD.activity_id IS NOT NULL
                        AND NEW.activity_id IS NULL
                        AND pg_trigger_depth() > 1
                    ) THEN
                    RAISE EXCEPTION 'attachment activity relation is immutable'
                        USING ERRCODE = '23514';
                END IF;

                IF NEW.attached_by_user_id IS DISTINCT FROM OLD.attached_by_user_id
                    AND NOT (
                        OLD.attached_by_user_id IS NOT NULL
                        AND NEW.attached_by_user_id IS NULL
                        AND pg_trigger_depth() > 1
                    ) THEN
                    RAISE EXCEPTION 'attachment actor relation is immutable'
                        USING ERRCODE = '23514';
                END IF;

                IF OLD.detached_at IS NOT NULL THEN
                    IF NEW.detached_at IS DISTINCT FROM OLD.detached_at
                        OR NEW.detached_by_identity_id IS DISTINCT FROM OLD.detached_by_identity_id
                        OR NEW.detachment_justification IS DISTINCT FROM OLD.detachment_justification
                        OR (
                            NEW.detached_by_user_id IS DISTINCT FROM OLD.detached_by_user_id
                            AND NOT (
                                OLD.detached_by_user_id IS NOT NULL
                                AND NEW.detached_by_user_id IS NULL
                                AND pg_trigger_depth() > 1
                            )
                        ) THEN
                        RAISE EXCEPTION 'detachment evidence is immutable'
                            USING ERRCODE = '23514';
                    END IF;
                ELSIF NEW.detached_at IS NOT NULL
                    AND (
                        NEW.detached_by_user_id IS NULL
                        OR NEW.detached_by_identity_id IS DISTINCT FROM NEW.detached_by_user_id
                        OR NOT enforce_legal_hold_actor_reference(
                            NEW.detached_by_user_id,
                            NEW.tenant_id
                        )
                        OR NOT enforce_legal_hold_is_active(
                            NEW.legal_hold_id,
                            NEW.tenant_id
                        )
                    ) THEN
                    RAISE EXCEPTION 'detachment actor identity must match a current user'
                        USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER legal_hold_attachments_history_immutable
            BEFORE INSERT OR UPDATE ON legal_hold_activity_attachments
            FOR EACH ROW
            EXECUTE FUNCTION enforce_legal_hold_attachment_history();

            CREATE OR REPLACE FUNCTION enforce_legal_hold_evidence_deletion()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF pg_trigger_depth() <= 1 THEN
                    RAISE EXCEPTION 'legal hold evidence cannot be deleted directly'
                        USING ERRCODE = '23514';
                END IF;

                RETURN OLD;
            END;
            $$;

            CREATE TRIGGER legal_holds_prevent_direct_delete
            BEFORE DELETE ON legal_holds
            FOR EACH ROW
            EXECUTE FUNCTION enforce_legal_hold_evidence_deletion();

            CREATE TRIGGER legal_hold_attachments_prevent_direct_delete
            BEFORE DELETE ON legal_hold_activity_attachments
            FOR EACH ROW
            EXECUTE FUNCTION enforce_legal_hold_evidence_deletion();

            CREATE TRIGGER legal_holds_prevent_truncate
            BEFORE TRUNCATE ON legal_holds
            FOR EACH STATEMENT
            EXECUTE FUNCTION enforce_legal_hold_evidence_deletion();

            CREATE TRIGGER legal_hold_attachments_prevent_truncate
            BEFORE TRUNCATE ON legal_hold_activity_attachments
            FOR EACH STATEMENT
            EXECUTE FUNCTION enforce_legal_hold_evidence_deletion();

            CREATE OR REPLACE FUNCTION enforce_legal_hold_actor_tenant()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.tenant_id IS DISTINCT FROM OLD.tenant_id
                    AND (
                        EXISTS (
                            SELECT 1 FROM legal_holds
                            WHERE created_by_user_id = OLD.id OR released_by_user_id = OLD.id
                        )
                        OR EXISTS (
                            SELECT 1 FROM legal_hold_activity_attachments
                            WHERE attached_by_user_id = OLD.id OR detached_by_user_id = OLD.id
                        )
                    ) THEN
                    RAISE EXCEPTION 'legal hold actors cannot move across tenants while referenced'
                        USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER users_enforce_legal_hold_actor_tenant
            BEFORE UPDATE OF tenant_id ON users
            FOR EACH ROW
            EXECUTE FUNCTION enforce_legal_hold_actor_tenant();
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS users_enforce_legal_hold_actor_tenant ON users');

        Schema::dropIfExists('legal_hold_activity_attachments');
        Schema::dropIfExists('legal_holds');

        DB::statement('DROP FUNCTION IF EXISTS enforce_legal_hold_attachment_history()');
        DB::statement('DROP FUNCTION IF EXISTS enforce_legal_hold_history()');
        DB::statement('DROP FUNCTION IF EXISTS enforce_legal_hold_evidence_deletion()');
        DB::statement('DROP FUNCTION IF EXISTS enforce_legal_hold_actor_tenant()');
        DB::statement('DROP FUNCTION IF EXISTS enforce_legal_hold_is_active(uuid, bigint)');
        DB::statement('DROP FUNCTION IF EXISTS enforce_legal_hold_actor_reference(uuid, bigint)');

        Schema::table('activity_log', function (Blueprint $table): void {
            $table->dropUnique('activity_log_tenant_id_id_unique');
        });
    }
};

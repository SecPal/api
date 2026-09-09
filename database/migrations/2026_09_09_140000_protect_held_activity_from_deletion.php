<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Legal hold retention protection requires PostgreSQL.');
        }

        DB::statement(<<<'SQL'
            CREATE INDEX legal_hold_attachments_active_activity_identity_index
            ON legal_hold_activity_attachments (tenant_id, activity_identity_id, legal_hold_id)
            WHERE detached_at IS NULL
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION activity_is_actively_held(
                owner_tenant_id bigint,
                activity_identity bigint
            )
            RETURNS boolean
            LANGUAGE plpgsql
            STABLE
            AS $$
            BEGIN
                RETURN EXISTS (
                    SELECT 1
                    FROM legal_hold_activity_attachments AS attachment
                    INNER JOIN legal_holds AS legal_hold
                        ON legal_hold.tenant_id = attachment.tenant_id
                        AND legal_hold.id = attachment.legal_hold_id
                    WHERE attachment.tenant_id = owner_tenant_id
                        AND attachment.activity_identity_id = activity_identity
                        AND attachment.detached_at IS NULL
                        AND legal_hold.tenant_id = owner_tenant_id
                        AND legal_hold.status = 'active'
                );
            END;
            $$;

            CREATE OR REPLACE FUNCTION prevent_actively_held_activity_deletion()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF activity_is_actively_held(OLD.tenant_id, OLD.id) THEN
                    RAISE EXCEPTION 'actively held activity cannot be deleted'
                        USING ERRCODE = '23514';
                END IF;

                RETURN OLD;
            END;
            $$;

            CREATE TRIGGER activity_log_prevent_actively_held_delete
            BEFORE DELETE ON activity_log
            FOR EACH ROW
            EXECUTE FUNCTION prevent_actively_held_activity_deletion();
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS activity_log_prevent_actively_held_delete ON activity_log');
        DB::statement('DROP FUNCTION IF EXISTS prevent_actively_held_activity_deletion()');
        DB::statement('DROP FUNCTION IF EXISTS activity_is_actively_held(bigint, bigint)');
        DB::statement('DROP INDEX IF EXISTS legal_hold_attachments_active_activity_identity_index');
    }
};

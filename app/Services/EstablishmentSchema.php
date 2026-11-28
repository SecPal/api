<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The cutover migration installs this schema after retiring legacy references.
 * Before activation, consumers can install it in an isolated PostgreSQL schema
 * with the functional tenants table.
 */
final class EstablishmentSchema
{
    public function apply(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new \LogicException('Establishment schema requires PostgreSQL.');
        }

        DB::transaction(function (): void {
            Schema::create('establishments', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
                $table->unique(['tenant_id', 'id']);
            });

            Schema::create('establishment_versions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
                $table->uuid('establishment_id');
                $table->timestampTz('effective_at', 6);
                $table->string('name');
                $table->string('code')->nullable();
                $table->jsonb('address');
                $table->boolean('is_closed');

                $table->foreign(['tenant_id', 'establishment_id'], 'establishment_versions_owner_foreign')
                    ->references(['tenant_id', 'id'])->on('establishments')->restrictOnDelete();
                $table->unique(['tenant_id', 'establishment_id', 'effective_at'], 'establishment_versions_effective_unique');
            });

            DB::statement(<<<'SQL'
                ALTER TABLE establishment_versions ADD CONSTRAINT establishment_versions_address_object
                    CHECK (jsonb_typeof(address) = 'object')
                SQL);

            DB::unprepared(<<<'SQL'
                CREATE FUNCTION enforce_establishment_history() RETURNS trigger
                LANGUAGE plpgsql
                SET search_path TO pg_catalog, pg_temp AS $$
                DECLARE
                    previous_effective_at timestamptz;
                    previously_closed boolean;
                BEGIN
                    IF TG_OP <> 'INSERT' THEN
                        RAISE EXCEPTION 'Establishment history is immutable' USING ERRCODE = '23514';
                    END IF;
                    IF NEW.effective_at IS NOT NULL THEN
                        RAISE EXCEPTION 'Establishment history time is set by the database' USING ERRCODE = '23514';
                    END IF;

                    -- Serialize appends and invalidate older transaction snapshots.
                    EXECUTE format(
                        'UPDATE %I.establishments SET tenant_id = tenant_id WHERE tenant_id = $1 AND id = $2',
                        TG_TABLE_SCHEMA
                    ) USING NEW.tenant_id, NEW.establishment_id;

                    EXECUTE format(
                        'SELECT effective_at, is_closed FROM %I.establishment_versions
                         WHERE tenant_id = $1 AND establishment_id = $2 ORDER BY effective_at DESC LIMIT 1',
                        TG_TABLE_SCHEMA
                    ) INTO previous_effective_at, previously_closed USING NEW.tenant_id, NEW.establishment_id;

                    IF previously_closed THEN
                        RAISE EXCEPTION 'Establishment history is closed' USING ERRCODE = '23514';
                    END IF;
                    NEW.effective_at := clock_timestamp();
                    IF NEW.effective_at <= previous_effective_at THEN
                        RAISE EXCEPTION 'Establishment history time did not advance' USING ERRCODE = '23514';
                    END IF;

                    RETURN NEW;
                END;
                $$;

                CREATE TRIGGER establishment_versions_history
                    BEFORE INSERT OR UPDATE OR DELETE ON establishment_versions
                    FOR EACH ROW EXECUTE FUNCTION enforce_establishment_history();

                CREATE TRIGGER establishment_versions_no_truncate
                    BEFORE TRUNCATE ON establishment_versions
                    FOR EACH STATEMENT EXECUTE FUNCTION enforce_establishment_history();
                SQL);
        });
    }
}

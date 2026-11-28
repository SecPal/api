<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Install the employment authority against the functional Employee and its evidence at cutover. */
final class EmploymentSchema
{
    public function apply(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new \LogicException('Employment schema requires PostgreSQL.');
        }

        DB::transaction(function (): void {
            DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

            Schema::table('employees', fn (Blueprint $table) => $table->unique(['tenant_id', 'id'], 'employment_employee_owner_unique'));
            Schema::table('employee_documents', fn (Blueprint $table) => $table->unique(['employee_id', 'id'], 'employment_evidence_owner_unique'));

            Schema::create('employment_periods', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
                $table->uuid('employee_id');
                $table->uuid('initial_terms_id');
                $table->timestampTz('valid_from', 6);
                $table->timestampTz('valid_until', 6)->nullable();
                $table->uuid('end_evidence_id')->nullable();

                $table->unique(['tenant_id', 'employee_id', 'id'], 'employment_periods_owner_unique');
                $table->foreign(['tenant_id', 'employee_id'], 'employment_periods_employee_foreign')
                    ->references(['tenant_id', 'id'])->on('employees')->restrictOnDelete();
                $table->foreign(['employee_id', 'end_evidence_id'], 'employment_periods_end_evidence_foreign')
                    ->references(['employee_id', 'id'])->on('employee_documents')->restrictOnDelete();
                $table->index(['tenant_id', 'employee_id', 'valid_from']);
            });

            Schema::create('employment_terms_versions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
                $table->uuid('employee_id');
                $table->uuid('employment_period_id');
                $table->uuid('evidence_id');
                $table->uuid('replaces_id')->nullable();
                $table->timestampTz('effective_from', 6);
                $table->timestampTz('recorded_at', 6);
                $table->text('conditions_enc');

                $table->unique(['tenant_id', 'employment_period_id', 'effective_from', 'id'], 'employment_terms_binding_unique');
                $table->index(['tenant_id', 'employment_period_id', 'effective_from', 'recorded_at'], 'employment_terms_time_index');
                $table->foreign(['tenant_id', 'employee_id', 'employment_period_id'], 'employment_terms_period_foreign')
                    ->references(['tenant_id', 'employee_id', 'id'])->on('employment_periods')->restrictOnDelete();
                $table->foreign(['employee_id', 'evidence_id'], 'employment_terms_evidence_foreign')
                    ->references(['employee_id', 'id'])->on('employee_documents')->restrictOnDelete();
                $table->foreign(['tenant_id', 'employment_period_id', 'effective_from', 'replaces_id'], 'employment_terms_replaces_foreign')
                    ->references(['tenant_id', 'employment_period_id', 'effective_from', 'id'])
                    ->on('employment_terms_versions')->restrictOnDelete();
            });

            DB::unprepared(<<<'SQL'
                ALTER TABLE employment_periods
                    ADD CONSTRAINT employment_periods_valid_interval
                        CHECK (isfinite(valid_from) AND (valid_until IS NULL OR (isfinite(valid_until) AND valid_until > valid_from))),
                    ADD CONSTRAINT employment_periods_end_evidence_required
                        CHECK ((valid_until IS NULL) = (end_evidence_id IS NULL)),
                    ADD CONSTRAINT employment_periods_no_overlap EXCLUDE USING gist (
                        employee_id WITH =, tstzrange(valid_from, valid_until, '[)') WITH &&
                    ),
                    ADD CONSTRAINT employment_periods_initial_terms_foreign
                        FOREIGN KEY (tenant_id, id, valid_from, initial_terms_id)
                        REFERENCES employment_terms_versions (tenant_id, employment_period_id, effective_from, id)
                        DEFERRABLE INITIALLY DEFERRED;

                CREATE FUNCTION enforce_employment_period_history() RETURNS trigger
                LANGUAGE plpgsql SET search_path TO pg_catalog, pg_temp AS $$
                BEGIN
                    IF TG_OP <> 'UPDATE' THEN
                        RAISE EXCEPTION 'Employment periods are retained' USING ERRCODE = '23514';
                    END IF;

                    IF (OLD.id, OLD.tenant_id, OLD.employee_id, OLD.valid_from, OLD.initial_terms_id)
                        IS DISTINCT FROM (NEW.id, NEW.tenant_id, NEW.employee_id, NEW.valid_from, NEW.initial_terms_id)
                        OR (OLD.valid_until IS NOT NULL AND (
                            NEW.valid_until IS NULL OR NEW.valid_until > OLD.valid_until
                            OR (NEW.valid_until = OLD.valid_until AND NEW.end_evidence_id <> OLD.end_evidence_id)
                        )) THEN
                        RAISE EXCEPTION 'Employment period history cannot be replaced' USING ERRCODE = '23514';
                    END IF;

                    RETURN NEW;
                END;
                $$;

                CREATE TRIGGER employment_periods_history
                    BEFORE UPDATE OR DELETE ON employment_periods
                    FOR EACH ROW EXECUTE FUNCTION enforce_employment_period_history();

                CREATE TRIGGER employment_periods_no_truncate
                    BEFORE TRUNCATE ON employment_periods
                    FOR EACH STATEMENT EXECUTE FUNCTION enforce_employment_period_history();

                CREATE FUNCTION enforce_employment_terms_history() RETURNS trigger
                LANGUAGE plpgsql SET search_path TO pg_catalog, pg_temp AS $$
                DECLARE
                    period_start timestamptz;
                    period_end timestamptz;
                    previous_id uuid;
                    previous_recorded_at timestamptz;
                BEGIN
                    IF TG_OP <> 'INSERT' THEN
                        RAISE EXCEPTION 'Employment terms are immutable' USING ERRCODE = '23514';
                    END IF;
                    IF NEW.recorded_at IS NOT NULL THEN
                        RAISE EXCEPTION 'Employment terms recording time is set by the database' USING ERRCODE = '23514';
                    END IF;

                    -- The owner write also invalidates an older transaction snapshot.
                    EXECUTE format(
                        'UPDATE %I.employment_periods SET valid_from = valid_from
                         WHERE tenant_id = $1 AND employee_id = $2 AND id = $3
                         RETURNING valid_from, valid_until', TG_TABLE_SCHEMA
                    ) INTO period_start, period_end USING NEW.tenant_id, NEW.employee_id, NEW.employment_period_id;
                    IF period_start IS NULL THEN
                        RAISE EXCEPTION 'Employment period is unavailable' USING ERRCODE = '23503';
                    END IF;
                    IF NOT isfinite(NEW.effective_from) OR NEW.effective_from < period_start
                        OR NEW.effective_from >= period_end THEN
                        RAISE EXCEPTION 'Terms take effect outside the employment period' USING ERRCODE = '23514';
                    END IF;

                    EXECUTE format(
                        'SELECT id, recorded_at FROM %I.employment_terms_versions
                         WHERE tenant_id = $1 AND employment_period_id = $2 AND effective_from = $3
                         ORDER BY recorded_at DESC LIMIT 1', TG_TABLE_SCHEMA
                    ) INTO previous_id, previous_recorded_at USING NEW.tenant_id, NEW.employment_period_id, NEW.effective_from;
                    IF NEW.replaces_id IS DISTINCT FROM previous_id THEN
                        RAISE EXCEPTION 'Terms correction must replace the latest version at its effective instant'
                            USING ERRCODE = '23514';
                    END IF;

                    NEW.recorded_at := clock_timestamp();
                    IF NEW.recorded_at <= previous_recorded_at THEN
                        RAISE EXCEPTION 'Employment terms recording time did not advance' USING ERRCODE = '23514';
                    END IF;

                    RETURN NEW;
                END;
                $$;

                CREATE TRIGGER employment_terms_history
                    BEFORE INSERT OR UPDATE OR DELETE ON employment_terms_versions
                    FOR EACH ROW EXECUTE FUNCTION enforce_employment_terms_history();

                CREATE TRIGGER employment_terms_no_truncate
                    BEFORE TRUNCATE ON employment_terms_versions
                    FOR EACH STATEMENT EXECUTE FUNCTION enforce_employment_terms_history();
                SQL);
        });
    }
}

<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Install after the authoritative Establishment and Employment definitions at cutover. */
final class EmployeeEstablishmentAssignmentSchema
{
    public function apply(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new \LogicException('Employee establishment assignments require PostgreSQL.');
        }

        DB::transaction(function (): void {
            Schema::create('employee_establishment_assignments', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignId('tenant_id');
                $table->uuid('employee_id');
                $table->uuid('employment_period_id');
                $table->uuid('establishment_id');
                $table->timestampTz('valid_from', 6);
                $table->timestampTz('valid_until', 6)->nullable();
                $table->timestampTz('cancelled_at', 6)->nullable();
                $table->foreign(['tenant_id', 'employee_id', 'employment_period_id'], 'establishment_assignments_period_owner')
                    ->references(['tenant_id', 'employee_id', 'id'])->on('employment_periods')->restrictOnDelete();
                $table->foreign(['tenant_id', 'establishment_id'], 'establishment_assignments_branch_owner')
                    ->references(['tenant_id', 'id'])->on('establishments')->restrictOnDelete();
                $table->index(['tenant_id', 'establishment_id']);
            });

            // The pair of deferred temporal foreign keys makes the union of
            // non-overlapping assignments equal to their employment period.
            // Cancelled schedules retain provenance and have no effective range.
            DB::unprepared(<<<'SQL'
                ALTER TABLE employment_periods
                    ADD COLUMN effective_range tstzrange GENERATED ALWAYS AS (tstzrange(valid_from, valid_until, '[)')) STORED,
                    ADD CONSTRAINT employment_periods_temporal_key
                        UNIQUE (tenant_id, employee_id, id, effective_range WITHOUT OVERLAPS);

                ALTER TABLE employee_establishment_assignments
                    ADD COLUMN effective_range tstzrange GENERATED ALWAYS AS (
                        CASE WHEN cancelled_at IS NULL THEN tstzrange(valid_from, valid_until, '[)') END
                    ) STORED,
                    ADD CONSTRAINT establishment_assignments_valid_interval
                        CHECK (isfinite(valid_from) AND (valid_until IS NULL OR (isfinite(valid_until) AND valid_until > valid_from))),
                    ADD CONSTRAINT establishment_assignments_temporal_key
                        UNIQUE (tenant_id, employee_id, employment_period_id, effective_range WITHOUT OVERLAPS),
                    ADD CONSTRAINT establishment_assignments_within_employment
                        FOREIGN KEY (tenant_id, employee_id, employment_period_id, PERIOD effective_range)
                        REFERENCES employment_periods (tenant_id, employee_id, id, PERIOD effective_range)
                        DEFERRABLE INITIALLY DEFERRED;

                ALTER TABLE employment_periods
                    ADD CONSTRAINT employment_periods_assignment_coverage
                        FOREIGN KEY (tenant_id, employee_id, id, PERIOD effective_range)
                        REFERENCES employee_establishment_assignments (tenant_id, employee_id, employment_period_id, PERIOD effective_range)
                        DEFERRABLE INITIALLY DEFERRED;

                CREATE FUNCTION enforce_establishment_assignment_history() RETURNS trigger
                LANGUAGE plpgsql SET search_path TO pg_catalog, pg_temp AS $$
                DECLARE
                    period_end timestamptz;
                    write_time timestamptz;
                    locked_establishment uuid;
                    first_version_at timestamptz;
                    already_closed boolean;
                BEGIN
                    IF TG_OP = 'UPDATE' THEN
                        -- The normal writer locks the period first. A direct
                        -- UPDATE already holds the assignment row, so it must
                        -- fail immediately if another session owns the period.
                        EXECUTE format(
                            'SELECT valid_until FROM %I.employment_periods
                             WHERE tenant_id = $1 AND employee_id = $2 AND id = $3 FOR UPDATE NOWAIT',
                            TG_TABLE_SCHEMA
                        ) INTO period_end USING OLD.tenant_id, OLD.employee_id, OLD.employment_period_id;

                        write_time := clock_timestamp();
                        IF NEW.valid_until = '-infinity'::timestamptz THEN
                            NEW.valid_until := write_time;
                        END IF;
                        IF (OLD.id, OLD.tenant_id, OLD.employee_id, OLD.employment_period_id, OLD.establishment_id, OLD.valid_from)
                            IS DISTINCT FROM (NEW.id, NEW.tenant_id, NEW.employee_id, NEW.employment_period_id, NEW.establishment_id, NEW.valid_from)
                            OR OLD.cancelled_at IS NOT NULL
                            OR (OLD.valid_until IS NOT NULL AND (NEW.valid_until IS NULL OR NEW.valid_until > OLD.valid_until)) THEN
                            RAISE EXCEPTION 'Establishment assignment history cannot be replaced' USING ERRCODE = '23514';
                        END IF;
                        IF NEW.cancelled_at IS NOT NULL THEN
                            IF period_end IS NULL OR period_end > OLD.valid_from OR NEW.valid_until IS DISTINCT FROM OLD.valid_until THEN
                                RAISE EXCEPTION 'Assignment is still within employment' USING ERRCODE = '23514';
                            END IF;
                            NEW.cancelled_at := write_time;
                        ELSIF NEW.valid_until IS DISTINCT FROM OLD.valid_until AND NEW.valid_until < write_time
                            AND NEW.valid_until IS DISTINCT FROM period_end THEN
                            RAISE EXCEPTION 'Transfers cannot alter prior effective assignments' USING ERRCODE = '23514';
                        END IF;
                        RETURN NEW;
                    END IF;
                    IF TG_OP <> 'INSERT' THEN
                        RAISE EXCEPTION 'Establishment assignments are retained' USING ERRCODE = '23514';
                    END IF;
                    IF NEW.cancelled_at IS NOT NULL THEN
                        RAISE EXCEPTION 'New establishment assignments must be effective' USING ERRCODE = '23514';
                    END IF;

                    -- Take the period lock before the establishment lock, as
                    -- the service does. Bump the same establishment row used
                    -- for version appends to invalidate stale snapshots.
                    EXECUTE format(
                        'SELECT id FROM %I.employment_periods WHERE tenant_id = $1 AND employee_id = $2 AND id = $3 FOR UPDATE',
                        TG_TABLE_SCHEMA
                    ) USING NEW.tenant_id, NEW.employee_id, NEW.employment_period_id;
                    EXECUTE format(
                        'UPDATE %I.establishments SET tenant_id = tenant_id WHERE tenant_id = $1 AND id = $2 RETURNING id',
                        TG_TABLE_SCHEMA
                    ) INTO locked_establishment USING NEW.tenant_id, NEW.establishment_id;
                    IF locked_establishment IS NULL THEN
                        RAISE EXCEPTION 'Establishment is unavailable' USING ERRCODE = '23503';
                    END IF;
                    EXECUTE format(
                        'SELECT min(effective_at), bool_or(is_closed) FROM %I.establishment_versions
                         WHERE tenant_id = $1 AND establishment_id = $2',
                        TG_TABLE_SCHEMA
                    ) INTO first_version_at, already_closed USING NEW.tenant_id, NEW.establishment_id;
                    IF first_version_at IS NULL OR NEW.valid_from < first_version_at OR already_closed THEN
                        RAISE EXCEPTION 'Establishment is unavailable during the requested assignment' USING ERRCODE = '23514';
                    END IF;

                    RETURN NEW;
                END;
                $$;

                CREATE TRIGGER establishment_assignments_history
                    BEFORE INSERT OR UPDATE OR DELETE ON employee_establishment_assignments
                    FOR EACH ROW EXECUTE FUNCTION enforce_establishment_assignment_history();

                CREATE TRIGGER establishment_assignments_no_truncate
                    BEFORE TRUNCATE ON employee_establishment_assignments
                    FOR EACH STATEMENT EXECUTE FUNCTION enforce_establishment_assignment_history();

                CREATE FUNCTION end_establishment_assignments() RETURNS trigger
                LANGUAGE plpgsql SET search_path TO pg_catalog, pg_temp AS $$
                BEGIN
                    EXECUTE format(
                        'UPDATE %I.employee_establishment_assignments
                         SET valid_until = CASE WHEN valid_from < $4 THEN $4 ELSE valid_until END,
                             cancelled_at = CASE WHEN valid_from >= $4 THEN clock_timestamp() END
                         WHERE tenant_id = $1 AND employee_id = $2 AND employment_period_id = $3
                           AND cancelled_at IS NULL AND (valid_until IS NULL OR valid_until > $4)',
                        TG_TABLE_SCHEMA
                    ) USING NEW.tenant_id, NEW.employee_id, NEW.id, NEW.valid_until;
                    RETURN NEW;
                END;
                $$;

                -- PostgreSQL orders same-kind triggers by name. Trim before
                -- RI_ConstraintTrigger_a_* when constraints run immediately.
                CREATE TRIGGER "00_employment_periods_end_assignments"
                    AFTER UPDATE OF valid_until ON employment_periods
                    FOR EACH ROW WHEN (NEW.valid_until IS DISTINCT FROM OLD.valid_until)
                    EXECUTE FUNCTION end_establishment_assignments();

                CREATE FUNCTION guard_establishment_closure() RETURNS trigger
                LANGUAGE plpgsql SET search_path TO pg_catalog, pg_temp AS $$
                DECLARE
                    has_assignments boolean;
                BEGIN
                    EXECUTE format(
                        'SELECT EXISTS (SELECT 1 FROM %I.employee_establishment_assignments
                         WHERE tenant_id = $1 AND establishment_id = $2 AND cancelled_at IS NULL
                           AND (valid_until IS NULL OR valid_until > $3))',
                        TG_TABLE_SCHEMA
                    ) INTO has_assignments USING NEW.tenant_id, NEW.establishment_id, NEW.effective_at;
                    IF has_assignments THEN
                        RAISE EXCEPTION 'Establishment has current or scheduled assignments' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END;
                $$;

                CREATE TRIGGER establishment_versions_guard_closure
                    AFTER INSERT ON establishment_versions
                    FOR EACH ROW WHEN (NEW.is_closed) EXECUTE FUNCTION guard_establishment_closure();
                SQL);
        });
    }
}

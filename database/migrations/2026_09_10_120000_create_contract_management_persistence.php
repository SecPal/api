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
            throw new RuntimeException('The contract management persistence model requires PostgreSQL.');
        }

        Schema::create('contracts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenant_keys')->cascadeOnDelete();
            $table->uuid('customer_id');
            $table->string('type', 16);
            $table->string('status', 16)->default('active');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('billing_unit', 16);
            $table->decimal('unit_price', 14, 4);
            $table->string('currency_code', 3);
            $table->timestampTz('retired_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id'], 'contracts_tenant_id_id_unique');
            $table->foreign(
                ['tenant_id', 'customer_id'],
                'contracts_tenant_customer_foreign'
            )->references(['tenant_id', 'id'])->on('customers')->restrictOnDelete();
            $table->index(['tenant_id', 'customer_id'], 'contracts_tenant_customer_index');
            $table->index('customer_id', 'contracts_customer_index');
            $table->index(['tenant_id', 'status'], 'contracts_tenant_status_index');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE contracts
            ADD CONSTRAINT contracts_type_check
            CHECK (type IN ('permanent', 'temporary', 'one_time', 'recurring')),
            ADD CONSTRAINT contracts_status_check
            CHECK (status IN ('active', 'retired')),
            ADD CONSTRAINT contracts_dates_check
            CHECK (ends_on IS NULL OR ends_on >= starts_on),
            ADD CONSTRAINT contracts_billing_unit_check
            CHECK (billing_unit IN ('hour', 'day', 'unit', 'flat')),
            ADD CONSTRAINT contracts_unit_price_check
            CHECK (unit_price <> 'NaN'::numeric AND unit_price >= 0),
            ADD CONSTRAINT contracts_currency_code_check
            CHECK (currency_code COLLATE "C" ~ '^[A-Z]{3}$'),
            ADD CONSTRAINT contracts_lifecycle_check
            CHECK (
                (status = 'active' AND retired_at IS NULL)
                OR (status = 'retired' AND retired_at IS NOT NULL)
            )
            SQL);

        Schema::create('service_bookings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenant_keys')->cascadeOnDelete();
            $table->uuid('contract_id');
            $table->date('service_date');
            $table->decimal('quantity', 14, 4);
            $table->string('billing_unit', 16);
            $table->decimal('unit_price', 14, 4);
            $table->string('currency_code', 3);
            $table->decimal('total', 22, 2)->storedAs('round(quantity * unit_price, 2)');
            $table->string('invoice_state', 16)->default('unbilled');
            $table->timestampTz('invoiced_at')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestampTz('retired_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id'], 'service_bookings_tenant_id_id_unique');
            $table->foreign(
                ['tenant_id', 'contract_id'],
                'service_bookings_tenant_contract_foreign'
            )->references(['tenant_id', 'id'])->on('contracts')->restrictOnDelete();
            $table->index(['tenant_id', 'contract_id'], 'service_bookings_tenant_contract_index');
            $table->index('contract_id', 'service_bookings_contract_index');
            $table->index(
                ['tenant_id', 'service_date', 'status', 'invoice_state'],
                'service_bookings_tenant_date_status_invoice_index'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE service_bookings
            ADD CONSTRAINT service_bookings_quantity_check
            CHECK (quantity <> 'NaN'::numeric AND quantity > 0),
            ADD CONSTRAINT service_bookings_billing_unit_check
            CHECK (billing_unit IN ('hour', 'day', 'unit', 'flat')),
            ADD CONSTRAINT service_bookings_unit_price_check
            CHECK (unit_price <> 'NaN'::numeric AND unit_price >= 0),
            ADD CONSTRAINT service_bookings_currency_code_check
            CHECK (currency_code COLLATE "C" ~ '^[A-Z]{3}$'),
            ADD CONSTRAINT service_bookings_invoice_state_check
            CHECK (invoice_state IN ('unbilled', 'invoiced')),
            ADD CONSTRAINT service_bookings_invoice_lifecycle_check
            CHECK (
                (invoice_state = 'unbilled' AND invoiced_at IS NULL)
                OR (invoice_state = 'invoiced' AND invoiced_at IS NOT NULL)
            ),
            ADD CONSTRAINT service_bookings_status_check
            CHECK (status IN ('active', 'retired')),
            ADD CONSTRAINT service_bookings_lifecycle_check
            CHECK (
                (status = 'active' AND retired_at IS NULL)
                OR (status = 'retired' AND retired_at IS NOT NULL)
            )
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_contract_history()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.tenant_id IS DISTINCT FROM OLD.tenant_id THEN
                    RAISE EXCEPTION 'contract identity and tenant are immutable'
                        USING ERRCODE = '23514';
                END IF;

                PERFORM 1
                FROM contracts
                WHERE tenant_id = OLD.tenant_id
                    AND id = OLD.id
                FOR UPDATE;

                IF (NEW.customer_id IS DISTINCT FROM OLD.customer_id
                    OR NEW.currency_code IS DISTINCT FROM OLD.currency_code)
                    AND EXISTS (
                        SELECT 1
                        FROM service_bookings
                        WHERE tenant_id = OLD.tenant_id
                            AND contract_id = OLD.id
                    ) THEN
                    RAISE EXCEPTION 'contract customer and currency are immutable after booking history exists'
                        USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER contracts_history_guard
            BEFORE UPDATE ON contracts
            FOR EACH ROW
            EXECUTE FUNCTION enforce_contract_history();

            CREATE OR REPLACE FUNCTION enforce_service_booking_history()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                contract_currency varchar(3);
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.invoice_state = 'invoiced' AND pg_trigger_depth() = 1 THEN
                        RAISE EXCEPTION 'invoiced service booking evidence cannot be deleted'
                            USING ERRCODE = '23514';
                    END IF;

                    RETURN OLD;
                END IF;

                IF TG_OP = 'UPDATE' AND (
                    NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.tenant_id IS DISTINCT FROM OLD.tenant_id
                ) THEN
                    RAISE EXCEPTION 'service booking identity and tenant are immutable'
                        USING ERRCODE = '23514';
                END IF;

                SELECT currency_code
                INTO contract_currency
                FROM contracts
                WHERE tenant_id = NEW.tenant_id
                    AND id = NEW.contract_id
                FOR SHARE;

                IF NOT FOUND THEN
                    RAISE EXCEPTION 'service booking contract does not exist in its tenant'
                        USING ERRCODE = '23503';
                END IF;

                IF NEW.currency_code IS DISTINCT FROM contract_currency THEN
                    RAISE EXCEPTION 'service booking currency must match its contract currency'
                        USING ERRCODE = '23514';
                END IF;

                IF TG_OP = 'UPDATE' AND OLD.invoice_state = 'invoiced' AND (
                    NEW.contract_id IS DISTINCT FROM OLD.contract_id
                    OR NEW.service_date IS DISTINCT FROM OLD.service_date
                    OR NEW.quantity IS DISTINCT FROM OLD.quantity
                    OR NEW.billing_unit IS DISTINCT FROM OLD.billing_unit
                    OR NEW.unit_price IS DISTINCT FROM OLD.unit_price
                    OR NEW.currency_code IS DISTINCT FROM OLD.currency_code
                    OR NEW.invoice_state IS DISTINCT FROM OLD.invoice_state
                    OR NEW.invoiced_at IS DISTINCT FROM OLD.invoiced_at
                ) THEN
                    RAISE EXCEPTION 'invoiced service booking financial evidence is immutable'
                        USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER service_bookings_history_guard
            BEFORE INSERT OR UPDATE OR DELETE ON service_bookings
            FOR EACH ROW
            EXECUTE FUNCTION enforce_service_booking_history();
            SQL);

        Schema::create('internal_cost_centers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenant_keys')->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->string('status', 16)->default('active');
            $table->timestampTz('inactive_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id'], 'internal_cost_centers_tenant_id_id_unique');
            $table->unique(['tenant_id', 'code'], 'internal_cost_centers_tenant_code_unique');
            $table->index(['tenant_id', 'status'], 'internal_cost_centers_tenant_status_index');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE internal_cost_centers
            ADD CONSTRAINT internal_cost_centers_code_check
            CHECK (
                char_length(code) BETWEEN 1 AND 64
                AND code !~ '^[[:space:]]|[[:space:]]$'
            ),
            ADD CONSTRAINT internal_cost_centers_name_check
            CHECK (char_length(btrim(name)) BETWEEN 1 AND 255),
            ADD CONSTRAINT internal_cost_centers_status_check
            CHECK (status IN ('active', 'inactive')),
            ADD CONSTRAINT internal_cost_centers_lifecycle_check
            CHECK (
                (status = 'active' AND inactive_at IS NULL)
                OR (status = 'inactive' AND inactive_at IS NOT NULL)
            )
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_internal_cost_center_identity()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.tenant_id IS DISTINCT FROM OLD.tenant_id
                    OR NEW.code IS DISTINCT FROM OLD.code THEN
                    RAISE EXCEPTION 'internal cost center identity and code are immutable'
                        USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER internal_cost_centers_identity_guard
            BEFORE UPDATE ON internal_cost_centers
            FOR EACH ROW
            EXECUTE FUNCTION enforce_internal_cost_center_identity();
            SQL);

        Schema::create('cost_center_allocations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenant_keys')->cascadeOnDelete();
            $table->uuid('service_booking_id');
            $table->uuid('internal_cost_center_id');
            $table->unsignedSmallInteger('share_bps');
            $table->timestampsTz();

            $table->foreign(
                ['tenant_id', 'service_booking_id'],
                'cost_center_allocations_tenant_booking_foreign'
            )->references(['tenant_id', 'id'])->on('service_bookings')->restrictOnDelete();
            $table->foreign(
                ['tenant_id', 'internal_cost_center_id'],
                'cost_center_allocations_tenant_center_foreign'
            )->references(['tenant_id', 'id'])->on('internal_cost_centers')->restrictOnDelete();
            $table->unique(
                ['tenant_id', 'service_booking_id', 'internal_cost_center_id'],
                'cost_center_allocations_booking_center_unique'
            );
            $table->index(
                ['tenant_id', 'service_booking_id'],
                'cost_center_allocations_tenant_booking_index'
            );
            $table->index('service_booking_id', 'cost_center_allocations_booking_index');
            $table->index(
                ['tenant_id', 'internal_cost_center_id'],
                'cost_center_allocations_tenant_center_index'
            );
            $table->index('internal_cost_center_id', 'cost_center_allocations_center_index');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE cost_center_allocations
            ADD CONSTRAINT cost_center_allocations_share_check
            CHECK (share_bps BETWEEN 1 AND 10000)
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION lock_cost_center_allocation_owners()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    PERFORM 1
                    FROM service_bookings
                    WHERE tenant_id = NEW.tenant_id
                        AND id = NEW.service_booking_id
                    FOR UPDATE;
                ELSIF TG_OP = 'DELETE' THEN
                    PERFORM 1
                    FROM service_bookings
                    WHERE tenant_id = OLD.tenant_id
                        AND id = OLD.service_booking_id
                    FOR UPDATE;
                ELSE
                    PERFORM 1
                    FROM service_bookings
                    WHERE (tenant_id = OLD.tenant_id AND id = OLD.service_booking_id)
                        OR (tenant_id = NEW.tenant_id AND id = NEW.service_booking_id)
                    ORDER BY tenant_id, id
                    FOR UPDATE;
                END IF;

                RETURN COALESCE(NEW, OLD);
            END;
            $$;

            CREATE TRIGGER cost_center_allocations_lock_owners
            BEFORE INSERT OR UPDATE OR DELETE ON cost_center_allocations
            FOR EACH ROW
            EXECUTE FUNCTION lock_cost_center_allocation_owners();

            CREATE OR REPLACE FUNCTION enforce_active_cost_center_allocation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                center_status varchar(16);
            BEGIN
                IF TG_OP = 'UPDATE'
                    AND NEW.tenant_id IS NOT DISTINCT FROM OLD.tenant_id
                    AND NEW.service_booking_id IS NOT DISTINCT FROM OLD.service_booking_id
                    AND NEW.internal_cost_center_id IS NOT DISTINCT FROM OLD.internal_cost_center_id THEN
                    RETURN NEW;
                END IF;

                SELECT status
                INTO center_status
                FROM internal_cost_centers
                WHERE tenant_id = NEW.tenant_id
                    AND id = NEW.internal_cost_center_id
                FOR SHARE;

                IF NOT FOUND THEN
                    RAISE EXCEPTION 'allocation cost center does not exist in its tenant'
                        USING ERRCODE = '23503';
                END IF;

                IF center_status <> 'active' THEN
                    RAISE EXCEPTION 'inactive cost centers cannot receive allocations'
                        USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER cost_center_allocations_active_center
            BEFORE INSERT OR UPDATE OF tenant_id, service_booking_id, internal_cost_center_id
            ON cost_center_allocations
            FOR EACH ROW
            EXECUTE FUNCTION enforce_active_cost_center_allocation();

            CREATE OR REPLACE FUNCTION enforce_complete_cost_center_allocation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                allocation_count bigint;
                allocation_total bigint;
            BEGIN
                IF TG_OP IN ('UPDATE', 'DELETE') THEN
                    SELECT count(*), COALESCE(sum(share_bps), 0)
                    INTO allocation_count, allocation_total
                    FROM cost_center_allocations
                    WHERE tenant_id = OLD.tenant_id
                        AND service_booking_id = OLD.service_booking_id;

                    IF allocation_count > 0 AND allocation_total <> 10000 THEN
                        RAISE EXCEPTION 'cost center allocations must total exactly 10000 basis points'
                            USING ERRCODE = '23514';
                    END IF;
                END IF;

                IF TG_OP IN ('INSERT', 'UPDATE')
                    AND (
                        TG_OP = 'INSERT'
                        OR NEW.tenant_id IS DISTINCT FROM OLD.tenant_id
                        OR NEW.service_booking_id IS DISTINCT FROM OLD.service_booking_id
                    ) THEN
                    SELECT count(*), COALESCE(sum(share_bps), 0)
                    INTO allocation_count, allocation_total
                    FROM cost_center_allocations
                    WHERE tenant_id = NEW.tenant_id
                        AND service_booking_id = NEW.service_booking_id;

                    IF allocation_count > 0 AND allocation_total <> 10000 THEN
                        RAISE EXCEPTION 'cost center allocations must total exactly 10000 basis points'
                            USING ERRCODE = '23514';
                    END IF;
                ELSIF TG_OP = 'UPDATE' THEN
                    SELECT count(*), COALESCE(sum(share_bps), 0)
                    INTO allocation_count, allocation_total
                    FROM cost_center_allocations
                    WHERE tenant_id = NEW.tenant_id
                        AND service_booking_id = NEW.service_booking_id;

                    IF allocation_count > 0 AND allocation_total <> 10000 THEN
                        RAISE EXCEPTION 'cost center allocations must total exactly 10000 basis points'
                            USING ERRCODE = '23514';
                    END IF;
                END IF;

                RETURN NULL;
            END;
            $$;

            CREATE CONSTRAINT TRIGGER cost_center_allocations_complete_split
            AFTER INSERT OR UPDATE OR DELETE ON cost_center_allocations
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW
            EXECUTE FUNCTION enforce_complete_cost_center_allocation();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_center_allocations');
        Schema::dropIfExists('internal_cost_centers');
        Schema::dropIfExists('service_bookings');
        Schema::dropIfExists('contracts');

        DB::statement('DROP FUNCTION IF EXISTS enforce_complete_cost_center_allocation()');
        DB::statement('DROP FUNCTION IF EXISTS enforce_active_cost_center_allocation()');
        DB::statement('DROP FUNCTION IF EXISTS lock_cost_center_allocation_owners()');
        DB::statement('DROP FUNCTION IF EXISTS enforce_internal_cost_center_identity()');
        DB::statement('DROP FUNCTION IF EXISTS enforce_service_booking_history()');
        DB::statement('DROP FUNCTION IF EXISTS enforce_contract_history()');
    }
};

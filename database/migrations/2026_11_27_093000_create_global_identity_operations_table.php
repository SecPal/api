<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Services\GlobalIdentity\GlobalIdentityDeliveryState;
use App\Services\GlobalIdentity\GlobalIdentityOperationType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('global_identity_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->enum('operation_type', array_column(GlobalIdentityOperationType::cases(), 'value'));
            $table->uuid('user_id')->nullable()->index();
            $table->bigInteger('email_version')->nullable();
            $table->uuid('invitation_id')->nullable();
            $table->bigInteger('tenant_id')->nullable();
            $table->timestampTz('expires_at');
            $table->char('token_hash', 64)->nullable();
            $table->jsonb('delivery_token_enc')->nullable();
            $table->jsonb('recipient_enc')->nullable();
            $table->uuid('delivery_key_id');
            $table->bigInteger('delivery_key_version');
            $table->enum('delivery_state', array_column(GlobalIdentityDeliveryState::cases(), 'value'));
            $table->uuid('delivery_attempt_id')->nullable()->unique();
            $table->bigInteger('delivery_fence_version')->default(0);
            $table->integer('delivery_attempt_count')->default(0);
            $table->timestampTz('lease_expires_at')->nullable();
            $table->binary('transport_idempotency_key')->nullable();
            $table->string('transport_identity', 64)->nullable();
            $table->boolean('uses_idempotency_key')->default(false);
            $table->string('provider_message_reference', 128)->nullable();
            $table->timestampTz('dispatch_committed_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->string('terminal_reason', 32)->nullable();
            $table->string('last_delivery_error_class', 32)->nullable();
            $table->boolean('quarantined')->default(false);
            $table->timestampsTz();
            $table->index(['delivery_state', 'lease_expires_at'], 'global_identity_delivery_lease');
        });
        DB::statement(<<<'SQL'
            ALTER TABLE global_identity_operations
                ADD CONSTRAINT global_identity_operation_versions CHECK (
                    delivery_key_version > 0 AND delivery_fence_version >= 0
                    AND delivery_attempt_count >= 0
                    AND (email_version IS NULL OR email_version > 0)
                ),
                ADD CONSTRAINT global_identity_operation_attempt_key CHECK (
                    (delivery_attempt_id IS NULL AND transport_idempotency_key IS NULL
                        AND transport_identity IS NULL AND NOT uses_idempotency_key)
                    OR (delivery_attempt_id IS NOT NULL AND transport_idempotency_key IS NOT NULL AND transport_identity IS NOT NULL
                        AND octet_length(transport_idempotency_key) = 32)
                )
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION global_identity_operation_binding_immutable() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF ROW(NEW.id, NEW.operation_type, NEW.user_id, NEW.email_version, NEW.invitation_id, NEW.tenant_id)
                    IS DISTINCT FROM
                   ROW(OLD.id, OLD.operation_type, OLD.user_id, OLD.email_version, OLD.invitation_id, OLD.tenant_id)
                THEN
                    RAISE EXCEPTION 'Global identity operation binding is immutable' USING ERRCODE = '23514';
                END IF;
                IF OLD.delivery_attempt_id IS NOT NULL AND NEW.delivery_attempt_id IS NOT NULL
                    AND (ROW(NEW.delivery_attempt_id, NEW.transport_identity, NEW.transport_idempotency_key)
                        IS DISTINCT FROM ROW(OLD.delivery_attempt_id, OLD.transport_identity, OLD.transport_idempotency_key)
                        OR (OLD.dispatch_committed_at IS NOT NULL
                            AND ROW(NEW.dispatch_committed_at, NEW.uses_idempotency_key)
                                IS DISTINCT FROM ROW(OLD.dispatch_committed_at, OLD.uses_idempotency_key)))
                THEN
                    RAISE EXCEPTION 'Global identity attempt binding is immutable' USING ERRCODE = '23514';
                END IF;
                IF OLD.delivery_attempt_id IS NOT NULL AND NEW.delivery_attempt_id IS NULL
                    AND (NEW.delivery_state NOT IN ('delivered', 'retryable_failed', 'terminal')
                        OR NEW.dispatch_committed_at IS DISTINCT FROM OLD.dispatch_committed_at)
                THEN
                    RAISE EXCEPTION 'Global identity attempt release is invalid' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END
            $$;

            CREATE TRIGGER global_identity_operation_binding
            BEFORE UPDATE ON global_identity_operations FOR EACH ROW
            EXECUTE FUNCTION global_identity_operation_binding_immutable();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('global_identity_operations');
        DB::statement('DROP FUNCTION IF EXISTS global_identity_operation_binding_immutable()');
    }
};

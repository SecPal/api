<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('private_objects', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenant_keys')->restrictOnDelete();
            $table->string('purpose', 32);
            $table->char('object_key', 32)->unique();
            $table->char('backend_identity', 64);
            $table->string('state', 16)->default('PENDING');
            $table->char('storage_sha256', 64);
            $table->unsignedBigInteger('stored_bytes');
            $table->jsonb('crypto');
            $table->jsonb('replication_targets');
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });
        Schema::create('private_object_references', function (Blueprint $table): void {
            $table->uuid('object_id')->primary();
            $table->foreign('object_id')->references('id')->on('private_objects')->restrictOnDelete();
            $table->uuid('employee_document_id')->nullable()->unique();
            $table->uuid('onboarding_submission_file_id')->nullable()->unique();
            $table->timestampTz('tombstoned_at')->nullable();
        });
        Schema::create('object_replication_intents', function (Blueprint $table): void {
            $table->foreignUuid('object_id')->constrained('private_objects')->restrictOnDelete();
            $table->string('target', 128);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('accepted_at')->nullable();
            $table->primary(['object_id', 'target']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
ALTER TABLE private_objects ADD CONSTRAINT private_objects_metadata CHECK (
    state IN ('PENDING','READY','TOMBSTONED') AND purpose IN ('employee-document','onboarding-file')
    AND stored_bytes > 0 AND object_key ~ '^[0-9a-f]{32}$' AND storage_sha256 ~ '^[0-9a-f]{64}$'
    AND jsonb_typeof(replication_targets) = 'array' AND jsonb_array_length(replication_targets) > 0);
ALTER TABLE private_object_references ADD CONSTRAINT private_object_one_reference CHECK (
    (employee_document_id IS NOT NULL)::integer + (onboarding_submission_file_id IS NOT NULL)::integer = 1);

CREATE OR REPLACE FUNCTION private_object_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Private Object retention authority required'; END IF;
    IF (to_jsonb(NEW) - 'state' - 'verified_at') IS DISTINCT FROM (to_jsonb(OLD) - 'state' - 'verified_at')
       OR (OLD.verified_at IS NOT NULL AND NEW.verified_at IS DISTINCT FROM OLD.verified_at)
       OR (NEW.state IS DISTINCT FROM OLD.state AND NOT (
          (OLD.state = 'PENDING' AND NEW.state = 'READY') OR
          (OLD.state = 'READY' AND NEW.state = 'TOMBSTONED'))) THEN
        RAISE EXCEPTION 'Immutable Private Object metadata or lifecycle';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER private_object_immutable BEFORE UPDATE OR DELETE ON private_objects
FOR EACH ROW EXECUTE FUNCTION private_object_immutable();

CREATE OR REPLACE FUNCTION private_object_reference_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' OR (to_jsonb(NEW) - 'tombstoned_at') IS DISTINCT FROM (to_jsonb(OLD) - 'tombstoned_at')
       OR (OLD.tombstoned_at IS NOT NULL AND NEW.tombstoned_at IS DISTINCT FROM OLD.tombstoned_at) THEN
        RAISE EXCEPTION 'Private Object reference identity and retention are immutable';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER private_object_reference_immutable BEFORE UPDATE OR DELETE ON private_object_references
FOR EACH ROW EXECUTE FUNCTION private_object_reference_immutable();

CREATE OR REPLACE FUNCTION private_object_replication_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' OR (to_jsonb(NEW) - 'accepted_at') IS DISTINCT FROM (to_jsonb(OLD) - 'accepted_at')
       OR (OLD.accepted_at IS NOT NULL AND NEW.accepted_at IS DISTINCT FROM OLD.accepted_at) THEN
        RAISE EXCEPTION 'Private Object replication identity and retention are immutable';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER private_object_replication_immutable BEFORE UPDATE OR DELETE ON object_replication_intents
FOR EACH ROW EXECUTE FUNCTION private_object_replication_immutable();

REVOKE ALL ON private_objects, private_object_references, object_replication_intents FROM PUBLIC;
DO $$ BEGIN
    IF to_regrole('secpal_runtime') IS NOT NULL THEN
        REVOKE ALL ON private_objects, private_object_references, object_replication_intents FROM secpal_runtime;
        GRANT SELECT, INSERT ON private_objects, private_object_references, object_replication_intents TO secpal_runtime;
        GRANT UPDATE (state, verified_at) ON private_objects TO secpal_runtime;
        GRANT UPDATE (tombstoned_at) ON private_object_references TO secpal_runtime;
    END IF;
END $$;

CREATE OR REPLACE FUNCTION private_object_publication() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    oid uuid;
    obj private_objects%ROWTYPE;
    ref private_object_references%ROWTYPE;
    owner_tenant bigint;
    owner_deleted boolean;
    owner_key text;
    owner_matches boolean;
    owner_found boolean := false;
    declared_tenant bigint;
    retained_missing_owner boolean;
BEGIN
    IF TG_TABLE_NAME = 'private_objects' THEN oid := NEW.id;
    ELSIF TG_TABLE_NAME IN ('employee_documents','onboarding_submission_files') THEN
        IF TG_TABLE_NAME = 'employee_documents' THEN
            SELECT object_id INTO oid FROM private_object_references WHERE employee_document_id = OLD.id;
        ELSE
            SELECT object_id INTO oid FROM private_object_references WHERE onboarding_submission_file_id = OLD.id;
        END IF;
    ELSE oid := COALESCE(NEW.object_id, OLD.object_id);
    END IF;
    IF oid IS NULL THEN RETURN NULL; END IF;
    SELECT * INTO obj FROM private_objects WHERE id = oid;
    IF TG_TABLE_NAME = 'private_object_references' AND TG_WHEN = 'BEFORE' THEN ref := NEW;
    ELSE SELECT * INTO ref FROM private_object_references WHERE object_id = oid;
    END IF;
    IF obj.state = 'PENDING' AND TG_WHEN <> 'BEFORE' THEN
        IF ref.object_id IS NOT NULL OR EXISTS (SELECT 1 FROM object_replication_intents WHERE object_id = oid) THEN
            RAISE EXCEPTION 'Pending Private Object cannot have published references';
        END IF;
        RETURN NULL;
    END IF;
    IF obj.verified_at IS NULL OR ref.object_id IS NULL THEN
        RAISE EXCEPTION 'Private Object publication is incomplete';
    END IF;
    IF ref.employee_document_id IS NOT NULL AND obj.purpose = 'employee-document' THEN
        SELECT e.tenant_id, d.deleted_at IS NOT NULL, d.file_path INTO owner_tenant, owner_deleted, owner_key
        FROM employee_documents d JOIN employees e ON e.id = d.employee_id WHERE d.id = ref.employee_document_id;
        owner_found := FOUND;
    ELSIF ref.onboarding_submission_file_id IS NOT NULL AND obj.purpose = 'onboarding-file' THEN
        SELECT e.tenant_id, f.deleted_at IS NOT NULL, f.file_path, f.tenant_id INTO owner_tenant, owner_deleted, owner_key, declared_tenant
        FROM onboarding_submission_files f
        JOIN onboarding_form_submissions s ON s.id = f.onboarding_form_submission_id
        JOIN employees e ON e.id = s.employee_id WHERE f.id = ref.onboarding_submission_file_id;
        owner_found := FOUND;
    END IF;
    owner_matches := owner_found AND owner_tenant IS NOT DISTINCT FROM obj.tenant_id AND owner_key IS NOT DISTINCT FROM obj.object_key
        AND (obj.purpose = 'employee-document' OR declared_tenant IS NOT DISTINCT FROM owner_tenant);
    IF TG_WHEN = 'BEFORE' THEN
        IF NOT owner_matches THEN RAISE EXCEPTION 'Private Object business scope is invalid'; END IF;
        RETURN NEW;
    END IF;
    retained_missing_owner := NOT owner_found AND obj.state = 'TOMBSTONED' AND ref.tombstoned_at IS NOT NULL;
    IF NOT (owner_matches OR retained_missing_owner) OR
       (NOT retained_missing_owner AND owner_deleted IS DISTINCT FROM (ref.tombstoned_at IS NOT NULL)) OR
       (obj.state = 'TOMBSTONED') IS DISTINCT FROM (ref.tombstoned_at IS NOT NULL) OR
       EXISTS (SELECT 1 FROM jsonb_array_elements_text(obj.replication_targets) t
               WHERE NOT EXISTS (SELECT 1 FROM object_replication_intents i WHERE i.object_id = oid AND i.target = t)) THEN
        RAISE EXCEPTION 'Private Object tenant, reference or replication invariant';
    END IF;
    RETURN NULL;
END $$;

CREATE TRIGGER private_object_reference_scope BEFORE INSERT ON private_object_references
FOR EACH ROW EXECUTE FUNCTION private_object_publication();

CREATE CONSTRAINT TRIGGER private_object_publication AFTER INSERT OR UPDATE ON private_objects
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION private_object_publication();
CREATE CONSTRAINT TRIGGER private_object_reference_publication AFTER INSERT OR UPDATE OR DELETE ON private_object_references
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION private_object_publication();
CREATE CONSTRAINT TRIGGER private_object_replication_publication AFTER INSERT OR UPDATE OR DELETE ON object_replication_intents
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION private_object_publication();
CREATE CONSTRAINT TRIGGER employee_private_object_reference AFTER UPDATE OR DELETE ON employee_documents
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION private_object_publication();
CREATE CONSTRAINT TRIGGER onboarding_private_object_reference AFTER UPDATE OR DELETE ON onboarding_submission_files
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION private_object_publication();
SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS employee_private_object_reference ON employee_documents; DROP TRIGGER IF EXISTS onboarding_private_object_reference ON onboarding_submission_files;');
        }
        Schema::dropIfExists('object_replication_intents');
        Schema::dropIfExists('private_object_references');
        Schema::dropIfExists('private_objects');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS private_object_publication(); DROP FUNCTION IF EXISTS private_object_immutable(); DROP FUNCTION IF EXISTS private_object_reference_immutable(); DROP FUNCTION IF EXISTS private_object_replication_immutable();');
        }
    }
};

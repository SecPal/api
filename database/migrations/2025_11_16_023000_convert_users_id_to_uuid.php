<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Convert users.id from bigint to uuid.
     * This migration must run BEFORE create_secrets_table.
     */
    public function up(): void
    {
        // Drop ALL foreign key constraints that reference users.id
        DB::statement('ALTER TABLE model_has_roles DROP CONSTRAINT IF EXISTS model_has_roles_model_id_foreign');
        DB::statement('ALTER TABLE model_has_roles DROP CONSTRAINT IF EXISTS model_has_roles_assigned_by_foreign');
        DB::statement('ALTER TABLE model_has_permissions DROP CONSTRAINT IF EXISTS model_has_permissions_model_id_foreign');
        DB::statement('ALTER TABLE model_has_permissions DROP CONSTRAINT IF EXISTS model_has_permissions_assigned_by_foreign');
        DB::statement('ALTER TABLE role_assignments_log DROP CONSTRAINT IF EXISTS role_assignments_log_user_id_foreign');
        DB::statement('ALTER TABLE role_assignments_log DROP CONSTRAINT IF EXISTS role_assignments_log_assigned_by_foreign');

        // Drop the default value before changing type
        DB::statement('ALTER TABLE users ALTER COLUMN id DROP DEFAULT');

        // Convert users.id from bigint to uuid
        DB::statement('ALTER TABLE users ALTER COLUMN id TYPE uuid USING gen_random_uuid()');

        // Convert ALL foreign keys from bigint to uuid
        // Note: gen_random_uuid() generates new UUIDs that won't match users.id,
        // effectively orphaning existing data. This is acceptable for pre-1.0
        // development where databases are regularly refreshed.
        DB::statement('ALTER TABLE personal_access_tokens ALTER COLUMN tokenable_id TYPE uuid USING gen_random_uuid()');
        DB::statement('ALTER TABLE sessions ALTER COLUMN user_id TYPE uuid USING gen_random_uuid()');
        DB::statement('ALTER TABLE model_has_roles ALTER COLUMN model_id TYPE uuid USING gen_random_uuid()');
        DB::statement('ALTER TABLE model_has_roles ALTER COLUMN assigned_by TYPE uuid USING gen_random_uuid()');
        DB::statement('ALTER TABLE model_has_permissions ALTER COLUMN model_id TYPE uuid USING gen_random_uuid()');
        DB::statement('ALTER TABLE model_has_permissions ALTER COLUMN assigned_by TYPE uuid USING gen_random_uuid()');
        DB::statement('ALTER TABLE role_assignments_log ALTER COLUMN user_id TYPE uuid USING gen_random_uuid()');
        DB::statement('ALTER TABLE role_assignments_log ALTER COLUMN assigned_by TYPE uuid USING gen_random_uuid()');

        // Recreate ALL foreign keys
        DB::statement('ALTER TABLE model_has_roles ADD CONSTRAINT model_has_roles_model_id_foreign FOREIGN KEY (model_id) REFERENCES users(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE model_has_roles ADD CONSTRAINT model_has_roles_assigned_by_foreign FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL');
        DB::statement('ALTER TABLE model_has_permissions ADD CONSTRAINT model_has_permissions_model_id_foreign FOREIGN KEY (model_id) REFERENCES users(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE model_has_permissions ADD CONSTRAINT model_has_permissions_assigned_by_foreign FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL');
        DB::statement('ALTER TABLE role_assignments_log ADD CONSTRAINT role_assignments_log_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE role_assignments_log ADD CONSTRAINT role_assignments_log_assigned_by_foreign FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE CASCADE');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop ALL foreign key constraints
        DB::statement('ALTER TABLE model_has_roles DROP CONSTRAINT IF EXISTS model_has_roles_model_id_foreign');
        DB::statement('ALTER TABLE model_has_roles DROP CONSTRAINT IF EXISTS model_has_roles_assigned_by_foreign');
        DB::statement('ALTER TABLE model_has_permissions DROP CONSTRAINT IF EXISTS model_has_permissions_model_id_foreign');
        DB::statement('ALTER TABLE model_has_permissions DROP CONSTRAINT IF EXISTS model_has_permissions_assigned_by_foreign');
        DB::statement('ALTER TABLE role_assignments_log DROP CONSTRAINT IF EXISTS role_assignments_log_user_id_foreign');
        DB::statement('ALTER TABLE role_assignments_log DROP CONSTRAINT IF EXISTS role_assignments_log_assigned_by_foreign');

        // Convert back to bigint (data loss warning!)
        DB::statement('ALTER TABLE users ALTER COLUMN id TYPE bigint USING 1');
        DB::statement('ALTER TABLE users ALTER COLUMN id SET DEFAULT nextval(\'users_id_seq\')');

        DB::statement('ALTER TABLE personal_access_tokens ALTER COLUMN tokenable_id TYPE bigint USING 1');
        DB::statement('ALTER TABLE sessions ALTER COLUMN user_id TYPE bigint USING 1');
        DB::statement('ALTER TABLE model_has_roles ALTER COLUMN model_id TYPE bigint USING 1');
        DB::statement('ALTER TABLE model_has_roles ALTER COLUMN assigned_by TYPE bigint USING 1');
        DB::statement('ALTER TABLE model_has_permissions ALTER COLUMN model_id TYPE bigint USING 1');
        DB::statement('ALTER TABLE model_has_permissions ALTER COLUMN assigned_by TYPE bigint USING 1');
        DB::statement('ALTER TABLE role_assignments_log ALTER COLUMN user_id TYPE bigint USING 1');
        DB::statement('ALTER TABLE role_assignments_log ALTER COLUMN assigned_by TYPE bigint USING 1');

        // Recreate ALL foreign keys
        DB::statement('ALTER TABLE model_has_roles ADD CONSTRAINT model_has_roles_model_id_foreign FOREIGN KEY (model_id) REFERENCES users(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE model_has_roles ADD CONSTRAINT model_has_roles_assigned_by_foreign FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL');
        DB::statement('ALTER TABLE model_has_permissions ADD CONSTRAINT model_has_permissions_model_id_foreign FOREIGN KEY (model_id) REFERENCES users(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE model_has_permissions ADD CONSTRAINT model_has_permissions_assigned_by_foreign FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL');
        DB::statement('ALTER TABLE role_assignments_log ADD CONSTRAINT role_assignments_log_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE role_assignments_log ADD CONSTRAINT role_assignments_log_assigned_by_foreign FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE CASCADE');
    }
};

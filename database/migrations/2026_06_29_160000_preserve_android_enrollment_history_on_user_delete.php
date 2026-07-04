<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            $this->rebuildSqliteCreatedByReference(nullable: true, onDeleteAction: 'set null');

            return;
        }

        DB::statement('ALTER TABLE android_enrollment_sessions DROP CONSTRAINT IF EXISTS android_enrollment_sessions_created_by_foreign');
        DB::statement('ALTER TABLE android_enrollment_sessions ALTER COLUMN created_by DROP NOT NULL');
        DB::statement('ALTER TABLE android_enrollment_sessions ADD CONSTRAINT android_enrollment_sessions_created_by_foreign FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL');
    }

    public function down(): void
    {
        DB::table('android_enrollment_sessions')
            ->whereNull('created_by')
            ->delete();

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            $this->rebuildSqliteCreatedByReference(nullable: false, onDeleteAction: 'cascade');

            return;
        }

        DB::statement('ALTER TABLE android_enrollment_sessions DROP CONSTRAINT IF EXISTS android_enrollment_sessions_created_by_foreign');
        DB::statement('ALTER TABLE android_enrollment_sessions ALTER COLUMN created_by SET NOT NULL');
        DB::statement('ALTER TABLE android_enrollment_sessions ADD CONSTRAINT android_enrollment_sessions_created_by_foreign FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE');
    }

    private function rebuildSqliteCreatedByReference(bool $nullable, string $onDeleteAction): void
    {
        /** @var object{sql: string}|null $tableDefinition */
        $tableDefinition = DB::selectOne(
            "select sql from sqlite_master where type = 'table' and name = 'android_enrollment_sessions'"
        );

        if (! is_object($tableDefinition) || ! isset($tableDefinition->sql) || ! is_string($tableDefinition->sql)) {
            throw new RuntimeException('Unable to load SQLite android_enrollment_sessions table definition.');
        }

        $rewrittenSql = preg_replace(
            '/"created_by" varchar(?: not null)?/',
            $nullable ? '"created_by" varchar' : '"created_by" varchar not null',
            $tableDefinition->sql,
            1,
            $columnReplacementCount,
        );

        if (! is_string($rewrittenSql) || $columnReplacementCount !== 1) {
            throw new RuntimeException('Unable to rewrite SQLite android_enrollment_sessions.created_by nullability.');
        }

        $rewrittenSql = preg_replace(
            '/foreign key\("created_by"\) references "users"\("id"\)(?: on delete (?:cascade|set null))?/',
            sprintf('foreign key("created_by") references "users"("id") on delete %s', $onDeleteAction),
            $rewrittenSql,
            1,
            $foreignKeyReplacementCount,
        );

        if (! is_string($rewrittenSql) || $foreignKeyReplacementCount !== 1) {
            throw new RuntimeException('Unable to rewrite SQLite android_enrollment_sessions.created_by foreign key.');
        }

        $temporaryTable = '__android_enrollment_sessions_rewrite';
        $temporaryTableSql = preg_replace(
            '/^CREATE TABLE "android_enrollment_sessions"/',
            sprintf('CREATE TABLE "%s"', $temporaryTable),
            $rewrittenSql,
            1,
            $renamedTableCount,
        );

        if (! is_string($temporaryTableSql) || $renamedTableCount !== 1) {
            throw new RuntimeException('Unable to build temporary SQLite android_enrollment_sessions table definition.');
        }

        /** @var list<object{sql: string}> $schemaObjects */
        $schemaObjects = DB::select(
            "select sql from sqlite_master where tbl_name = 'android_enrollment_sessions' and type in ('index', 'trigger') and sql is not null order by type, name"
        );

        /** @var list<object{name: string}> $columns */
        $columns = DB::select('pragma table_info("android_enrollment_sessions")');
        $columnList = implode(', ', array_map(
            static fn (object $column): string => '"'.$column->name.'"',
            $columns,
        ));

        $foreignKeysEnabledValue = DB::scalar('pragma foreign_keys');
        $foreignKeysEnabled = in_array($foreignKeysEnabledValue, [1, '1'], true);

        if ($foreignKeysEnabled) {
            DB::statement('pragma foreign_keys = off');
        }

        try {
            DB::statement($temporaryTableSql);
            DB::statement(sprintf(
                'insert into "%s" (%s) select %s from "android_enrollment_sessions"',
                $temporaryTable,
                $columnList,
                $columnList,
            ));
            DB::statement('drop table "android_enrollment_sessions"');
            DB::statement(sprintf(
                'alter table "%s" rename to "android_enrollment_sessions"',
                $temporaryTable,
            ));

            foreach ($schemaObjects as $schemaObject) {
                DB::statement($schemaObject->sql);
            }
        } finally {
            if ($foreignKeysEnabled) {
                DB::statement('pragma foreign_keys = on');
            }
        }
    }
};

<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('user_internal_organizational_scopes')
            ->where('access_level', 'admin')
            ->update(['access_level' => 'manage']);

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            $this->rewriteSqliteAccessLevelConstraint();

            return;
        }

        DB::statement('ALTER TABLE user_internal_organizational_scopes DROP CONSTRAINT IF EXISTS user_internal_organizational_scopes_access_level_check');
        DB::statement(<<<'SQL'
            ALTER TABLE user_internal_organizational_scopes
            ADD CONSTRAINT user_internal_organizational_scopes_access_level_check
            CHECK (access_level IN ('none', 'read', 'write', 'manage'))
        SQL);
    }

    /**
     * Reverse the migrations.
     *
     * This migration is intentionally non-reversible: the up() step normalised
     * all 'admin' rows to 'manage' and there is no way to distinguish which
     * 'manage' rows were originally 'admin'. Attempting to roll back would
     * silently leave the data in an inconsistent state.
     *
     * @throws RuntimeException always, to prevent silent data loss on rollback
     */
    public function down(): void
    {
        throw new RuntimeException(
            'Migration '.self::class.' cannot be rolled back: '
            .'the admin→manage normalisation is irreversible. '
            .'Restore from a pre-migration backup if a rollback is required.'
        );
    }

    private function rewriteSqliteAccessLevelConstraint(): void
    {
        $temporaryTable = '__user_internal_organizational_scopes_access_level_rewrite';

        /** @var object{sql: string}|null $tableDefinition */
        $tableDefinition = DB::selectOne(
            "select sql from sqlite_master where type = 'table' and name = 'user_internal_organizational_scopes'"
        );

        if (! is_object($tableDefinition) || ! isset($tableDefinition->sql) || ! is_string($tableDefinition->sql)) {
            throw new RuntimeException('Unable to load SQLite user_internal_organizational_scopes table definition.');
        }

        $rewrittenTableSql = $this->replaceSqliteAccessLevelCheck($tableDefinition->sql);
        $temporaryTableSql = preg_replace(
            '/^CREATE TABLE "user_internal_organizational_scopes"/',
            sprintf('CREATE TABLE "%s"', $temporaryTable),
            $rewrittenTableSql,
            1,
            $renamedTableCount,
        );

        if (! is_string($temporaryTableSql) || $renamedTableCount !== 1) {
            throw new RuntimeException('Unable to build temporary SQLite table definition for access level rewrite.');
        }

        /** @var list<object{sql: string}> $schemaObjects */
        $schemaObjects = DB::select(
            "select sql from sqlite_master where tbl_name = 'user_internal_organizational_scopes' and type in ('index', 'trigger') and sql is not null order by type, name"
        );

        /** @var list<object{name: string}> $columns */
        $columns = DB::select('pragma table_info("user_internal_organizational_scopes")');
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
                'insert into "%s" (%s) select %s from "user_internal_organizational_scopes"',
                $temporaryTable,
                $columnList,
                $columnList,
            ));
            DB::statement('drop table "user_internal_organizational_scopes"');
            DB::statement(sprintf(
                'alter table "%s" rename to "user_internal_organizational_scopes"',
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

    private function replaceSqliteAccessLevelCheck(string $tableSql): string
    {
        $rewrittenSql = preg_replace(
            '/"access_level" varchar check \("access_level" in \([^)]+\)\)/',
            '"access_level" varchar check ("access_level" in (\'none\', \'read\', \'write\', \'manage\'))',
            $tableSql,
            1,
            $replacementCount,
        );

        if (! is_string($rewrittenSql) || $replacementCount !== 1) {
            throw new RuntimeException('Unable to rewrite SQLite access level check constraint.');
        }

        return $rewrittenSql;
    }
};

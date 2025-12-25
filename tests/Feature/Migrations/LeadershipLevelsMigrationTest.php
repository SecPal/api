<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

describe('Leadership Levels Migrations', function () {
    describe('leadership_levels table schema', function () {
        it('has all required columns', function (): void {
            expect(Schema::hasTable('leadership_levels'))->toBeTrue();

            $columns = [
                'id',
                'tenant_id',
                'rank',
                'name',
                'description',
                'color',
                'is_active',
                'created_at',
                'updated_at',
                'deleted_at',
            ];

            foreach ($columns as $column) {
                expect(Schema::hasColumn('leadership_levels', $column))
                    ->toBeTrue("Column '{$column}' should exist");
            }
        });

        it('has correct column types', function (): void {
            $columns = Schema::getColumns('leadership_levels');
            $columnMap = collect($columns)->keyBy('name');

            // UUID primary key
            expect($columnMap['id']['type_name'])->toBe('uuid');

            // Foreign key to tenant_keys
            expect($columnMap['tenant_id']['type_name'])->toBe('int8');
            expect($columnMap['tenant_id']['nullable'])->toBeFalse();

            // Rank: unsigned tiny integer
            expect($columnMap['rank']['type_name'])->toBe('int2');
            expect($columnMap['rank']['nullable'])->toBeFalse();

            // Name: string(100)
            expect($columnMap['name']['type_name'])->toBe('varchar');
            expect($columnMap['name']['nullable'])->toBeFalse();

            // Description: nullable text
            expect($columnMap['description']['type_name'])->toBe('text');
            expect($columnMap['description']['nullable'])->toBeTrue();

            // Color: nullable string(7)
            expect($columnMap['color']['type_name'])->toBe('varchar');
            expect($columnMap['color']['nullable'])->toBeTrue();

            // is_active: boolean with default true
            expect($columnMap['is_active']['type_name'])->toBe('bool');
            expect($columnMap['is_active']['nullable'])->toBeFalse();
        });

        it('has unique constraint on tenant_id and rank', function (): void {
            $indexes = Schema::getIndexes('leadership_levels');

            $uniqueIndexExists = collect($indexes)->contains(function ($index) {
                return $index['name'] === 'leadership_levels_tenant_rank_unique'
                    && $index['columns'] === ['tenant_id', 'rank']
                    && $index['unique'] === true;
            });

            expect($uniqueIndexExists)->toBeTrue('Unique constraint on (tenant_id, rank) should exist');
        });

        it('has unique constraint on tenant_id and name', function (): void {
            $indexes = Schema::getIndexes('leadership_levels');

            $uniqueIndexExists = collect($indexes)->contains(function ($index) {
                return $index['name'] === 'leadership_levels_tenant_name_unique'
                    && $index['columns'] === ['tenant_id', 'name']
                    && $index['unique'] === true;
            });

            expect($uniqueIndexExists)->toBeTrue('Unique constraint on (tenant_id, name) should exist');
        });

        it('has index on tenant_id, is_active, and rank', function (): void {
            $indexes = Schema::getIndexes('leadership_levels');

            $indexExists = collect($indexes)->contains(function ($index) {
                return $index['name'] === 'leadership_levels_tenant_active_rank_idx'
                    && $index['columns'] === ['tenant_id', 'is_active', 'rank'];
            });

            expect($indexExists)->toBeTrue('Index on (tenant_id, is_active, rank) should exist');
        });

        it('has foreign key to tenant_keys', function (): void {
            $foreignKeys = Schema::getForeignKeys('leadership_levels');

            $fkExists = collect($foreignKeys)->contains(function ($fk) {
                return $fk['columns'] === ['tenant_id']
                    && $fk['foreign_table'] === 'tenant_keys'
                    && $fk['foreign_columns'] === ['id']
                    && $fk['on_delete'] === 'cascade';
            });

            expect($fkExists)->toBeTrue('Foreign key to tenant_keys with CASCADE should exist');
        });

        it('supports soft deletes', function (): void {
            expect(Schema::hasColumn('leadership_levels', 'deleted_at'))->toBeTrue();
        });
    });

    describe('employees.leadership_level_id column', function () {
        it('has leadership_level_id column', function (): void {
            expect(Schema::hasColumn('employees', 'leadership_level_id'))
                ->toBeTrue('leadership_level_id column should exist in employees table');
        });

        it('leadership_level_id is nullable UUID', function (): void {
            $columns = Schema::getColumns('employees');
            $column = collect($columns)->firstWhere('name', 'leadership_level_id');

            expect($column)->not->toBeNull('leadership_level_id column should exist');
            expect($column['type_name'])->toBe('uuid');
            expect($column['nullable'])->toBeTrue('leadership_level_id should be nullable');
        });

        it('has foreign key to leadership_levels', function (): void {
            $foreignKeys = Schema::getForeignKeys('employees');

            $fkExists = collect($foreignKeys)->contains(function ($fk) {
                return $fk['columns'] === ['leadership_level_id']
                    && $fk['foreign_table'] === 'leadership_levels'
                    && $fk['foreign_columns'] === ['id']
                    && $fk['on_delete'] === 'set null';
            });

            expect($fkExists)->toBeTrue('Foreign key to leadership_levels with SET NULL should exist');
        });

        it('has index on tenant_id and leadership_level_id', function (): void {
            $indexes = Schema::getIndexes('employees');

            $indexExists = collect($indexes)->contains(function ($index) {
                return $index['name'] === 'employees_tenant_leadership_idx'
                    && $index['columns'] === ['tenant_id', 'leadership_level_id'];
            });

            expect($indexExists)->toBeTrue('Index on (tenant_id, leadership_level_id) should exist');
        });
    });

    describe('user_internal_organizational_scopes rank filters', function () {
        it('has min_viewable_rank column', function (): void {
            expect(Schema::hasColumn('user_internal_organizational_scopes', 'min_viewable_rank'))
                ->toBeTrue('min_viewable_rank column should exist');
        });

        it('has max_viewable_rank column', function (): void {
            expect(Schema::hasColumn('user_internal_organizational_scopes', 'max_viewable_rank'))
                ->toBeTrue('max_viewable_rank column should exist');
        });

        it('rank filter columns are nullable unsigned tiny integers', function (): void {
            $columns = Schema::getColumns('user_internal_organizational_scopes');
            $columnMap = collect($columns)->keyBy('name');

            expect($columnMap['min_viewable_rank']['type_name'])->toBe('int2');
            expect($columnMap['min_viewable_rank']['nullable'])->toBeTrue();

            expect($columnMap['max_viewable_rank']['type_name'])->toBe('int2');
            expect($columnMap['max_viewable_rank']['nullable'])->toBeTrue();
        });

        it('has index on user_id and rank filters', function (): void {
            $indexes = Schema::getIndexes('user_internal_organizational_scopes');

            $indexExists = collect($indexes)->contains(function ($index) {
                return $index['name'] === 'user_org_scopes_user_rank_filters_idx'
                    && $index['columns'] === ['user_id', 'min_viewable_rank', 'max_viewable_rank'];
            });

            expect($indexExists)->toBeTrue('Index on (user_id, min_viewable_rank, max_viewable_rank) should exist');
        });
    });

    describe('migration rollback', function () {
        it('can rollback leadership_levels table', function (): void {
            // Table should exist after migrations
            expect(Schema::hasTable('leadership_levels'))->toBeTrue();

            // Rollback the specific migration
            $this->artisan('migrate:rollback', ['--step' => 3]);

            // Table should be gone
            expect(Schema::hasTable('leadership_levels'))->toBeFalse();

            // Re-run migrations for subsequent tests
            $this->artisan('migrate');
        });
    });
});

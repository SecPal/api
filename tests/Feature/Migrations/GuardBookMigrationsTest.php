<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Tests for Guard Book database migrations (Issue #233).
 *
 * These tests verify:
 * - guard_books table structure and constraints
 * - guard_book_reports table structure and constraints
 * - Foreign key constraints
 * - XOR constraint (object_id OR object_area_id, but not both)
 * - Indexes for performance-critical queries
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

describe('guard_books migration', function (): void {
    it('creates guard_books table with correct columns', function (): void {
        expect(Schema::hasTable('guard_books'))->toBeTrue();

        expect(Schema::hasColumn('guard_books', 'id'))->toBeTrue()
            ->and(Schema::hasColumn('guard_books', 'tenant_id'))->toBeTrue()
            ->and(Schema::hasColumn('guard_books', 'object_id'))->toBeTrue()
            ->and(Schema::hasColumn('guard_books', 'object_area_id'))->toBeTrue()
            ->and(Schema::hasColumn('guard_books', 'title'))->toBeTrue()
            ->and(Schema::hasColumn('guard_books', 'description'))->toBeTrue()
            ->and(Schema::hasColumn('guard_books', 'is_active'))->toBeTrue()
            ->and(Schema::hasColumn('guard_books', 'archived_at'))->toBeTrue()
            ->and(Schema::hasColumn('guard_books', 'created_at'))->toBeTrue()
            ->and(Schema::hasColumn('guard_books', 'updated_at'))->toBeTrue()
            ->and(Schema::hasColumn('guard_books', 'deleted_at'))->toBeTrue();
    });

    it('has foreign key constraint to tenant_keys', function (): void {
        $foreignKeys = DB::select("
            SELECT tc.constraint_name, kcu.column_name, ccu.table_name AS foreign_table_name
            FROM information_schema.table_constraints AS tc
            JOIN information_schema.key_column_usage AS kcu ON tc.constraint_name = kcu.constraint_name
            JOIN information_schema.constraint_column_usage AS ccu ON ccu.constraint_name = tc.constraint_name
            WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_name = 'guard_books' AND kcu.column_name = 'tenant_id'
        ");

        expect($foreignKeys)->toHaveCount(1)
            ->and($foreignKeys[0]->foreign_table_name)->toBe('tenant_keys');
    });

    it('has foreign key constraint to objects (nullable)', function (): void {
        $foreignKeys = DB::select("
            SELECT tc.constraint_name, kcu.column_name, ccu.table_name AS foreign_table_name
            FROM information_schema.table_constraints AS tc
            JOIN information_schema.key_column_usage AS kcu ON tc.constraint_name = kcu.constraint_name
            JOIN information_schema.constraint_column_usage AS ccu ON ccu.constraint_name = tc.constraint_name
            WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_name = 'guard_books' AND kcu.column_name = 'object_id'
        ");

        expect($foreignKeys)->toHaveCount(1)
            ->and($foreignKeys[0]->foreign_table_name)->toBe('objects');
    });

    it('has foreign key constraint to object_areas (nullable)', function (): void {
        $foreignKeys = DB::select("
            SELECT tc.constraint_name, kcu.column_name, ccu.table_name AS foreign_table_name
            FROM information_schema.table_constraints AS tc
            JOIN information_schema.key_column_usage AS kcu ON tc.constraint_name = kcu.constraint_name
            JOIN information_schema.constraint_column_usage AS ccu ON ccu.constraint_name = tc.constraint_name
            WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_name = 'guard_books' AND kcu.column_name = 'object_area_id'
        ");

        expect($foreignKeys)->toHaveCount(1)
            ->and($foreignKeys[0]->foreign_table_name)->toBe('object_areas');
    });

    it('has index on tenant_id and object_id', function (): void {
        $indexes = DB::select("
            SELECT indexname FROM pg_indexes
            WHERE tablename = 'guard_books' AND indexdef LIKE '%tenant_id%' AND indexdef LIKE '%object_id%'
        ");

        expect($indexes)->not->toBeEmpty();
    });

    it('has index on tenant_id and object_area_id', function (): void {
        $indexes = DB::select("
            SELECT indexname FROM pg_indexes
            WHERE tablename = 'guard_books' AND indexdef LIKE '%tenant_id%' AND indexdef LIKE '%object_area_id%'
        ");

        expect($indexes)->not->toBeEmpty();
    });

    describe('XOR constraint enforcement at database level', function (): void {
        beforeEach(function (): void {
            // Set up tenant and object for constraint tests
            \App\Models\TenantKey::setKekPath(getTestKekPath());
            \App\Models\TenantKey::generateKek();
            $keys = \App\Models\TenantKey::generateEnvelopeKeys();
            $this->tenant = \App\Models\TenantKey::create($keys);

            $customer = \App\Models\Customer::factory()->forTenant($this->tenant->id)->create();
            $this->object = \App\Models\SecPalObject::factory()
                ->forTenant($this->tenant->id)
                ->forCustomer($customer)
                ->create();
            $this->objectArea = \App\Models\ObjectArea::factory()
                ->forTenant($this->tenant->id)
                ->forObject($this->object)
                ->create();
        });

        afterEach(function (): void {
            cleanupTestKekFile();
            \App\Models\TenantKey::setKekPath(null);
        });

        it('allows insert with only object_id', function (): void {
            $id = (string) \Illuminate\Support\Str::uuid();
            $result = DB::table('guard_books')->insert([
                'id' => $id,
                'tenant_id' => $this->tenant->id,
                'object_id' => $this->object->id,
                'object_area_id' => null,
                'title' => 'Test Guard Book',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            expect($result)->toBeTrue();
            expect(DB::table('guard_books')->where('id', $id)->exists())->toBeTrue();
        });

        it('allows insert with only object_area_id', function (): void {
            $id = (string) \Illuminate\Support\Str::uuid();
            $result = DB::table('guard_books')->insert([
                'id' => $id,
                'tenant_id' => $this->tenant->id,
                'object_id' => null,
                'object_area_id' => $this->objectArea->id,
                'title' => 'Area Guard Book',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            expect($result)->toBeTrue();
            expect(DB::table('guard_books')->where('id', $id)->exists())->toBeTrue();
        });

        it('rejects insert with both object_id AND object_area_id', function (): void {
            $id = (string) \Illuminate\Support\Str::uuid();

            expect(fn () => DB::table('guard_books')->insert([
                'id' => $id,
                'tenant_id' => $this->tenant->id,
                'object_id' => $this->object->id,
                'object_area_id' => $this->objectArea->id,
                'title' => 'Invalid Guard Book',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]))->toThrow(\Illuminate\Database\QueryException::class);
        });

        it('rejects insert with neither object_id NOR object_area_id', function (): void {
            $id = (string) \Illuminate\Support\Str::uuid();

            expect(fn () => DB::table('guard_books')->insert([
                'id' => $id,
                'tenant_id' => $this->tenant->id,
                'object_id' => null,
                'object_area_id' => null,
                'title' => 'Invalid Guard Book',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]))->toThrow(\Illuminate\Database\QueryException::class);
        });
    });
});

describe('guard_book_reports migration', function (): void {
    it('creates guard_book_reports table with correct columns', function (): void {
        expect(Schema::hasTable('guard_book_reports'))->toBeTrue();

        expect(Schema::hasColumn('guard_book_reports', 'id'))->toBeTrue()
            ->and(Schema::hasColumn('guard_book_reports', 'tenant_id'))->toBeTrue()
            ->and(Schema::hasColumn('guard_book_reports', 'guard_book_id'))->toBeTrue()
            ->and(Schema::hasColumn('guard_book_reports', 'report_number'))->toBeTrue()
            ->and(Schema::hasColumn('guard_book_reports', 'title'))->toBeTrue()
            ->and(Schema::hasColumn('guard_book_reports', 'period_start'))->toBeTrue()
            ->and(Schema::hasColumn('guard_book_reports', 'period_end'))->toBeTrue()
            ->and(Schema::hasColumn('guard_book_reports', 'filter_criteria'))->toBeTrue()
            ->and(Schema::hasColumn('guard_book_reports', 'total_events'))->toBeTrue()
            ->and(Schema::hasColumn('guard_book_reports', 'report_data'))->toBeTrue()
            ->and(Schema::hasColumn('guard_book_reports', 'generated_by_user_id'))->toBeTrue()
            ->and(Schema::hasColumn('guard_book_reports', 'generated_at'))->toBeTrue()
            ->and(Schema::hasColumn('guard_book_reports', 'status'))->toBeTrue()
            ->and(Schema::hasColumn('guard_book_reports', 'created_at'))->toBeTrue()
            ->and(Schema::hasColumn('guard_book_reports', 'updated_at'))->toBeTrue()
            ->and(Schema::hasColumn('guard_book_reports', 'deleted_at'))->toBeTrue();
    });

    it('has foreign key constraint to guard_books', function (): void {
        $foreignKeys = DB::select("
            SELECT tc.constraint_name, kcu.column_name, ccu.table_name AS foreign_table_name
            FROM information_schema.table_constraints AS tc
            JOIN information_schema.key_column_usage AS kcu ON tc.constraint_name = kcu.constraint_name
            JOIN information_schema.constraint_column_usage AS ccu ON ccu.constraint_name = tc.constraint_name
            WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_name = 'guard_book_reports' AND kcu.column_name = 'guard_book_id'
        ");

        expect($foreignKeys)->toHaveCount(1)
            ->and($foreignKeys[0]->foreign_table_name)->toBe('guard_books');
    });

    it('has foreign key constraint to users for generated_by', function (): void {
        $foreignKeys = DB::select("
            SELECT tc.constraint_name, kcu.column_name, ccu.table_name AS foreign_table_name
            FROM information_schema.table_constraints AS tc
            JOIN information_schema.key_column_usage AS kcu ON tc.constraint_name = kcu.constraint_name
            JOIN information_schema.constraint_column_usage AS ccu ON ccu.constraint_name = tc.constraint_name
            WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_name = 'guard_book_reports' AND kcu.column_name = 'generated_by_user_id'
        ");

        expect($foreignKeys)->toHaveCount(1)
            ->and($foreignKeys[0]->foreign_table_name)->toBe('users');
    });

    it('has unique constraint on report_number', function (): void {
        $uniqueConstraints = DB::select("
            SELECT indexname FROM pg_indexes
            WHERE tablename = 'guard_book_reports' AND indexdef LIKE '%UNIQUE%' AND indexdef LIKE '%report_number%'
        ");

        expect($uniqueConstraints)->not->toBeEmpty();
    });

    it('has index on guard_book_id', function (): void {
        $indexes = DB::select("
            SELECT indexname FROM pg_indexes
            WHERE tablename = 'guard_book_reports' AND indexdef LIKE '%guard_book_id%'
        ");

        expect($indexes)->not->toBeEmpty();
    });

    it('has index on period range', function (): void {
        $indexes = DB::select("
            SELECT indexname FROM pg_indexes
            WHERE tablename = 'guard_book_reports' AND indexdef LIKE '%period_start%' AND indexdef LIKE '%period_end%'
        ");

        expect($indexes)->not->toBeEmpty();
    });
});

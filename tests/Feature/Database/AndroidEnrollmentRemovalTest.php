<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('fresh schema contains no obsolete Android enrollment migration or configuration surface', function (): void {
    $obsoleteMigrations = [
        '2026_04_06_100007_create_android_enrollment_sessions_table',
        '2026_06_29_160000_preserve_android_enrollment_history_on_user_delete',
    ];
    $environmentExample = file_get_contents(base_path('.env.example'));

    expect(DB::table('migrations')->whereIn('migration', $obsoleteMigrations)->exists())->toBeFalse()
        ->and(collect($obsoleteMigrations)
            ->contains(static fn (string $migration): bool => is_file(database_path("migrations/{$migration}.php"))))
        ->toBeFalse()
        ->and(Schema::hasTable('android_enrollment_sessions'))->toBeFalse()
        ->and($environmentExample)->toBeString()
        ->not->toContain('BOOTSTRAP_MANAGED_ANDROID_ENROLLMENT_ENABLED');
});

test('the removal migration drops existing enrollment data and configured permission grants', function (): void {
    config([
        'permission.table_names.permissions' => 'tenant_permissions',
        'permission.table_names.model_has_permissions' => 'tenant_model_permissions',
        'permission.table_names.role_has_permissions' => 'tenant_role_permissions',
    ]);

    Schema::create('android_enrollment_sessions', function (Blueprint $table): void {
        $table->uuid('id')->primary();
    });
    Schema::create('tenant_permissions', function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->string('name');
    });
    Schema::create('tenant_model_permissions', function (Blueprint $table): void {
        $table->unsignedBigInteger('permission_id');
    });
    Schema::create('tenant_role_permissions', function (Blueprint $table): void {
        $table->unsignedBigInteger('permission_id');
    });

    $permissionId = DB::table('tenant_permissions')->insertGetId(['name' => 'android_enrollment.read']);
    DB::table('tenant_model_permissions')->insert(['permission_id' => $permissionId]);
    DB::table('tenant_role_permissions')->insert(['permission_id' => $permissionId]);

    $migration = require database_path('migrations/2026_07_23_000000_drop_android_enrollment_sessions_table.php');
    $migration->up();

    expect(Schema::hasTable('android_enrollment_sessions'))->toBeFalse()
        ->and(DB::table('tenant_permissions')->where('id', $permissionId)->exists())->toBeFalse()
        ->and(DB::table('tenant_model_permissions')->where('permission_id', $permissionId)->exists())->toBeFalse()
        ->and(DB::table('tenant_role_permissions')->where('permission_id', $permissionId)->exists())->toBeFalse();
});

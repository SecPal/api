<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('Android enrollment removal migration drops enrollment data and stale grants from configured permission tables', function (): void {
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
        $table->string('guard_name');
        $table->timestamps();
    });
    Schema::create('tenant_model_permissions', function (Blueprint $table): void {
        $table->unsignedBigInteger('permission_id');
    });
    Schema::create('tenant_role_permissions', function (Blueprint $table): void {
        $table->unsignedBigInteger('permission_id');
    });

    $permissionId = DB::table('tenant_permissions')->insertGetId([
        'name' => 'android_enrollment.read',
        'guard_name' => 'sanctum',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('tenant_model_permissions')->insert([
        'permission_id' => $permissionId,
    ]);
    DB::table('tenant_role_permissions')->insert([
        'permission_id' => $permissionId,
    ]);

    $migration = require database_path('migrations/2026_07_23_000000_drop_android_enrollment_sessions_table.php');
    $migration->up();

    expect(Schema::hasTable('android_enrollment_sessions'))->toBeFalse()
        ->and(DB::table('tenant_permissions')->where('id', $permissionId)->exists())->toBeFalse()
        ->and(DB::table('tenant_model_permissions')->where('permission_id', $permissionId)->exists())->toBeFalse()
        ->and(DB::table('tenant_role_permissions')->where('permission_id', $permissionId)->exists())->toBeFalse();
});

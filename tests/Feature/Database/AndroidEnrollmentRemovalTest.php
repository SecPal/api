<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\Activity;
use App\Models\TenantKey;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

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
        'permission.column_names.permission_pivot_key' => 'tenant_permission_id',
    ]);

    Schema::create('android_enrollment_sessions', function (Blueprint $table): void {
        $table->uuid('id')->primary();
    });
    Schema::create('tenant_permissions', function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->string('name');
    });
    Schema::create('tenant_model_permissions', function (Blueprint $table): void {
        $table->unsignedBigInteger('tenant_permission_id');
    });
    Schema::create('tenant_role_permissions', function (Blueprint $table): void {
        $table->unsignedBigInteger('tenant_permission_id');
    });

    $permissionId = DB::table('tenant_permissions')->insertGetId(['name' => 'android_enrollment.read']);
    DB::table('tenant_model_permissions')->insert(['tenant_permission_id' => $permissionId]);
    DB::table('tenant_role_permissions')->insert(['tenant_permission_id' => $permissionId]);

    $migration = require database_path('migrations/2026_07_23_000000_drop_android_enrollment_sessions_table.php');
    $migration->up();

    expect(Schema::hasTable('android_enrollment_sessions'))->toBeFalse()
        ->and(DB::table('tenant_permissions')->where('id', $permissionId)->exists())->toBeFalse()
        ->and(DB::table('tenant_model_permissions')->where('tenant_permission_id', $permissionId)->exists())->toBeFalse()
        ->and(DB::table('tenant_role_permissions')->where('tenant_permission_id', $permissionId)->exists())->toBeFalse();
});

test('historical enrollment audit entries remain readable without changing forensic data', function (): void {
    $tenant = TenantKey::factory()->create();
    $subjectId = (string) Str::uuid();
    $activity = Activity::factory()->create([
        'tenant_id' => $tenant->id,
        'subject_type' => 'App\\Models\\AndroidEnrollmentSession',
        'subject_id' => $subjectId,
    ])->refresh();
    $eventHash = $activity->event_hash;

    $migration = require database_path('migrations/2026_07_23_000000_drop_android_enrollment_sessions_table.php');
    $migration->up();

    $reloadedActivity = Activity::query()->with('subject')->findOrFail($activity->id);

    expect($reloadedActivity->relationLoaded('subject'))->toBeTrue()
        ->and($reloadedActivity->subject)->toBeNull()
        ->and($reloadedActivity->subject_type)->toBe('App\\Models\\AndroidEnrollmentSession')
        ->and($reloadedActivity->subject_id)->toBe($subjectId)
        ->and($reloadedActivity->event_hash)->toBe($eventHash)
        ->and($reloadedActivity->verifyChain())->toBeTrue();
});

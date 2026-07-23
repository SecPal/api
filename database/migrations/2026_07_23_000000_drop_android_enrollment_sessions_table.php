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
        Schema::dropIfExists('android_enrollment_sessions');

        $permissionsTable = $this->permissionTableName('permissions');
        $modelPermissionsTable = $this->permissionTableName('model_has_permissions');
        $rolePermissionsTable = $this->permissionTableName('role_has_permissions');
        $permissionPivotKey = $this->permissionPivotKey();

        $permissionIds = DB::table($permissionsTable)
            ->whereIn('name', ['android_enrollment.read', 'android_enrollment.write'])
            ->pluck('id');

        if ($permissionIds->isEmpty()) {
            return;
        }

        DB::table($modelPermissionsTable)
            ->whereIn($permissionPivotKey, $permissionIds)
            ->delete();

        DB::table($rolePermissionsTable)
            ->whereIn($permissionPivotKey, $permissionIds)
            ->delete();

        DB::table($permissionsTable)
            ->whereIn('id', $permissionIds)
            ->delete();
    }

    public function down(): void
    {
        throw new RuntimeException('The Android enrollment removal is intentionally irreversible.');
    }

    private function permissionTableName(string $key): string
    {
        $tableName = config("permission.table_names.{$key}");

        if (! is_string($tableName) || $tableName === '') {
            throw new RuntimeException("Permission table name [{$key}] is not configured.");
        }

        return $tableName;
    }

    private function permissionPivotKey(): string
    {
        $pivotKey = config('permission.column_names.permission_pivot_key', 'permission_id');

        return is_string($pivotKey) && $pivotKey !== '' ? $pivotKey : 'permission_id';
    }
};

<?php

// SPDX-FileCopyrightText: 2025 SecPal
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\RoleAssignmentLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

test('role assignment log can be created with all required fields', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create();
    $role = Role::create(['name' => 'manager']);

    $log = RoleAssignmentLog::create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'action' => 'assigned',
        'valid_from' => now(),
        'valid_until' => now()->addDays(7),
        'assigned_by' => $admin->id,
        'reason' => 'Vacation coverage',
    ]);

    expect($log)->toBeInstanceOf(RoleAssignmentLog::class)
        ->and($log->user_id)->toBe($user->id)
        ->and($log->role_id)->toBe($role->id)
        ->and($log->action)->toBe('assigned')
        ->and($log->assigned_by)->toBe($admin->id)
        ->and($log->reason)->toBe('Vacation coverage');
});

test('role assignment log is read-only (cannot be updated)', function () {
    $user = User::factory()->create();
    $role = Role::create(['name' => 'manager']);

    $log = RoleAssignmentLog::create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'action' => 'assigned',
        'valid_from' => now(),
        'valid_until' => now()->addDays(7),
    ]);

    $log->action = 'revoked';
    $result = $log->save();

    expect($result)->toBeFalse();
});

test('role assignment log cannot be deleted', function () {
    $user = User::factory()->create();
    $role = Role::create(['name' => 'manager']);

    $log = RoleAssignmentLog::create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'action' => 'assigned',
        'valid_from' => now(),
        'valid_until' => now()->addDays(7),
    ]);

    $result = $log->delete();

    expect($result)->toBeFalse()
        ->and(RoleAssignmentLog::find($log->id))->not->toBeNull();
});

test('role assignment log has relationship to user', function () {
    $user = User::factory()->create();
    $role = Role::create(['name' => 'manager']);

    $log = RoleAssignmentLog::create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'action' => 'assigned',
        'valid_from' => now(),
        'valid_until' => now()->addDays(7),
    ]);

    expect($log->user)->toBeInstanceOf(User::class)
        ->and($log->user->id)->toBe($user->id);
});

test('role assignment log has relationship to role', function () {
    $user = User::factory()->create();
    $role = Role::create(['name' => 'manager']);

    $log = RoleAssignmentLog::create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'action' => 'assigned',
        'valid_from' => now(),
        'valid_until' => now()->addDays(7),
    ]);

    expect($log->role)->toBeInstanceOf(Role::class)
        ->and($log->role->id)->toBe($role->id);
});

test('role assignment log has relationship to assigner', function () {
    $user = User::factory()->create();
    $admin = User::factory()->create();
    $role = Role::create(['name' => 'manager']);

    $log = RoleAssignmentLog::create([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'action' => 'assigned',
        'valid_from' => now(),
        'valid_until' => now()->addDays(7),
        'assigned_by' => $admin->id,
    ]);

    expect($log->assignedBy)->toBeInstanceOf(User::class)
        ->and($log->assignedBy->id)->toBe($admin->id);
});


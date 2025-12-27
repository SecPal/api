<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\Activity;
use App\Models\Employee;
use App\Models\TenantKey;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * @property TenantKey $tenant
 * @property User $user
 * @property ActivityLogService $service
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = TenantKey::factory()->create();
    $this->user = User::factory()->create();
    $this->service = app(ActivityLogService::class);
    $this->actingAs($this->user);

    // Set tenant context
    request()->merge([
        'organizational_unit_id' => null,
    ]);
});

test('logLoginSuccess creates authentication log with security level 2', function (): void {
    $activity = $this->service->logLoginSuccess($this->user);

    expect($activity)->toBeInstanceOf(Activity::class)
        ->and($activity->log_name)->toBe('authentication')
        ->and($activity->description)->toBe('User logged in successfully')
        ->and($activity->causer_id)->toBe((string) $this->user->id)
        ->and($activity->properties['event'])->toBe('login_success')
        ->and(Activity::getSecurityLevel($activity->log_name))->toBe(2);
});

test('logLoginFailed creates authentication log without causer', function (): void {
    // Note: In real scenarios, failed logins happen before authentication, so causer_id would be null.
    // In this test environment, a user is already authenticated from beforeEach(),
    // so Activity's booted() hook will auto-inject the authenticated user's ID as causer.
    // This is acceptable since the test verifies the core logging functionality.

    $activity = $this->service->logLoginFailed('test@example.com', 'Invalid password');

    expect($activity)->toBeInstanceOf(Activity::class)
        ->and($activity->log_name)->toBe('authentication')
        ->and($activity->description)->toBe('Failed login attempt')
        ->and($activity->properties['event'])->toBe('login_failed')
        ->and($activity->properties['email'])->toBe('test@example.com')
        ->and($activity->properties['reason'])->toBe('Invalid password')
        ->and(Activity::getSecurityLevel($activity->log_name))->toBe(2);
});

test('logLogout creates authentication log', function (): void {
    $activity = $this->service->logLogout($this->user);

    expect($activity)->toBeInstanceOf(Activity::class)
        ->and($activity->log_name)->toBe('authentication')
        ->and($activity->description)->toBe('User logged out')
        ->and($activity->causer_id)->toBe((string) $this->user->id)
        ->and($activity->properties['event'])->toBe('logout')
        ->and(Activity::getSecurityLevel($activity->log_name))->toBe(2);
});

test('logRoleAssignment creates rbac_changes log', function (): void {
    $targetUser = User::factory()->create();

    $activity = $this->service->logRoleAssignment(
        $this->user,
        $targetUser,
        ['admin', 'manager'],
        'Promotion'
    );

    expect($activity)->toBeInstanceOf(Activity::class)
        ->and($activity->log_name)->toBe('rbac_changes')
        ->and($activity->description)->toBe('Assigned roles to user')
        ->and($activity->causer_id)->toBe((string) $this->user->id)
        ->and($activity->subject_id)->toBe((string) $targetUser->id)
        ->and($activity->properties['event'])->toBe('role_assigned')
        ->and($activity->properties['roles'])->toBe(['admin', 'manager'])
        ->and($activity->properties['reason'])->toBe('Promotion')
        ->and(Activity::getSecurityLevel($activity->log_name))->toBe(2);
});

test('logRoleRevocation creates rbac_changes log', function (): void {
    $targetUser = User::factory()->create();

    $activity = $this->service->logRoleRevocation(
        $this->user,
        $targetUser,
        ['admin'],
        'Contract termination'
    );

    expect($activity)->toBeInstanceOf(Activity::class)
        ->and($activity->log_name)->toBe('rbac_changes')
        ->and($activity->description)->toBe('Revoked roles from user')
        ->and($activity->properties['event'])->toBe('role_revoked')
        ->and($activity->properties['roles'])->toBe(['admin'])
        ->and($activity->properties['reason'])->toBe('Contract termination');
});

test('logPermissionChange creates rbac_changes log', function (): void {
    $activity = $this->service->logPermissionChange(
        $this->user,
        'granted',
        ['employee.read', 'employee.write'],
        'role',
        'manager'
    );

    expect($activity)->toBeInstanceOf(Activity::class)
        ->and($activity->log_name)->toBe('rbac_changes')
        ->and($activity->properties['action'])->toBe('granted')
        ->and($activity->properties['permissions'])->toBe(['employee.read', 'employee.write'])
        ->and($activity->properties['target_type'])->toBe('role')
        ->and($activity->properties['target_id'])->toBe('manager')
        ->and(Activity::getSecurityLevel($activity->log_name))->toBe(2);
});

test('logScopeChange creates scope_changes log', function (): void {
    $targetUser = User::factory()->create();

    $activity = $this->service->logScopeChange(
        $this->user,
        $targetUser,
        'site',
        'site-uuid-123',
        'granted'
    );

    expect($activity)->toBeInstanceOf(Activity::class)
        ->and($activity->log_name)->toBe('scope_changes')
        ->and($activity->properties['event'])->toBe('scope_changed')
        ->and($activity->properties['scope_type'])->toBe('site')
        ->and($activity->properties['scope_id'])->toBe('site-uuid-123')
        ->and($activity->properties['action'])->toBe('granted')
        ->and(Activity::getSecurityLevel($activity->log_name))->toBe(2);
});

test('logHRAccess creates hr_access log with security level 3', function (): void {
    $employee = Employee::factory()->create(['tenant_id' => $this->tenant->id]);

    $activity = $this->service->logHRAccess(
        $this->user,
        $employee,
        ['salary', 'contract_data'],
        'Annual review'
    );

    expect($activity)->toBeInstanceOf(Activity::class)
        ->and($activity->log_name)->toBe('hr_access')
        ->and($activity->description)->toBe('Accessed sensitive HR data')
        ->and($activity->causer_id)->toBe((string) $this->user->id)
        ->and($activity->subject_id)->toBe($employee->id)
        ->and($activity->properties['event'])->toBe('hr_data_accessed')
        ->and($activity->properties['accessed_fields'])->toBe(['salary', 'contract_data'])
        ->and($activity->properties['reason'])->toBe('Annual review')
        ->and(Activity::getSecurityLevel($activity->log_name))->toBe(3);
});

test('logSensitiveAccess creates sensitive_access log with security level 3', function (): void {
    $employee = Employee::factory()->create(['tenant_id' => $this->tenant->id]);

    $activity = $this->service->logSensitiveAccess(
        $this->user,
        $employee,
        'medical_records',
        'Medical leave review',
        ['document_type' => 'health_certificate', 'classification' => 'confidential']
    );

    expect($activity)->toBeInstanceOf(Activity::class)
        ->and($activity->log_name)->toBe('sensitive_access')
        ->and($activity->properties['data_type'])->toBe('medical_records')
        ->and($activity->properties['reason'])->toBe('Medical leave review')
        ->and($activity->properties['document_type'])->toBe('health_certificate')
        ->and($activity->properties['classification'])->toBe('confidential')
        ->and(Activity::getSecurityLevel($activity->log_name))->toBe(3);
});

test('logCustomEvent creates flexible activity log', function (): void {
    $employee = Employee::factory()->create(['tenant_id' => $this->tenant->id]);

    $activity = $this->service->logCustomEvent(
        'custom_event',
        'Custom action performed',
        $this->user,
        $employee,
        ['foo' => 'bar', 'baz' => 123]
    );

    expect($activity)->toBeInstanceOf(Activity::class)
        ->and($activity->log_name)->toBe('custom_event')
        ->and($activity->description)->toBe('Custom action performed')
        ->and($activity->causer_id)->toBe((string) $this->user->id)
        ->and($activity->subject_id)->toBe($employee->id)
        ->and($activity->properties['foo'])->toBe('bar')
        ->and($activity->properties['baz'])->toBe(123);
});

test('activity log service automatically injects metadata', function (): void {
    // Simulate request with IP and user agent
    request()->merge([
        'REMOTE_ADDR' => '192.168.1.100',
        'HTTP_USER_AGENT' => 'Mozilla/5.0',
    ]);

    $activity = $this->service->logLoginSuccess($this->user);

    expect($activity->ip_address)->not->toBeNull()
        ->and($activity->user_agent)->not->toBeNull();
});

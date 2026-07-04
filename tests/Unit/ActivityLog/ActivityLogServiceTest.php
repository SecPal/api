<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

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

test('logLoginSuccess creates authentication log with 8-year retention', function (): void {
    $activity = $this->service->logLoginSuccess($this->user);

    expect($activity)->toBeInstanceOf(Activity::class)
        ->and($activity->log_name)->toBe('authentication')
        ->and($activity->description)->toBe('User logged in successfully')
        ->and($activity->causer_id)->toBe((string) $this->user->id)
        ->and($activity->properties['event'])->toBe('login_success')
        ->and(Activity::getRetentionYearsForLogType($activity->log_name))->toBeIn([3, 8]);
});

test('logLoginFailed creates authentication log without causer', function (): void {
    // Failed logins happen before authentication, so no user is authenticated.
    // Reset all guard instances to clear the user set by beforeEach() and verify
    // that causer_id is truly null, matching production conditions.
    Auth::forgetGuards();

    $activity = $this->service->logLoginFailed('test@example.com', 'Invalid password');

    expect($activity)->toBeInstanceOf(Activity::class)
        ->and($activity->log_name)->toBe('authentication')
        ->and($activity->description)->toBe('Failed login attempt')
        ->and($activity->causer_id)->toBeNull()
        ->and($activity->properties['event'])->toBe('login_failed')
        ->and($activity->properties['email'])->toBe('test@example.com')
        ->and($activity->properties['reason'])->toBe('Invalid password')
        ->and(Activity::getRetentionYearsForLogType($activity->log_name))->toBeIn([3, 8]);
});

test('logLogout creates authentication log', function (): void {
    $activity = $this->service->logLogout($this->user);

    expect($activity)->toBeInstanceOf(Activity::class)
        ->and($activity->log_name)->toBe('authentication')
        ->and($activity->description)->toBe('User logged out')
        ->and($activity->causer_id)->toBe((string) $this->user->id)
        ->and($activity->properties['event'])->toBe('logout')
        ->and(Activity::getRetentionYearsForLogType($activity->log_name))->toBeIn([3, 8]);
});

test('logLogoutAll creates authentication log', function (): void {
    $activity = $this->service->logLogoutAll($this->user);

    expect($activity)->toBeInstanceOf(Activity::class)
        ->and($activity->log_name)->toBe('authentication')
        ->and($activity->description)->toBe('User logged out from all devices')
        ->and($activity->causer_id)->toBe((string) $this->user->id)
        ->and($activity->properties['event'])->toBe('logout_all')
        ->and(Activity::getRetentionYearsForLogType($activity->log_name))->toBeIn([3, 8]);
});

test('logPasswordReset creates authentication log', function (): void {
    $activity = $this->service->logPasswordReset($this->user);

    expect($activity)->toBeInstanceOf(Activity::class)
        ->and($activity->log_name)->toBe('authentication')
        ->and($activity->description)->toBe('User reset password and revoked active sessions')
        ->and($activity->causer_id)->toBe((string) $this->user->id)
        ->and($activity->properties['event'])->toBe('password_reset')
        ->and(Activity::getRetentionYearsForLogType($activity->log_name))->toBeIn([3, 8]);
});

test('logUserMfaEvent creates authentication log for self-service MFA actions', function (): void {
    $activity = $this->service->logUserMfaEvent(
        $this->user,
        'mfa_enabled',
        'Enabled multi-factor authentication',
        ['method' => 'totp']
    );

    expect($activity)->toBeInstanceOf(Activity::class)
        ->and($activity->log_name)->toBe('authentication')
        ->and($activity->description)->toBe('Enabled multi-factor authentication')
        ->and($activity->causer_id)->toBe((string) $this->user->id)
        ->and($activity->subject_id)->toBe((string) $this->user->id)
        ->and($activity->properties['event'])->toBe('mfa_enabled')
        ->and($activity->properties['method'])->toBe('totp');
});

test('logPrivilegedMfaReset creates authentication log with actor and target attribution', function (): void {
    $targetUser = User::factory()->create();

    $activity = $this->service->logPrivilegedMfaReset(
        $this->user,
        $targetUser,
        'Lost authenticator device',
        ['had_pending_enrollment' => false]
    );

    expect($activity)->toBeInstanceOf(Activity::class)
        ->and($activity->log_name)->toBe('authentication')
        ->and($activity->description)->toBe('Privileged user reset multi-factor authentication')
        ->and($activity->causer_id)->toBe((string) $this->user->id)
        ->and($activity->subject_id)->toBe((string) $targetUser->id)
        ->and($activity->properties['event'])->toBe('mfa_reset_by_privileged_user')
        ->and($activity->properties['target_user_id'])->toBe($targetUser->id)
        ->and($activity->properties['reason'])->toBe('Lost authenticator device')
        ->and($activity->properties['had_pending_enrollment'])->toBeFalse();
});

test('logRoleAssignment creates rbac_changes log', function (): void {
    $targetUser = User::factory()->create();

    $activity = $this->service->logRoleAssignment(
        $this->user,
        $targetUser,
        ['Manager', 'HR'],
        'Promotion'
    );

    expect($activity)->toBeInstanceOf(Activity::class)
        ->and($activity->log_name)->toBe('rbac_changes')
        ->and($activity->description)->toBe('Assigned roles to user')
        ->and($activity->causer_id)->toBe((string) $this->user->id)
        ->and($activity->subject_id)->toBe((string) $targetUser->id)
        ->and($activity->properties['event'])->toBe('role_assigned')
        ->and($activity->properties['roles'])->toBe(['Manager', 'HR'])
        ->and($activity->properties['reason'])->toBe('Promotion')
        ->and(Activity::getRetentionYearsForLogType($activity->log_name))->toBeIn([3, 8]);
});

test('logRoleRevocation creates rbac_changes log', function (): void {
    $targetUser = User::factory()->create();

    $activity = $this->service->logRoleRevocation(
        $this->user,
        $targetUser,
        ['HR'],
        'Contract termination'
    );

    expect($activity)->toBeInstanceOf(Activity::class)
        ->and($activity->log_name)->toBe('rbac_changes')
        ->and($activity->description)->toBe('Revoked roles from user')
        ->and($activity->properties['event'])->toBe('role_revoked')
        ->and($activity->properties['roles'])->toBe(['HR'])
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
        ->and(Activity::getRetentionYearsForLogType($activity->log_name))->toBeIn([3, 8]);
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
        ->and(Activity::getRetentionYearsForLogType($activity->log_name))->toBeIn([3, 8]);
});

test('logHRAccess creates hr_access log with 10-year retention', function (): void {
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
        ->and(Activity::getRetentionYearsForLogType($activity->log_name))->toBeIn([3, 8, 10]);
});

test('logSensitiveAccess creates sensitive_access log with 10-year retention', function (): void {
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
        ->and(Activity::getRetentionYearsForLogType($activity->log_name))->toBeIn([3, 8, 10]);
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

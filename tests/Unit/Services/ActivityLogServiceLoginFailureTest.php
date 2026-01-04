<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Employee;
use App\Models\OrganizationalUnit;
use App\Models\TenantKey;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Test ActivityLogService login failure logging.
 *
 * Verifies that login failures are logged correctly with proper
 * user_exists, employee_exists, and has_organizational_unit flags.
 */
class ActivityLogServiceLoginFailureTest extends TestCase
{
    use RefreshDatabase;

    private ActivityLogService $service;

    private TenantKey $tenant;

    private OrganizationalUnit $orgUnit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ActivityLogService::class);
        $this->tenant = TenantKey::factory()->create();
        $this->orgUnit = OrganizationalUnit::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
    }

    /**
     * Test login failure with completely unknown email.
     *
     * Scenario: Email has no user account and no employee record.
     * Expected: user_exists=false, employee_exists=false, has_ou=false
     */
    public function test_logs_unknown_email_without_user_or_employee(): void
    {
        $unknownEmail = 'completely-unknown@example.com';

        $activity = $this->service->logLoginFailed($unknownEmail, 'invalid_credentials');

        expect($activity)->not->toBeNull();
        expect($activity->properties['event'])->toBe('login_failed');
        expect($activity->properties['email'])->toBe($unknownEmail);
        expect($activity->properties['user_exists'])->toBeFalse();
        expect($activity->properties['employee_exists'])->toBeFalse();
        expect($activity->properties['has_organizational_unit'])->toBeFalse();
        expect($activity->organizational_unit_id)->toBeNull();
    }

    /**
     * Test login failure with user + employee.
     *
     * Scenario: Email has both user account and employee record.
     * Expected: user_exists=true, employee_exists=true, has_ou=true
     */
    public function test_logs_user_with_employee(): void
    {
        $user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => $this->orgUnit->id,
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        $activity = $this->service->logLoginFailed($user->email, 'invalid_credentials');

        expect($activity)->not->toBeNull();
        expect($activity->properties['user_exists'])->toBeTrue();
        expect($activity->properties['employee_exists'])->toBeTrue();
        expect($activity->properties['has_organizational_unit'])->toBeTrue();
        expect($activity->organizational_unit_id)->toBe($this->orgUnit->id);
    }

    /**
     * Test login failure with employee but no user.
     *
     * Scenario: Email has employee record but no user account.
     * Expected: user_exists=false, employee_exists=true, has_ou=true
     *
     * This was the case that caused the original inconsistency:
     * user_exists=false but has_organizational_unit=true without
     * the employee_exists flag to explain why.
     */
    public function test_logs_employee_without_user_account(): void
    {
        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => $this->orgUnit->id,
            'user_id' => null, // No user account
            'email' => 'employee-no-user@example.com',
        ]);

        $activity = $this->service->logLoginFailed($employee->email, 'invalid_credentials');

        expect($activity)->not->toBeNull();
        expect($activity->properties['user_exists'])->toBeFalse();
        expect($activity->properties['employee_exists'])->toBeTrue();
        expect($activity->properties['has_organizational_unit'])->toBeTrue();
        expect($activity->organizational_unit_id)->toBe($this->orgUnit->id);

        // This combination is now logically consistent:
        // No user account exists, but employee record provides the OU
    }

    /**
     * Test that all login failures are logged.
     *
     * Verifies that even completely unknown emails are logged
     * for security auditing purposes.
     */
    public function test_all_login_failures_are_logged_including_unknown_emails(): void
    {
        $emails = [
            'unknown-1@example.com',
            'unknown-2@example.com',
            'random-hacker@evil.com',
        ];

        foreach ($emails as $email) {
            $activity = $this->service->logLoginFailed($email, 'invalid_credentials');
            expect($activity)->not->toBeNull('All login attempts must be logged');
        }
    }
}

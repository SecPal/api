<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

use App\Mail\EmployeeComplianceAlertMail;
use App\Models\Employee;
use App\Models\OrganizationalUnit;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/**
 * @property TenantKey $tenant
 * @property OrganizationalUnit $orgUnit
 */
beforeEach(function (): void {
    Mail::fake();

    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    $this->orgUnit = OrganizationalUnit::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Test Department',
        'code' => 'TEST',
        'type' => 'department',
        'is_active' => true,
    ]);
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('employee compliance alert command sends warning notifications 30 days before expiry', function (): void {
    $user = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'email' => 'warning.employee@example.com',
    ]);

    $employee = Employee::factory()->withComplianceCertifications()->create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $user->id,
        'email' => 'warning.employee@example.com',
        'status' => Employee::STATUS_ACTIVE,
        'firearms_license_expiry' => now()->addDays(30)->toDateString(),
        'first_aid_cert_expiry' => now()->addDays(60)->toDateString(),
        'evacuation_cert_expiry' => now()->addDays(60)->toDateString(),
        'additional_certifications' => null,
    ]);

    Artisan::call('employees:send-compliance-alert-notifications');

    Mail::assertQueued(EmployeeComplianceAlertMail::class, function (EmployeeComplianceAlertMail $mail) use ($employee): bool {
        return $mail->employee->is($employee)
            && $mail->severity === 'warning'
            && count($mail->documents) === 1;
    });
});

test('employee compliance alert command sends critical notifications 7 days before expiry', function (): void {
    $user = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'email' => 'critical.employee@example.com',
    ]);

    $employee = Employee::factory()->withComplianceCertifications()->create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $user->id,
        'email' => 'critical.employee@example.com',
        'status' => Employee::STATUS_ACTIVE,
        'firearms_license_expiry' => now()->addDays(7)->toDateString(),
        'first_aid_cert_expiry' => now()->addDays(60)->toDateString(),
        'evacuation_cert_expiry' => now()->addDays(60)->toDateString(),
        'additional_certifications' => null,
    ]);

    Artisan::call('employees:send-compliance-alert-notifications');

    Mail::assertQueued(EmployeeComplianceAlertMail::class, function (EmployeeComplianceAlertMail $mail) use ($employee): bool {
        return $mail->employee->is($employee)
            && $mail->severity === 'critical';
    });
});

test('employee compliance alert command sends expired notifications on first day after expiry', function (): void {
    $user = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'email' => 'expired.employee@example.com',
    ]);

    $employee = Employee::factory()->withComplianceCertifications()->create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $user->id,
        'email' => 'expired.employee@example.com',
        'status' => Employee::STATUS_ACTIVE,
        'firearms_license_expiry' => now()->subDay()->toDateString(),
        'first_aid_cert_expiry' => now()->addDays(60)->toDateString(),
        'evacuation_cert_expiry' => now()->addDays(60)->toDateString(),
        'additional_certifications' => null,
    ]);

    Artisan::call('employees:send-compliance-alert-notifications');

    Mail::assertQueued(EmployeeComplianceAlertMail::class, function (EmployeeComplianceAlertMail $mail) use ($employee): bool {
        return $mail->employee->is($employee)
            && $mail->severity === 'expired';
    });
});

test('employee compliance alert command dry run does not send mails', function (): void {
    $user = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'email' => 'dry-run.employee@example.com',
    ]);

    Employee::factory()->withComplianceCertifications()->create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $user->id,
        'email' => 'dry-run.employee@example.com',
        'status' => Employee::STATUS_ACTIVE,
        'firearms_license_expiry' => now()->addDays(7)->toDateString(),
        'additional_certifications' => null,
    ]);

    Artisan::call('employees:send-compliance-alert-notifications', ['--dry-run' => true]);

    Mail::assertNothingQueued();
});

test('employee compliance alert command skips employees without user accounts or email', function (): void {
    Employee::factory()->withComplianceCertifications()->create([
        'tenant_id' => $this->tenant->id,
        'user_id' => null,
        'email' => null,
        'status' => Employee::STATUS_ACTIVE,
        'firearms_license_expiry' => now()->addDays(7)->toDateString(),
        'additional_certifications' => null,
    ]);

    Artisan::call('employees:send-compliance-alert-notifications');

    Mail::assertNothingQueued();
});

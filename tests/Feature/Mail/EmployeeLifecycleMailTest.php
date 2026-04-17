<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
//
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Mail\AccountDeactivatedMail;
use App\Mail\BwrIdDocumentAutoDeletedMail;
use App\Mail\ContractEndingSoonMail;
use App\Mail\EmployeeComplianceAlertMail;
use App\Mail\OnboardingInvitationMail;
use App\Mail\QualificationExpiringMail;
use App\Mail\WelcomeActiveMail;
use App\Models\Employee;
use App\Models\EmployeeQualification;
use App\Models\OrganizationalUnit;
use App\Models\Qualification;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * @property TenantKey $tenant
 * @property OrganizationalUnit $orgUnit
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.frontend_url', 'https://app.secpal.dev');
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

afterEach(function () {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('onboarding invitation mail has correct content', function () {
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'john.doe.onboarding@secpal.dev',
        'contract_start_date' => now()->addDays(14),
        'status' => Employee::STATUS_ACTIVE, // Avoid observer creating user
    ]);

    $user = User::factory()->create([
        'name' => 'John Doe',
        'email' => 'john.doe.onboarding@secpal.dev',
        'password' => bcrypt('password'),
    ]);

    $mail = new OnboardingInvitationMail($employee, $user, 'fixed-onboarding-token');

    $content = $mail->content();
    expect($content->markdown)->toBe('emails.employees.onboarding-invitation');
});

test('onboarding invitation URL includes token and email parameters', function () {
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'email' => 'test.onboarding@secpal.dev',
        'status' => Employee::STATUS_ACTIVE,
    ]);

    $user = User::factory()->create([
        'name' => 'Test User',
        'email' => 'test.onboarding@secpal.dev',
        'password' => bcrypt('password'),
    ]);

    $mail = new OnboardingInvitationMail($employee, $user, 'fixed-onboarding-token');
    $content = $mail->content();
    $onboardingUrl = $content->with['onboardingUrl'];

    // URL must contain both token and email parameters
    expect($onboardingUrl)
        ->toContain('?token=fixed-onboarding-token')
        ->toContain('&email='.urlencode('test.onboarding@secpal.dev'));
});

test('onboarding invitation mail preserves plaintext token property', function () {
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'email' => 'test.reset@secpal.dev',
        'status' => Employee::STATUS_ACTIVE,
    ]);

    $user = User::factory()->create([
        'name' => 'Test User',
        'email' => 'test.reset@secpal.dev',
        'password' => bcrypt('password'),
    ]);

    $mail = new OnboardingInvitationMail($employee, $user, 'provided-token-123');

    expect($mail->employee->id)->toBe($employee->id);
    expect($mail->user->id)->toBe($user->id);
    expect($mail->plainToken)->toBe('provided-token-123');
});

test('welcome active mail has correct content', function () {
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'first_name' => 'Jane',
        'last_name' => 'Smith',
        'email' => 'jane.smith.welcome@secpal.dev',
        'status' => Employee::STATUS_ACTIVE,
        'employee_number' => 'EMP-WELCOME-001',
    ]);

    $mail = new WelcomeActiveMail($employee);

    $content = $mail->content();
    expect($content->markdown)->toBe('emails.employees.welcome-active');
});

test('welcome active mail contains employee data', function () {
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'status' => Employee::STATUS_ACTIVE,
        'employee_number' => 'TEST-002',
    ]);

    $mail = new WelcomeActiveMail($employee);

    expect($mail->employee->id)->toBe($employee->id);
    expect($mail->employee->employee_number)->toBe('TEST-002');
});

test('employee lifecycle mail subjects resolve in English', function () {
    app()->setLocale('en');

    expect(__('emails.account_deactivated.subject'))->toBe('Account Deactivated')
        ->and(__('emails.contract_ending_soon.subject'))->toBe('Your Contract Ends Soon')
        ->and(__('emails.welcome_active.subject'))->toBe('Welcome to the Team!')
        ->and(__('emails.qualification_expiring.qualification_fallback'))->toBe('Qualification')
        ->and(__('emails.qualification_expiring.subject', ['qualification_name' => 'First Aid']))
        ->toBe('Qualification Expiring Soon: First Aid');
});

test('employee lifecycle mail subjects resolve in German', function () {
    app()->setLocale('de');

    expect(__('emails.account_deactivated.subject'))->toBe('Konto deaktiviert')
        ->and(__('emails.contract_ending_soon.subject'))->toBe('Ihr Vertrag endet bald')
        ->and(__('emails.welcome_active.subject'))->toBe('Willkommen im Team!')
        ->and(__('emails.qualification_expiring.qualification_fallback'))->toBe('Qualifikation')
        ->and(__('emails.qualification_expiring.subject', ['qualification_name' => 'Erste Hilfe']))
        ->toBe('Qualifikation läuft bald ab: Erste Hilfe');
});

test('account deactivated mail has correct content', function () {
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'first_name' => 'Bob',
        'last_name' => 'Johnson',
        'email' => 'bob.johnson.deactivated@secpal.dev',
        'status' => Employee::STATUS_TERMINATED,
        'termination_date' => now(),
    ]);

    $mail = new AccountDeactivatedMail($employee);

    $content = $mail->content();
    expect($content->markdown)->toBe('emails.employees.account-deactivated');
});

test('account deactivated mail contains termination date', function () {
    $terminationDate = now()->subDays(1);
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'status' => Employee::STATUS_TERMINATED,
        'termination_date' => $terminationDate,
    ]);

    $mail = new AccountDeactivatedMail($employee);

    expect($mail->employee->termination_date->format('Y-m-d'))->toBe($terminationDate->format('Y-m-d'));
});

test('contract ending soon mail has correct content', function () {
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'first_name' => 'Alice',
        'last_name' => 'Brown',
        'email' => 'alice.brown.ending@secpal.dev',
        'status' => Employee::STATUS_ACTIVE,
        'termination_date' => now()->addDays(30),
    ]);

    $mail = new ContractEndingSoonMail($employee);

    $content = $mail->content();
    expect($content->markdown)->toBe('emails.employees.contract-ending-soon');
});

test('contract ending soon mail contains future termination date', function () {
    $futureDate = now()->addDays(45);
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'termination_date' => $futureDate,
    ]);

    $mail = new ContractEndingSoonMail($employee);

    expect($mail->employee->termination_date->format('Y-m-d'))->toBe($futureDate->format('Y-m-d'));
});

test('qualification expiring mail has correct content', function () {
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'first_name' => 'Charlie',
        'last_name' => 'Wilson',
        'email' => 'charlie.wilson.qual@secpal.dev',
        'status' => Employee::STATUS_ACTIVE,
    ]);

    $qualification = Qualification::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'First Aid Certificate',
        'code' => 'FIRST-AID',
        'is_active' => true,
    ]);

    $empQual = EmployeeQualification::create([
        'employee_id' => $employee->id,
        'qualification_id' => $qualification->id,
        'obtained_date' => now()->subYears(2),
        'expiry_date' => now()->addDays(30),
        'status' => EmployeeQualification::STATUS_EXPIRING,
    ]);

    $mail = new QualificationExpiringMail($empQual);

    $content = $mail->content();
    expect($content->markdown)->toBe('emails.employees.qualification-expiring');
});

test('qualification expiring mail contains qualification data', function () {
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
    ]);

    $qualification = Qualification::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Safety Training',
        'code' => 'SAFETY-001',
        'is_active' => true,
    ]);

    $expiryDate = now()->addDays(25);
    $empQual = EmployeeQualification::create([
        'employee_id' => $employee->id,
        'qualification_id' => $qualification->id,
        'obtained_date' => now()->subYear(),
        'expiry_date' => $expiryDate,
        'status' => EmployeeQualification::STATUS_EXPIRING,
        'certificate_number' => 'CERT-12345',
        'issuing_authority' => 'Test Authority',
    ]);

    $mail = new QualificationExpiringMail($empQual);

    expect($mail->qualification->qualification->name)->toBe('Safety Training');
    expect($mail->qualification->certificate_number)->toBe('CERT-12345');
    expect($mail->qualification->issuing_authority)->toBe('Test Authority');
});

test('employee compliance alert mail has correct content', function () {
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'first_name' => 'Taylor',
        'last_name' => 'Alert',
        'email' => 'taylor.alert@secpal.dev',
        'status' => Employee::STATUS_ACTIVE,
    ]);

    $mail = new EmployeeComplianceAlertMail($employee, [
        [
            'type' => 'firearms_license',
            'label' => 'Firearms License',
            'expiry' => now()->addDays(7)->toDateString(),
            'status' => 'critical',
            'days_until_expiry' => 7,
        ],
    ], 'critical');

    $content = $mail->content();
    expect($content->markdown)->toBe('emails.employees.compliance-alert');
});

test('employee compliance alert mail stores provided severity and documents', function () {
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'email' => 'severity.alert@secpal.dev',
        'status' => Employee::STATUS_ACTIVE,
    ]);

    $documents = [
        [
            'type' => 'firearms_license',
            'label' => 'Firearms License',
            'expiry' => now()->subDay()->toDateString(),
            'status' => 'expired',
            'days_until_expiry' => -1,
        ],
    ];

    $mail = new EmployeeComplianceAlertMail($employee, $documents, 'expired');

    expect($mail->employee->id)->toBe($employee->id);
    expect($mail->documents)->toBe($documents);
    expect($mail->severity)->toBe('expired');
});

test('employee compliance alert mail envelope subject contains translated severity', function () {
    app()->setLocale('en');

    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'email' => 'envelope.test@secpal.dev',
        'status' => Employee::STATUS_ACTIVE,
    ]);

    $mail = new EmployeeComplianceAlertMail($employee, [], 'critical');

    $subject = $mail->envelope()->subject;
    expect($subject)->toBe('Compliance documents require attention: critical');
});

test('bwr id document auto deleted mail includes employee details and deletion reason', function () {
    $employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $this->orgUnit->id,
        'employee_number' => 'EMP-912',
        'first_name' => 'Casey',
        'last_name' => 'Secure',
        'email' => 'casey.secure@secpal.dev',
        'bwr_id' => '1234567',
        'status' => Employee::STATUS_ACTIVE,
    ]);

    $mail = new BwrIdDocumentAutoDeletedMail($employee);

    expect($mail->content()->markdown)->toBe('emails.hr.bwr-id-document-auto-deleted')
        ->and($mail->envelope()->subject)->toContain('BWR')
        ->and($mail->render())->toContain('Casey Secure')
        ->and($mail->render())->toContain('EMP-912')
        ->and($mail->render())->toContain('deleted automatically because BWR approval made continued storage unnecessary');
});

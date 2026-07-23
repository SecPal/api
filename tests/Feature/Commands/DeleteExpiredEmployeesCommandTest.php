<?php

/**
 * SPDX-FileCopyrightText: 2026 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution
 */

declare(strict_types=1);

use App\Models\Activity;
use App\Models\Customer;
use App\Models\CustomerAssignment;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\OnboardingFormSubmission;
use App\Models\OnboardingSubmissionFile;
use App\Models\OrganizationalUnit;
use App\Models\Site;
use App\Models\SiteAssignment;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class)->group('serial');

beforeEach(function (): void {
    Storage::fake('local');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

test('it deletes expired terminated employees, removes local files, and anonymizes linked users', function (): void {
    $tenant = TenantKey::factory()->create();
    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Jane Doe',
        'email' => 'jane.doe@secpal.dev',
    ]);

    $employee = Employee::factory()
        ->for($tenant, 'tenant')
        ->terminated()
        ->create([
            'user_id' => $user->id,
            'status' => Employee::STATUS_TERMINATED,
            'user_account_active' => false,
            'employment_end_date' => now()->subYears(4)->toDateString(),
            'retention_period_end' => now()->subDay()->toDateString(),
            'id_document_copy_path' => 'employees/expired/id_document.enc',
        ]);

    Storage::disk('local')->put('employees/expired/id_document.enc', 'encrypted-id-copy');

    $document = EmployeeDocument::factory()->create([
        'employee_id' => $employee->id,
        'uploaded_by' => $user->id,
        'file_path' => 'employees/expired/documents/contract.enc',
    ]);

    Storage::disk('local')->put($document->file_path, 'encrypted-document');

    $submission = OnboardingFormSubmission::factory()->submitted()->create([
        'employee_id' => $employee->id,
    ]);

    $submissionFile = OnboardingSubmissionFile::create([
        'onboarding_form_submission_id' => $submission->id,
        'uploaded_by' => $user->id,
        'document_type' => 'contract',
        'file_path' => 'employees/expired/onboarding/supporting.enc',
        'file_name' => 'supporting.pdf',
        'mime_type' => 'application/pdf',
        'file_size' => 128,
    ]);

    Storage::disk('local')->put($submissionFile->file_path, 'encrypted-submission-file');

    $user->createToken('expired-user-device');
    DB::table('sessions')->insert([
        'id' => 'session-expired-user',
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Pest',
        'payload' => 'payload',
        'last_activity' => now()->timestamp,
    ]);

    $this->artisan('employees:delete-expired')
        ->expectsOutputToContain('Deleted 1 expired employee record(s)')
        ->assertSuccessful();

    $this->assertDatabaseMissing('employees', ['id' => $employee->id]);
    $this->assertDatabaseMissing('employee_documents', ['id' => $document->id]);
    $this->assertDatabaseMissing('onboarding_form_submissions', ['id' => $submission->id]);
    $this->assertDatabaseMissing('onboarding_submission_files', ['id' => $submissionFile->id]);

    Storage::disk('local')->assertMissing('employees/expired/id_document.enc');
    Storage::disk('local')->assertMissing($document->file_path);
    Storage::disk('local')->assertMissing($submissionFile->file_path);

    $user->refresh();

    expect($user->name)->toBe('Deleted User')
        ->and($user->email)->toBe('deleted-user+'.$user->id.'@secpal.dev')
        ->and($user->email_verified_at)->toBeNull()
        ->and(Hash::check('password', $user->password))->toBeFalse();

    expect(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse();
    expect($user->tokens()->count())->toBe(0);

    $activity = DB::table('activity_log')
        ->where('description', 'Employee data deleted after retention period')
        ->where('subject_id', $employee->id)
        ->first();

    expect($activity)->not->toBeNull();
});

test('it preserves activity causer rank context before deleting expired employees', function (): void {
    $tenant = TenantKey::factory()->create();
    $orgUnit = OrganizationalUnit::factory()->create([
        'tenant_id' => $tenant->id,
    ]);
    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $employee = Employee::factory()
        ->for($tenant, 'tenant')
        ->terminated()
        ->create([
            'user_id' => $user->id,
            'management_level' => 3,
            'status' => Employee::STATUS_TERMINATED,
            'employment_end_date' => now()->subYears(4)->toDateString(),
            'retention_period_end' => now()->subDay()->toDateString(),
        ]);

    $activity = Activity::factory()->create([
        'tenant_id' => $tenant->id,
        'organizational_unit_id' => $orgUnit->id,
        'causer_type' => User::class,
        'causer_id' => $user->id,
        'properties' => ['existing' => 'value'],
    ]);

    $this->artisan('employees:delete-expired')
        ->expectsOutputToContain('Deleted 1 expired employee record(s)')
        ->assertSuccessful();

    $activity->refresh();

    expect($activity->properties)
        ->toMatchArray([
            'existing' => 'value',
        ])
        ->and($activity->causer_employee_id)->toBeNull()
        ->and($activity->causer_employee_organizational_unit_id)->toBeNull()
        ->and($activity->causer_employee_management_level)->toBeNull()
        ->and($activity->verifyChain())->toBeTrue();
});

test('it deletes push registrations when anonymizing a linked user', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-30 12:00:00 UTC'));

    $tenant = TenantKey::factory()->create();
    $otherTenant = TenantKey::factory()->create();
    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'android.cleanup@secpal.dev',
    ]);

    Employee::factory()
        ->for($tenant, 'tenant')
        ->terminated()
        ->create([
            'user_id' => $user->id,
            'status' => Employee::STATUS_TERMINATED,
            'user_account_active' => false,
            'employment_end_date' => now()->subYears(2)->toDateString(),
            'retention_period_end' => now()->subDay()->toDateString(),
        ]);

    DB::table('push_device_registrations')->insert([
        'id' => (string) Str::uuid(),
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'installation_id' => (string) Str::uuid(),
        'platform' => 'android',
        'provider' => 'fcm',
        'device_name' => 'Warehouse handheld',
        'push_token_enc' => '{"ciphertext":"demo","nonce":"demo"}',
        'token_last_eight' => '89abcdef',
        'last_lifecycle_event' => 'registered',
        'package_name' => 'app.secpal',
        'package_version_name' => '1.5.0',
        'package_version_code' => 10500,
        'manufacturer' => 'Samsung',
        'model' => 'SM-G556B',
        'android_version' => '16',
        'sdk_int' => 36,
        'bootstrap_version' => 'v1',
        'schema_version' => 3,
        'push_metadata_revision' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('push_device_registrations')->insert([
        'id' => (string) Str::uuid(),
        'tenant_id' => $otherTenant->id,
        'user_id' => $user->id,
        'installation_id' => (string) Str::uuid(),
        'platform' => 'android',
        'provider' => 'fcm',
        'device_name' => 'Other tenant handheld',
        'push_token_enc' => '{"ciphertext":"demo-other","nonce":"demo-other"}',
        'token_last_eight' => '76543210',
        'last_lifecycle_event' => 'registered',
        'package_name' => 'app.secpal',
        'package_version_name' => '1.5.0',
        'package_version_code' => 10500,
        'manufacturer' => 'Samsung',
        'model' => 'SM-X200',
        'android_version' => '16',
        'sdk_int' => 36,
        'bootstrap_version' => 'v1',
        'schema_version' => 3,
        'push_metadata_revision' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('employees:delete-expired')->assertSuccessful();

    expect(DB::table('push_device_registrations')
        ->where('tenant_id', $tenant->id)
        ->where('user_id', $user->id)
        ->exists())->toBeFalse()
        ->and(DB::table('push_device_registrations')
            ->where('tenant_id', $otherTenant->id)
            ->where('user_id', $user->id)
            ->exists())->toBeTrue();
});

test('it erases customer and site assignment history during retention deletion', function (): void {
    $tenant = TenantKey::factory()->create();
    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);
    $otherUser = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);
    $customer = Customer::factory()->create([
        'tenant_id' => $tenant->id,
    ]);
    $site = Site::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    $employee = Employee::factory()
        ->for($tenant, 'tenant')
        ->terminated()
        ->create([
            'user_id' => $user->id,
            'status' => Employee::STATUS_TERMINATED,
            'user_account_active' => false,
            'employment_end_date' => now()->subYears(4)->toDateString(),
            'retention_period_end' => now()->subDay()->toDateString(),
        ]);

    $customerAssignment = CustomerAssignment::factory()
        ->for($tenant, 'tenant')
        ->create([
            'customer_id' => $customer->id,
            'user_id' => $user->id,
            'valid_from' => now()->subMonths(6),
            'valid_until' => now()->subMonth(),
        ]);

    $siteAssignment = SiteAssignment::factory()
        ->for($tenant, 'tenant')
        ->create([
            'site_id' => $site->id,
            'user_id' => $user->id,
            'valid_from' => now()->subMonths(3),
            'valid_until' => null,
        ]);

    $otherCustomerAssignment = CustomerAssignment::factory()
        ->for($tenant, 'tenant')
        ->create([
            'customer_id' => $customer->id,
            'user_id' => $otherUser->id,
            'valid_from' => now()->subMonths(2),
            'valid_until' => null,
        ]);

    $otherSiteAssignment = SiteAssignment::factory()
        ->for($tenant, 'tenant')
        ->create([
            'site_id' => $site->id,
            'user_id' => $otherUser->id,
            'valid_from' => now()->subMonths(1),
            'valid_until' => null,
        ]);

    $this->artisan('employees:delete-expired')
        ->expectsOutputToContain('Deleted 1 expired employee record(s)')
        ->assertSuccessful();

    $this->assertDatabaseMissing('employees', ['id' => $employee->id]);
    $this->assertDatabaseMissing('customer_assignments', ['id' => $customerAssignment->id]);
    $this->assertDatabaseMissing('site_assignments', ['id' => $siteAssignment->id]);

    $this->assertDatabaseHas('customer_assignments', ['id' => $otherCustomerAssignment->id]);
    $this->assertDatabaseHas('site_assignments', ['id' => $otherSiteAssignment->id]);
});

test('it supports dry run without deleting employee records', function (): void {
    $tenant = TenantKey::factory()->create();

    $employee = Employee::factory()
        ->for($tenant, 'tenant')
        ->terminated()
        ->create([
            'status' => Employee::STATUS_TERMINATED,
            'employment_end_date' => now()->subYears(4)->toDateString(),
            'retention_period_end' => now()->subDay()->toDateString(),
        ]);

    $this->artisan('employees:delete-expired --dry-run')
        ->expectsOutputToContain('Would delete 1 expired employee record(s)')
        ->assertSuccessful();

    $this->assertDatabaseHas('employees', ['id' => $employee->id]);
});

test('it limits deletion to the selected tenant', function (): void {
    $tenantA = TenantKey::factory()->create();
    $tenantB = TenantKey::factory()->create();

    $employeeA = Employee::factory()
        ->for($tenantA, 'tenant')
        ->terminated()
        ->create([
            'status' => Employee::STATUS_TERMINATED,
            'employment_end_date' => now()->subYears(4)->toDateString(),
            'retention_period_end' => now()->subDay()->toDateString(),
        ]);

    $employeeB = Employee::factory()
        ->for($tenantB, 'tenant')
        ->terminated()
        ->create([
            'status' => Employee::STATUS_TERMINATED,
            'employment_end_date' => now()->subYears(4)->toDateString(),
            'retention_period_end' => now()->subDay()->toDateString(),
        ]);

    $this->artisan('employees:delete-expired', ['--tenant' => $tenantA->id])
        ->expectsOutputToContain('Deleted 1 expired employee record(s)')
        ->assertSuccessful();

    $this->assertDatabaseMissing('employees', ['id' => $employeeA->id]);
    $this->assertDatabaseHas('employees', ['id' => $employeeB->id]);
});

test('it deletes every expired employee across multiple chunks', function (): void {
    $tenant = TenantKey::factory()->create();

    Employee::factory()
        ->count(55)
        ->for($tenant, 'tenant')
        ->terminated()
        ->create([
            'status' => Employee::STATUS_TERMINATED,
            'employment_end_date' => now()->subYears(4)->toDateString(),
            'retention_period_end' => now()->subDay()->toDateString(),
        ]);

    $this->artisan('employees:delete-expired')
        ->expectsOutputToContain('Deleted 55 expired employee record(s)')
        ->assertSuccessful();

    expect(Employee::query()->count())->toBe(0);
});

test('it skips and logs suspicious file paths that fall outside the employees/ prefix', function (): void {
    $tenant = TenantKey::factory()->create();

    $employee = Employee::factory()
        ->for($tenant, 'tenant')
        ->terminated()
        ->create([
            'status' => Employee::STATUS_TERMINATED,
            'employment_end_date' => now()->subYears(4)->toDateString(),
            'retention_period_end' => now()->subDay()->toDateString(),
            'id_document_copy_path' => '../etc/passwd',
        ]);

    Storage::disk('local')->put('employees/safe.enc', 'safe-content');

    Log::spy();

    $this->artisan('employees:delete-expired')
        ->expectsOutputToContain('Deleted 1 expired employee record(s)')
        ->assertSuccessful();

    $this->assertDatabaseMissing('employees', ['id' => $employee->id]);

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $msg) => str_contains($msg, 'suspicious'));

    Storage::disk('local')->assertExists('employees/safe.enc');
});

test('it clears password reset tokens for the pre-anonymization email', function (): void {
    $tenant = TenantKey::factory()->create();
    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'jane.doe@secpal.dev',
    ]);

    DB::table('password_reset_tokens')->insert([
        'email' => $user->email,
        'token' => Hash::make('some-token'),
        'created_at' => now()->subMinutes(10),
    ]);

    $employee = Employee::factory()
        ->for($tenant, 'tenant')
        ->terminated()
        ->create([
            'user_id' => $user->id,
            'status' => Employee::STATUS_TERMINATED,
            'employment_end_date' => now()->subYears(4)->toDateString(),
            'retention_period_end' => now()->subDay()->toDateString(),
        ]);

    $this->artisan('employees:delete-expired')
        ->expectsOutputToContain('Deleted 1 expired employee record(s)')
        ->assertSuccessful();

    expect(DB::table('password_reset_tokens')->where('email', 'jane.doe@secpal.dev')->exists())->toBeFalse();
});

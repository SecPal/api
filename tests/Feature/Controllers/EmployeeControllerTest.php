<?php

/*
 * SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution
 */

use App\Mail\BwrIdDocumentAutoDeletedMail;
use App\Mail\OnboardingInvitationMail;
use App\Models\Employee;
use App\Models\EmployeeAddress;
use App\Models\EmployeeDocument;
use App\Models\EmployeeOnboardingToken;
use App\Models\EmployeeQualification;
use App\Models\Establishment;
use App\Models\LegalEntity;
use App\Models\OrganizationalUnit;
use App\Models\Permission;
use App\Models\Qualification;
use App\Models\TenantKey;
use App\Models\User;
use App\Support\LikePattern;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\travelTo;

uses(RefreshDatabase::class);

/**
 * @property TenantKey $tenant
 * @property User $user
 * @property string $token
 * @property LegalEntity $legalEntity
 * @property Establishment $establishment
 * @property OrganizationalUnit $organizationalUnit
 */
beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    // Set tenant context for permission system
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($this->tenant->id);

    // Run seeder to ensure predefined roles exist
    Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);
    Role::firstOrCreate([
        'name' => 'Employee',
        'guard_name' => 'sanctum',
    ]);
    Role::firstOrCreate([
        'name' => 'Employee Read Only',
        'guard_name' => 'sanctum',
    ]);

    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('test-device')->plainTextToken;
    givePermissionWithTenant($this->user, $this->tenant->id, 'employees.read_salary');
    $registrar->setPermissionsTeamId($this->tenant->id);

    $this->organizationalUnit = OrganizationalUnit::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);
    $this->legalEntity = LegalEntity::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);
    $this->establishment = Establishment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->legalEntity->id,
    ]);
});

afterEach(function (): void {
    // Reset tenant context
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

function grantDualManagementScopes(User $user, string $organizationalUnitId): void
{
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $organizationalUnitId,
        'access_level' => 'manage',
        'include_descendants' => true,
        'min_viewable_rank' => 0,
        'max_viewable_rank' => 0,
        'min_assignable_rank' => 0,
        'max_assignable_rank' => 0,
        'allow_self_access' => true,
    ]);
    $user->organizationalScopes()->create([
        'organizational_unit_id' => $organizationalUnitId,
        'access_level' => 'manage',
        'include_descendants' => true,
        'min_viewable_rank' => 1,
        'max_viewable_rank' => 255,
        'min_assignable_rank' => 1,
        'max_assignable_rank' => 255,
        'allow_self_access' => true,
    ]);
}

describe('GET /v1/employees', function () {
    test('returns 401 when not authenticated', function (): void {
        $response = $this->getJson('/v1/employees');
        $response->assertStatus(401);
    });

    test('returns 403 when user lacks employee.read permission', function (): void {
        $response = $this->withToken($this->token)->getJson('/v1/employees');
        $response->assertStatus(403);
    });

    test('returns paginated employees with valid permission', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.read');

        Employee::factory()->count(3)->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
        ]);

        $response = $this->withToken($this->token)->getJson('/v1/employees');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'employee_number', 'first_name', 'last_name', 'email', 'status'],
                ],
                'links',
                'meta',
            ]);
    });

    test('filters employees by status', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.read');

        Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'status' => Employee::STATUS_ACTIVE,
        ]);

        Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'status' => Employee::STATUS_TERMINATED,
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/v1/employees?status=active');

        $response->assertStatus(200);
        expect($response->json('data'))->toHaveCount(1);
    });

    test('filters employees by establishment_id', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.read');

        $otherEstablishment = Establishment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
        ]);

        Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
        ]);

        Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $otherEstablishment->id,
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/v1/employees?establishment_id={$this->establishment->id}");

        $response->assertStatus(200);
        expect($response->json('data'))->toHaveCount(1);
    });

    test('returns 422 for invalid establishment_id filter format', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.read');

        $response = $this->withToken($this->token)
            ->getJson('/v1/employees?establishment_id=1');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['establishment_id']);
    });

    test('returns empty list for foreign-tenant establishment_id filter', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.read');

        $otherTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
        $foreignEstablishment = Establishment::factory()->create([
            'tenant_id' => $otherTenant->id,
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/v1/employees?establishment_id={$foreignEstablishment->id}");

        $response->assertOk();
        expect($response->json('data'))->toBeArray();
        expect($response->json('data'))->toHaveCount(0);
    });

    test('organizationally scoped users fail closed for employee domain records', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.read');
        grantDualManagementScopes($this->user, $this->organizationalUnit->id);
        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
        ]);
        $response = $this->withToken($this->token)->getJson('/v1/employees');

        $response->assertOk()
            ->assertJsonCount(0, 'data');

        $this->withToken($this->token)
            ->getJson("/v1/employees/{$employee->id}")
            ->assertForbidden();
    });

    test('searches employees by email', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.read');

        Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'email' => 'john.doe@example.com',
        ]);

        Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'email' => 'jane.smith@example.com',
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/v1/employees?search=john.doe');

        $response->assertStatus(200);
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data')[0]['email'])->toBe('john.doe@example.com');
    });

    test('returns employees with compliance alerts for dashboard overview', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.read');

        Employee::factory()->withExpiringComplianceCertifications()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'status' => Employee::STATUS_ACTIVE,
            'email' => 'alert@example.com',
        ]);

        Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'status' => Employee::STATUS_ACTIVE,
            'email' => 'clear@example.com',
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/v1/employees/compliance-alerts');

        $response->assertOk()
            ->assertJsonCount(1, 'data');

        expect($response->json('data.0.email'))->toBe('alert@example.com')
            ->and(collect($response->json('data.0.expiring_documents'))->pluck('status')->all())
            ->toContain('expired');
    });

    test('compliance alerts endpoint paginates after alert filtering', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.read');

        Employee::factory(2)->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'status' => Employee::STATUS_ACTIVE,
        ]);

        Employee::factory()->withExpiringWorkPermit()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'status' => Employee::STATUS_ACTIVE,
            'email' => 'work-permit-alert@example.com',
            'nationalities' => ['TR'],
            'work_permit_expiry' => now()->addDays(5)->toDateString(),
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/v1/employees/compliance-alerts?per_page=2');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'work-permit-alert@example.com')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.per_page', 2);

        expect(collect($response->json('data.0.expiring_documents'))->pluck('type')->all())
            ->toContain('work_permit');
    });

    test('filters employee compliance alerts by alert status', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.read');

        Employee::factory()->withComplianceCertifications()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'status' => Employee::STATUS_ACTIVE,
            'email' => 'warning@example.com',
            'firearms_license_expiry' => now()->addDays(20)->toDateString(),
            'first_aid_cert_expiry' => now()->addDays(45)->toDateString(),
            'evacuation_cert_expiry' => now()->addDays(50)->toDateString(),
            'additional_certifications' => [
                [
                    'name' => 'Badge',
                    'issued_date' => now()->subMonth()->toDateString(),
                    'expiry_date' => now()->addDays(18)->toDateString(),
                    'issuer' => 'Customer Security',
                ],
            ],
        ]);

        Employee::factory()->withExpiringComplianceCertifications()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'status' => Employee::STATUS_ACTIVE,
            'email' => 'critical@example.com',
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/v1/employees/compliance-alerts?compliance_status=warning');

        $response->assertOk()
            ->assertJsonCount(1, 'data');

        expect($response->json('data.0.email'))->toBe('warning@example.com');
    });

    test('returns 422 for invalid employee compliance alert status filter', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.read');

        $response = $this->withToken($this->token)
            ->getJson('/v1/employees/compliance-alerts?compliance_status=blocked');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['compliance_status']);
    });

    test('compliance alerts endpoint paginates results and respects per_page', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.read');

        Employee::factory(3)->withExpiringComplianceCertifications()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'status' => Employee::STATUS_ACTIVE,
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/v1/employees/compliance-alerts?per_page=2');

        $response->assertOk();

        expect($response->json('data'))->toHaveCount(2)
            ->and($response->json('meta'))->toHaveKey('current_page')
            ->and($response->json('meta.total'))->toBeGreaterThanOrEqual(2);
    });

    test('treats wildcard-only employee search input as a literal string', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.read');

        Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'email' => 'john.doe@secpal.dev',
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/v1/employees?search='.urlencode('%%%%%'));

        $response->assertStatus(200);
        expect($response->json('data'))->toHaveCount(0);
    });

    test('binds escaped like patterns for literal backslash wildcard employee searches', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.read');

        Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'email' => 'foo\\%_bar@secpal.dev',
        ]);

        DB::enableQueryLog();

        $response = $this->withToken($this->token)
            ->getJson('/v1/employees?search='.urlencode('foo\%_bar'));

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $response->assertOk();

        $bindings = collect($queries)
            ->pluck('bindings')
            ->flatten(1)
            ->filter(fn (mixed $binding): bool => is_string($binding));

        expect($bindings)->toContain('%'.LikePattern::escape('foo\%_bar').'%');
    });
});

describe('POST /v1/employees', function () {
    test('returns 401 when not authenticated', function (): void {
        $response = $this->postJson('/v1/employees', [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
        ]);

        $response->assertStatus(401);
    });

    test('returns 403 when user lacks employee.write permission', function (): void {
        $response = $this->withToken($this->token)->postJson('/v1/employees', [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'date_of_birth' => '1990-01-15',
            'position' => 'Security Guard',
            'status' => 'pre_contract',
            'contract_type' => 'full_time',
            'contract_start_date' => now()->toDateString(),
            'weekly_hours' => 40,
            'hourly_rate' => 15.50,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'management_level' => 0,
        ]);

        $response->assertStatus(403);
    });

    test('returns 422 when required fields are missing', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $response = $this->withToken($this->token)
            ->postJson('/v1/employees', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'first_name',
                'last_name',
                'email',
                'date_of_birth',
                'position',
                'status',
                'contract_start_date',
                'contract_type',
                'legal_entity_id',
                'establishment_id',
            ]);
    });

    test('returns 422 when frontend-required employee fields are missing', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $response = $this->withToken($this->token)
            ->postJson('/v1/employees', [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => 'john.doe@example.com',
                'status' => Employee::STATUS_PRE_CONTRACT,
                'contract_type' => 'full_time',
                'management_level' => 0,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'date_of_birth',
                'position',
                'contract_start_date',
                'legal_entity_id',
                'establishment_id',
            ]);
    });

    test('creates a non-management employee when management level is omitted', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $response = $this->withToken($this->token)
            ->postJson('/v1/employees', [
                'first_name' => 'Nina',
                'last_name' => 'Newhire',
                'email' => 'nina.newhire@example.com',
                'date_of_birth' => '1993-05-15',
                'position' => 'Security Guard',
                'status' => Employee::STATUS_PRE_CONTRACT,
                'contract_type' => 'full_time',
                'contract_start_date' => now()->addWeek()->toDateString(),
                'weekly_hours' => 40,
                'hourly_rate' => 16.50,
                'legal_entity_id' => $this->legalEntity->id,
                'establishment_id' => $this->establishment->id,
                'sachkunde_type' => 'none',
                'work_permit_type' => 'none',
                'criminal_record_status' => 'valid',
                'send_invitation' => true,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.management_level', 0)
            ->assertJsonPath('data.onboarding_invitation.status', Employee::INVITATION_STATUS_SENT);

        $employee = Employee::findOrFail($response->json('data.id'));

        expect($employee->management_level)->toBe(0)
            ->and($employee->onboarding_invitation_status)->toBe(Employee::INVITATION_STATUS_SENT);
    });

    test('rejects salary writes on employee creation without employees.read_salary permission', function (): void {
        $user = User::factory()->create();
        $token = $user->createToken('test-device')->plainTextToken;
        givePermissionWithTenant($user, $this->tenant->id, 'employee.write');

        $response = $this->withToken($token)
            ->postJson('/v1/employees', [
                'first_name' => 'Nina',
                'last_name' => 'Newhire',
                'email' => 'nina.no-salary@secpal.dev',
                'date_of_birth' => '1993-05-15',
                'position' => 'Security Guard',
                'status' => Employee::STATUS_PRE_CONTRACT,
                'contract_type' => 'full_time',
                'contract_start_date' => now()->addWeek()->toDateString(),
                'weekly_hours' => 40,
                'hourly_rate' => 16.50,
                'legal_entity_id' => $this->legalEntity->id,
                'establishment_id' => $this->establishment->id,
                'management_level' => 0,
                'sachkunde_type' => 'none',
                'work_permit_type' => 'none',
                'criminal_record_status' => 'valid',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['hourly_rate']);
    });

    test('creates employee with auto-generated employee_number', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $response = $this->withToken($this->token)
            ->postJson('/v1/employees', [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => 'john.doe@example.com',
                'date_of_birth' => '1990-01-15',
                'position' => 'Security Guard',
                'status' => 'pre_contract',
                'contract_type' => 'full_time',
                'contract_start_date' => now()->toDateString(),
                'weekly_hours' => 40,
                'hourly_rate' => 15.50,
                'legal_entity_id' => $this->legalEntity->id,
                'establishment_id' => $this->establishment->id,
                'sachkunde_type' => 'none',
                'work_permit_type' => 'none',
                'criminal_record_status' => 'valid',
                'management_level' => 0,
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'employee_number',
                    'first_name',
                    'last_name',
                    'email',
                    'status',
                    'onboarding_workflow' => ['status'],
                ],
            ]);

        $employeeNumber = $response->json('data.employee_number');
        expect($employeeNumber)->toMatch('/^EMP-\d{4}-\d{4}$/');
        expect($response->json('data.status'))->toBe(Employee::STATUS_PRE_CONTRACT);
        expect($response->json('data.onboarding_workflow.status'))->toBe(Employee::WORKFLOW_STATUS_INVITED);
    });

    test('returns 422 when employee email is already taken', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $payload = [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'duplicate.employee@example.com',
            'date_of_birth' => '1990-01-15',
            'position' => 'Security Guard',
            'status' => 'pre_contract',
            'contract_type' => 'full_time',
            'contract_start_date' => now()->toDateString(),
            'weekly_hours' => 40,
            'hourly_rate' => 15.50,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'sachkunde_type' => 'none',
            'work_permit_type' => 'none',
            'criminal_record_status' => 'valid',
            'management_level' => 0,
        ];

        $this->withToken($this->token)
            ->postJson('/v1/employees', $payload)
            ->assertCreated();

        $response = $this->withToken($this->token)
            ->postJson('/v1/employees', [
                ...$payload,
                'first_name' => 'Jane',
                'last_name' => 'Smith',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    });

    test('creates employee with user account via Observer', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $response = $this->withToken($this->token)
            ->postJson('/v1/employees', [
                'first_name' => 'Jane',
                'last_name' => 'Smith',
                'email' => 'jane.smith@example.com',
                'date_of_birth' => '1995-06-20',
                'position' => 'Site Supervisor',
                'status' => 'pre_contract',
                'contract_type' => 'full_time',
                'contract_start_date' => now()->toDateString(),
                'weekly_hours' => 40,
                'hourly_rate' => 18.00,
                'legal_entity_id' => $this->legalEntity->id,
                'establishment_id' => $this->establishment->id,
                'sachkunde_type' => 'none',
                'work_permit_type' => 'none',
                'criminal_record_status' => 'valid',
                'management_level' => 0,
            ]);

        $response->assertStatus(201);

        $employee = Employee::find($response->json('data.id'));
        expect($employee->user_id)->not->toBeNull();
        expect($employee->user->email)->toBe('jane.smith@example.com');
    });

    test('creates employee and sends onboarding invitation when explicitly requested', function (): void {
        Mail::fake();

        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $response = $this->withToken($this->token)
            ->postJson('/v1/employees', [
                'first_name' => 'Ivy',
                'last_name' => 'Invite',
                'email' => 'ivy.invite@example.com',
                'date_of_birth' => '1991-03-20',
                'position' => 'Security Guard',
                'status' => Employee::STATUS_PRE_CONTRACT,
                'contract_type' => 'full_time',
                'contract_start_date' => now()->addWeek()->toDateString(),
                'weekly_hours' => 40,
                'hourly_rate' => 16.50,
                'legal_entity_id' => $this->legalEntity->id,
                'establishment_id' => $this->establishment->id,
                'sachkunde_type' => 'none',
                'work_permit_type' => 'none',
                'criminal_record_status' => 'valid',
                'management_level' => 0,
                'send_invitation' => true,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.onboarding_invitation.status', Employee::INVITATION_STATUS_SENT);

        $employee = Employee::findOrFail($response->json('data.id'));

        expect($employee->onboarding_invitation_status)->toBe(Employee::INVITATION_STATUS_SENT)
            ->and($employee->onboarding_invitation_requested_at)->not->toBeNull()
            ->and($employee->onboarding_invitation_token_created_at)->not->toBeNull()
            ->and($employee->onboarding_invitation_mail_sent_at)->not->toBeNull()
            ->and($employee->onboarding_invitation_mail_failed_at)->toBeNull()
            ->and(EmployeeOnboardingToken::where('employee_id', $employee->id)->count())->toBe(1);

        Mail::assertSent(OnboardingInvitationMail::class, function (OnboardingInvitationMail $mail) use ($employee): bool {
            return $mail->employee->id === $employee->id
                && $mail->hasTo('ivy.invite@example.com');
        });
    });

    test('returns a visible partial-failure invitation state when onboarding mail cannot be sent', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        config()->set('app.frontend_url', null);

        $response = $this->withToken($this->token)
            ->postJson('/v1/employees', [
                'first_name' => 'Faye',
                'last_name' => 'Failure',
                'email' => 'faye.failure@example.com',
                'date_of_birth' => '1992-07-12',
                'position' => 'Security Guard',
                'status' => Employee::STATUS_PRE_CONTRACT,
                'contract_type' => 'full_time',
                'contract_start_date' => now()->addWeek()->toDateString(),
                'weekly_hours' => 40,
                'hourly_rate' => 16.50,
                'legal_entity_id' => $this->legalEntity->id,
                'establishment_id' => $this->establishment->id,
                'sachkunde_type' => 'none',
                'work_permit_type' => 'none',
                'criminal_record_status' => 'valid',
                'management_level' => 0,
                'send_invitation' => true,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.onboarding_invitation.status', Employee::INVITATION_STATUS_CREATED_NOT_SENT)
            ->assertJsonPath('data.onboarding_invitation.failure_reason', 'Mail delivery failed. Check logs for details.');

        $employee = Employee::findOrFail($response->json('data.id'));

        expect($employee->onboarding_invitation_status)->toBe(Employee::INVITATION_STATUS_CREATED_NOT_SENT)
            ->and($employee->onboarding_invitation_token_created_at)->not->toBeNull()
            ->and($employee->onboarding_invitation_mail_sent_at)->toBeNull()
            ->and($employee->onboarding_invitation_mail_failed_at)->not->toBeNull()
            ->and($employee->onboarding_invitation_failure_reason)->toBe('Mail delivery failed. Check logs for details.')
            ->and(EmployeeOnboardingToken::where('employee_id', $employee->id)->count())->toBe(1);
    });

    test('returns 422 when send_invitation is requested for a non-pre-contract employee', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $response = $this->withToken($this->token)
            ->postJson('/v1/employees', [
                'first_name' => 'Alex',
                'last_name' => 'Active',
                'email' => 'alex.active@example.com',
                'date_of_birth' => '1990-01-15',
                'position' => 'Security Guard',
                'status' => Employee::STATUS_ACTIVE,
                'contract_type' => 'full_time',
                'contract_start_date' => now()->toDateString(),
                'weekly_hours' => 40,
                'hourly_rate' => 15.50,
                'legal_entity_id' => $this->legalEntity->id,
                'establishment_id' => $this->establishment->id,
                'sachkunde_type' => 'none',
                'work_permit_type' => 'none',
                'criminal_record_status' => 'valid',
                'management_level' => 0,
                'send_invitation' => true,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['send_invitation'])
            ->assertJsonPath(
                'errors.send_invitation.0',
                'Invitation sending is only available when employee status is pre_contract. Received: active.'
            );
    });

    test('generates unique employee_number per tenant', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $response1 = $this->withToken($this->token)
            ->postJson('/v1/employees', [
                'first_name' => 'First',
                'last_name' => 'Employee',
                'email' => 'first@example.com',
                'date_of_birth' => '1990-01-01',
                'position' => 'Security Guard',
                'status' => 'pre_contract',
                'contract_type' => 'full_time',
                'contract_start_date' => now()->toDateString(),
                'weekly_hours' => 40,
                'hourly_rate' => 15.00,
                'legal_entity_id' => $this->legalEntity->id,
                'establishment_id' => $this->establishment->id,
                'sachkunde_type' => 'none',
                'work_permit_type' => 'none',
                'criminal_record_status' => 'valid',
                'management_level' => 0,
            ]);

        $response2 = $this->withToken($this->token)
            ->postJson('/v1/employees', [
                'first_name' => 'Second',
                'last_name' => 'Employee',
                'email' => 'second@example.com',
                'date_of_birth' => '1992-02-02',
                'position' => 'Patrol Guard',
                'status' => 'pre_contract',
                'contract_type' => 'part_time',
                'contract_start_date' => now()->toDateString(),
                'weekly_hours' => 20,
                'hourly_rate' => 16.00,
                'legal_entity_id' => $this->legalEntity->id,
                'establishment_id' => $this->establishment->id,
                'sachkunde_type' => 'none',
                'work_permit_type' => 'none',
                'criminal_record_status' => 'valid',
                'management_level' => 0,
            ]);

        $number1 = $response1->json('data.employee_number');
        $number2 = $response2->json('data.employee_number');

        expect($number1)->not->toBe($number2);
        expect($number2)->toMatch('/^EMP-\d{4}-\d{4}$/');
    });

    test('restarts the employee_number sequence for each tenant', function (): void {
        $frozenDate = now()->setMonth(6)->setDay(15)->startOfDay();
        travelTo($frozenDate);

        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');
        $this->user->forceFill(['tenant_id' => $this->tenant->id])->save();
        Sanctum::actingAs($this->user, [User::API_ACCESS_ABILITY]);

        $otherTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
        $otherUser = User::factory()->create(['tenant_id' => $otherTenant->id]);
        givePermissionWithTenant($otherUser, $otherTenant->id, 'employee.write');
        givePermissionWithTenant($otherUser, $otherTenant->id, 'employees.read_salary');

        $otherLegalEntity = LegalEntity::factory()->create([
            'tenant_id' => $otherTenant->id,
        ]);
        $otherEstablishment = Establishment::factory()->create([
            'tenant_id' => $otherTenant->id,
            'legal_entity_id' => $otherLegalEntity->id,
        ]);

        $firstTenantResponse = $this
            ->postJson('/v1/employees', [
                'first_name' => 'Tenant',
                'last_name' => 'One',
                'email' => 'tenant.one@example.com',
                'date_of_birth' => '1990-01-01',
                'position' => 'Security Guard',
                'status' => 'pre_contract',
                'contract_type' => 'full_time',
                'contract_start_date' => now()->toDateString(),
                'weekly_hours' => 40,
                'hourly_rate' => 15.00,
                'legal_entity_id' => $this->legalEntity->id,
                'establishment_id' => $this->establishment->id,
                'sachkunde_type' => 'none',
                'work_permit_type' => 'none',
                'criminal_record_status' => 'valid',
                'management_level' => 0,
            ]);

        Sanctum::actingAs($otherUser, [User::API_ACCESS_ABILITY]);

        $secondTenantResponse = $this
            ->postJson('/v1/employees', [
                'first_name' => 'Tenant',
                'last_name' => 'Two',
                'email' => 'tenant.two@example.com',
                'date_of_birth' => '1991-02-02',
                'position' => 'Site Supervisor',
                'status' => 'pre_contract',
                'contract_type' => 'full_time',
                'contract_start_date' => now()->toDateString(),
                'weekly_hours' => 40,
                'hourly_rate' => 16.00,
                'legal_entity_id' => $otherLegalEntity->id,
                'establishment_id' => $otherEstablishment->id,
                'sachkunde_type' => 'none',
                'work_permit_type' => 'none',
                'criminal_record_status' => 'valid',
                'management_level' => 0,
            ]);

        $expectedEmployeeNumber = sprintf('EMP-%d-0001', $frozenDate->year);

        $firstTenantResponse->assertCreated()
            ->assertJsonPath('data.employee_number', $expectedEmployeeNumber);

        $secondTenantResponse->assertCreated()
            ->assertJsonPath('data.employee_number', $expectedEmployeeNumber);

        expect(Employee::query()->where('employee_number', $expectedEmployeeNumber)->count())->toBe(2);

        Sanctum::actingAs($this->user, [User::API_ACCESS_ABILITY]);
    });

    test('rejects direct retention field writes on employee creation', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $response = $this->withToken($this->token)
            ->postJson('/v1/employees', [
                'first_name' => 'Rita',
                'last_name' => 'Retention',
                'email' => 'rita.retention@example.com',
                'date_of_birth' => '1990-01-15',
                'position' => 'Security Guard',
                'status' => Employee::STATUS_PRE_CONTRACT,
                'contract_type' => 'full_time',
                'contract_start_date' => now()->addWeek()->toDateString(),
                'weekly_hours' => 40,
                'hourly_rate' => 15.50,
                'legal_entity_id' => $this->legalEntity->id,
                'establishment_id' => $this->establishment->id,
                'sachkunde_type' => 'none',
                'work_permit_type' => 'none',
                'criminal_record_status' => 'valid',
                'management_level' => 0,
                'employment_end_date' => now()->toDateString(),
                'retention_period_end' => now()->addYears(3)->endOfYear()->toDateString(),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'employment_end_date' => 'Retention fields are managed by the employee lifecycle and cannot be written directly.',
                'retention_period_end',
            ]);

        $this->assertDatabaseMissing('employees', [
            'email' => 'rita.retention@example.com',
        ]);
    });

    test('rejects direct bwr workflow state writes on employee creation', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $response = $this->withToken($this->token)
            ->postJson('/v1/employees', [
                'first_name' => 'Bianca',
                'last_name' => 'Bypass',
                'email' => 'bianca.bypass@example.com',
                'date_of_birth' => '1990-01-15',
                'position' => 'Security Guard',
                'status' => Employee::STATUS_PRE_CONTRACT,
                'contract_type' => 'full_time',
                'contract_start_date' => now()->addWeek()->toDateString(),
                'weekly_hours' => 40,
                'hourly_rate' => 15.50,
                'legal_entity_id' => $this->legalEntity->id,
                'establishment_id' => $this->establishment->id,
                'sachkunde_type' => 'none',
                'work_permit_type' => 'none',
                'criminal_record_status' => 'valid',
                'management_level' => 0,
                'bwr_status' => 'active',
                'bwr_registered_at' => now()->toDateString(),
                'bwr_submission_date' => now()->toDateString(),
                'bwr_notes' => 'bypassed note',
                'gender' => 'female',
                'addresses' => [
                    [
                        'street' => 'Hauptstrasse',
                        'house_number' => '42A',
                        'postal_code' => '10115',
                        'city' => 'Berlin',
                        'country' => 'DE',
                        'resided_from' => '2024-01-01',
                    ],
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'bwr_status' => 'BWR fields must be changed via the dedicated BWR status endpoint.',
                'bwr_registered_at',
                'bwr_submission_date' => 'BWR submission date can only be set when updating an existing employee via PATCH.',
                'bwr_notes' => 'BWR fields must be changed via the dedicated BWR status endpoint.',
            ]);

        $this->assertDatabaseMissing('employees', [
            'email' => 'bianca.bypass@example.com',
        ]);
    });
});

describe('GET /v1/employees/{employee}', function () {
    test('returns 401 when not authenticated', function (): void {
        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
        ]);

        $response = $this->getJson("/v1/employees/{$employee->id}");
        $response->assertStatus(401);
    });

    test('returns 403 when user lacks employee.read permission', function (): void {
        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/v1/employees/{$employee->id}");

        $response->assertStatus(403);
    });

    test('returns employee with relationships', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.read');

        // We intentionally create two non-overlapping rank scopes (ADR-009):
        // one for Guards (0-0) and one for Leadership (1-255). A single scope
        // cannot model both cohorts without either excluding one group or broadening access.

        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'position' => 'Test Position',
            'management_level' => 3,
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/v1/employees/{$employee->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'employee_number',
                    'first_name',
                    'last_name',
                    'email',
                    'status',
                    'position',
                    'management_level',
                    'user',
                    'legal_entity_id',
                    'establishment_id',
                ],
            ])
            ->assertJson([
                'data' => [
                    'position' => 'Test Position',
                    'management_level' => 3,
                ],
            ]);
    });

    test('includes side resources only with their dedicated read permissions', function (bool $canReadSideResources): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.read');
        if ($canReadSideResources) {
            givePermissionWithTenant($this->user, $this->tenant->id, 'employee_document.read');
            givePermissionWithTenant($this->user, $this->tenant->id, 'employee_qualification.read');
        }

        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
        ]);
        $document = EmployeeDocument::factory()->create([
            'employee_id' => $employee->id,
            'uploaded_by' => $this->user->id,
        ]);
        $employeeQualification = EmployeeQualification::factory()->create([
            'employee_id' => $employee->id,
            'qualification_id' => Qualification::factory()->create([
                'tenant_id' => $this->tenant->id,
            ])->id,
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/v1/employees/{$employee->id}")
            ->assertOk();

        if ($canReadSideResources) {
            $response->assertJsonPath('data.documents.0.id', $document->id)
                ->assertJsonPath('data.qualifications.0.id', $employeeQualification->id);

            return;
        }

        $response->assertJsonMissingPath('data.documents')
            ->assertJsonMissingPath('data.qualifications');
    })->with([false, true]);

    test('omits sensitive identifiers for managers without employees.read_sensitive', function (): void {
        giveRoleWithTenant($this->user, $this->tenant->id, 'Manager');

        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'management_level' => 0,
            'tax_id' => '12345678901',
            'social_security_number' => '65 123456 A 123',
            'id_document_number' => 'L01X00T47',
            'health_insurance_number' => 'AOK123456789',
            'work_permit_number' => 'WP-123456',
            'residence_permit_number' => 'RP-123456',
            'sachkunde_ihk_number' => 'IHK-123456',
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/v1/employees/{$employee->id}");

        $response->assertOk();
        expect($response->json('data'))->not->toHaveKeys([
            'tax_id',
            'social_security_number',
            'id_document_number',
            'health_insurance_number',
            'work_permit_number',
            'residence_permit_number',
            'sachkunde_ihk_number',
        ]);
    });

    test('returns sensitive identifiers for HR users with employees.read_sensitive', function (): void {
        giveRoleWithTenant($this->user, $this->tenant->id, 'HR');

        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'management_level' => 0,
            'tax_id' => '12345678901',
            'social_security_number' => '65 123456 A 123',
            'id_document_number' => 'L01X00T47',
            'health_insurance_number' => 'AOK123456789',
            'work_permit_number' => 'WP-123456',
            'residence_permit_number' => 'RP-123456',
            'sachkunde_ihk_number' => 'IHK-123456',
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/v1/employees/{$employee->id}");

        $response->assertOk()
            ->assertJsonPath('data.tax_id', '12345678901')
            ->assertJsonPath('data.social_security_number', '65 123456 A 123')
            ->assertJsonPath('data.id_document_number', 'L01X00T47')
            ->assertJsonPath('data.health_insurance_number', 'AOK123456789')
            ->assertJsonPath('data.work_permit_number', 'WP-123456')
            ->assertJsonPath('data.residence_permit_number', 'RP-123456')
            ->assertJsonPath('data.sachkunde_ihk_number', 'IHK-123456');
    });

    test('omits salary fields without employees.read_salary', function (): void {
        $user = User::factory()->create();
        $token = $user->createToken('test-device')->plainTextToken;
        givePermissionWithTenant($user, $this->tenant->id, 'employee.read');

        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'management_level' => 0,
            'hourly_rate' => '29.75',
        ]);

        $response = $this->withToken($token)
            ->getJson("/v1/employees/{$employee->id}");

        $response->assertOk();
        expect($response->json('data'))->not->toHaveKey('hourly_rate');
    });

    test('returns 404 when user tries to access employee from different tenant', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.read');

        $otherTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
        $employee = Employee::factory()->create([
            'tenant_id' => $otherTenant->id,
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/v1/employees/{$employee->id}");

        $response->assertNotFound();
    });

    test('returns 404 for invalid employee id format', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.read');

        $response = $this->withToken($this->token)
            ->getJson('/v1/employees/1');

        $response->assertNotFound()
            ->assertExactJson([
                'message' => 'Resource not found.',
            ]);
    });
});

describe('PATCH /v1/employees/{employee}', function () {
    test('allows an unchanged closed organizational unit in an employee update', function (bool $isDeleted): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'weekly_hours' => 40,
        ]);
        $isDeleted ? $this->organizationalUnit->delete() : $this->organizationalUnit->update(['is_assignable' => false]);

        $response = $this->withToken($this->token)
            ->patchJson("/v1/employees/{$employee->id}", [
                'legal_entity_id' => $this->legalEntity->id,
                'establishment_id' => $this->establishment->id,
                'weekly_hours' => 35,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.weekly_hours', '35.00');
    })->with(['deleted' => true, 'non-assignable' => false]);

    test('returns 401 when not authenticated', function (): void {
        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
        ]);

        $response = $this->patchJson("/v1/employees/{$employee->id}", [
            'weekly_hours' => 35,
        ]);

        $response->assertStatus(401);
    });

    test('returns 403 when user lacks employee.write permission', function (): void {
        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/v1/employees/{$employee->id}", [
                'weekly_hours' => 35,
            ]);

        $response->assertStatus(403);
    });

    test('updates employee with valid data', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        // Need TWO scopes: 0-0 for Guards + 1-255 for Leadership (ADR-009)

        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'weekly_hours' => 40,
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/v1/employees/{$employee->id}", [
                'weekly_hours' => 35,
            ]);

        $response->assertStatus(200);
        expect($response->json('data.weekly_hours'))->toBe('35.00'); // decimal:2 cast returns string
    });

    test('rejects salary updates without employees.read_salary permission', function (): void {
        $user = User::factory()->create();
        $token = $user->createToken('test-device')->plainTextToken;
        givePermissionWithTenant($user, $this->tenant->id, 'employee.write');

        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'hourly_rate' => 15.50,
        ]);

        $response = $this->withToken($token)
            ->patchJson("/v1/employees/{$employee->id}", [
                'hourly_rate' => 19.75,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['hourly_rate']);

        expect($employee->fresh()->hourly_rate)->toBe(15.5);
    });

    test('rejects blank address rows without deleting existing address history', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $employee = Employee::factory()->withAddressHistory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
        ]);

        $existingAddressIds = $employee->addresses()->orderBy('id')->pluck('id')->all();

        $response = $this->withToken($this->token)
            ->patchJson("/v1/employees/{$employee->id}", [
                'addresses' => [
                    [
                        'street' => '',
                        'house_number' => '',
                        'postal_code' => '',
                        'city' => '',
                        'supplement' => '',
                        'country' => null,
                        'resided_from' => null,
                        'resided_until' => null,
                    ],
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['addresses.0']);

        expect($employee->addresses()->orderBy('id')->pluck('id')->all())
            ->toBe($existingAddressIds);
    });

    test('treats addresses null as a no-op for existing address history', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $employee = Employee::factory()->withAddressHistory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
        ]);

        $existingAddressIds = $employee->addresses()->orderBy('id')->pluck('id')->all();

        expect($existingAddressIds)->not->toBeEmpty();

        $response = $this->withToken($this->token)
            ->patchJson("/v1/employees/{$employee->id}", [
                'addresses' => null,
            ]);

        $response->assertOk();

        expect($employee->fresh()->addresses()->orderBy('id')->pluck('id')->all())
            ->toBe($existingAddressIds);
    });

    test('rejects an address row where all fields are empty', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
        ]);

        $existingCount = $employee->addresses()->count();

        $response = $this->withToken($this->token)
            ->patchJson("/v1/employees/{$employee->id}", [
                'addresses' => [
                    ['street' => null, 'house_number' => null, 'postal_code' => null, 'city' => null, 'supplement' => null, 'country' => null, 'resided_from' => null, 'resided_until' => null],
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['addresses.0']);

        expect($employee->fresh()->addresses()->count())->toBe($existingCount);
    });

    test('rejects an address row where all fields are blank strings', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
        ]);

        $existingCount = $employee->addresses()->count();

        $response = $this->withToken($this->token)
            ->patchJson("/v1/employees/{$employee->id}", [
                'addresses' => [
                    ['street' => '   ', 'house_number' => '', 'postal_code' => '', 'city' => '', 'supplement' => '', 'country' => null, 'resided_from' => null, 'resided_until' => null],
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['addresses.0']);

        expect($employee->fresh()->addresses()->count())->toBe($existingCount);
    });

    test('rejects addresses null for employees that require a current BWR address', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $employee = Employee::factory()->withAddressHistory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'bwr_status' => 'pending',
        ]);

        $existingAddressIds = $employee->addresses()->orderBy('id')->pluck('id')->all();

        $response = $this->withToken($this->token)
            ->patchJson("/v1/employees/{$employee->id}", [
                'addresses' => null,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['addresses']);

        expect($employee->fresh()->addresses()->orderBy('id')->pluck('id')->all())
            ->toBe($existingAddressIds);
    });

    test('rejects direct status changes via patch', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'status' => Employee::STATUS_ACTIVE,
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/v1/employees/{$employee->id}", [
                'status' => Employee::STATUS_TERMINATED,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        expect($employee->fresh()->status)->toBe(Employee::STATUS_ACTIVE);
    });

    test('rejects null status via patch', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'status' => Employee::STATUS_ACTIVE,
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/v1/employees/{$employee->id}", [
                'status' => null,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        expect($employee->fresh()->status)->toBe(Employee::STATUS_ACTIVE);
    });

    test('rejects direct bwr field changes via patch and preserves audit trail invariants', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'bwr_status' => 'pending',
            'bwr_id' => null,
            'bwr_notes' => null,
            'bwr_registered_at' => null,
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/v1/employees/{$employee->id}", [
                'bwr_status' => 'active',
                'bwr_id' => '1234567',
                'bwr_notes' => 'Attempted bypass',
                'bwr_registered_at' => now()->toDateString(),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'bwr_status' => 'BWR fields must be changed via the dedicated BWR status endpoint.',
                'bwr_id',
                'bwr_notes',
                'bwr_registered_at',
            ]);

        $employee->refresh();

        expect($employee->bwr_status)->toBe('pending')
            ->and($employee->bwr_id)->toBeNull()
            ->and($employee->bwr_notes)->toBeNull()
            ->and($employee->bwr_registered_at)->toBeNull();

        $this->assertDatabaseMissing('activity_log', [
            'description' => 'BWR status updated',
            'subject_type' => Employee::class,
            'subject_id' => $employee->id,
        ]);
    });

    test('rejects direct retention field changes via patch', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'employment_end_date' => null,
            'retention_period_end' => null,
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/v1/employees/{$employee->id}", [
                'employment_end_date' => now()->toDateString(),
                'retention_period_end' => now()->addYears(3)->endOfYear()->toDateString(),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'employment_end_date' => 'Retention fields are managed by the employee lifecycle and cannot be written directly.',
                'retention_period_end',
            ]);

        $employee->refresh();

        expect($employee->employment_end_date)->toBeNull()
            ->and($employee->retention_period_end)->toBeNull();
    });
});

describe('POST /v1/employees/{employee}/bwr/export', function (): void {
    test('returns 401 when not authenticated', function (): void {
        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
        ]);

        $response = $this->postJson("/v1/employees/{$employee->id}/bwr/export", [
            'format' => 'csv',
        ]);

        $response->assertStatus(401);
    });

    test('returns 403 when user lacks employee.write permission', function (): void {
        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/v1/employees/{$employee->id}/bwr/export", [
                'format' => 'csv',
            ]);

        $response->assertStatus(403);
    });

    test('exports a bwr-ready employee and transitions status to pending', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'first_name' => 'Taylor',
            'last_name' => 'Export',
            'date_of_birth' => '1990-01-15',
            'gender' => 'female',
            'birth_name' => 'Taylor Birthname',
            'previous_names' => ['Taylor Previous'],
            'birth_city' => 'Berlin',
            'birth_country' => 'DE',
            'nationalities' => ['DE'],
            'intended_activities' => ['object_protection'],
            'id_document_type' => 'id_card',
            'id_document_number' => 'L01X00T47',
            'id_document_expiry' => now()->addYear()->toDateString(),
            'sachkunde_type' => '34a_new',
            'sachkunde_certificate' => 'IHK-123456',
            'bwr_status' => 'not_registered',
            'status' => Employee::STATUS_PRE_CONTRACT,
            'position' => 'Security Guard',
            'contract_type' => 'full_time',
            'contract_start_date' => now()->toDateString(),
            'management_level' => 0,
            'work_permit_type' => 'none',
        ]);

        $employee->addresses()->delete();

        EmployeeAddress::factory()->create([
            'employee_id' => $employee->id,
            'tenant_id' => $this->tenant->id,
            'street' => 'Altstrasse',
            'house_number' => '5',
            'postal_code' => '20095',
            'city' => 'Hamburg',
            'country' => 'DE',
            'resided_from' => '2021-01-01',
            'resided_until' => '2023-12-31',
        ]);

        EmployeeAddress::factory()->current()->create([
            'employee_id' => $employee->id,
            'tenant_id' => $this->tenant->id,
            'street' => 'Hauptstrasse',
            'house_number' => '42A',
            'postal_code' => '10115',
            'city' => 'Berlin',
            'country' => 'DE',
            'resided_from' => '2024-01-01',
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/v1/employees/{$employee->id}/bwr/export", [
                'format' => 'csv',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.employee_id', $employee->id)
            ->assertJsonPath('data.format', 'csv');

        expect($response->json('data.download_url'))->toContain("/v1/employees/{$employee->id}/bwr/exports/")
            ->and($employee->fresh()->bwr_status)->toBe('pending')
            ->and($employee->fresh()->bwr_submission_date)->not->toBeNull();

        $this->assertDatabaseHas('activity_log', [
            'description' => 'BWR export generated',
            'subject_type' => Employee::class,
            'subject_id' => $employee->id,
            'causer_type' => User::class,
            'causer_id' => $this->user->id,
        ]);

        $activity = Activity::query()
            ->where('subject_type', Employee::class)
            ->where('subject_id', $employee->id)
            ->where('description', 'BWR export generated')
            ->latest()
            ->first();

        expect($activity)->not->toBeNull()
            ->and($activity?->properties?->get('file_path'))->toBeString()
            ->and($activity?->properties?->get('file_size_bytes'))->toBeInt()
            ->and($activity?->properties?->get('file_size_bytes'))->toBeGreaterThan(0);
    });

    test('returns 422 when employee is not ready for bwr export', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'bwr_status' => 'not_registered',
            'gender' => null,
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/v1/employees/{$employee->id}/bwr/export", [
                'format' => 'csv',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Employee is not ready for BWR export.');

        expect($response->json('errors'))->toContain('gender')
            ->and($response->json('error_messages'))->toContain(__('bwr_export.missing_fields.gender'))
            ->and($employee->fresh()->bwr_status)->toBe('not_registered');
    });

    test('returns locale-independent bwr export error codes under a German Accept-Language header', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'bwr_status' => 'not_registered',
            'gender' => null,
        ]);

        $response = $this->withToken($this->token)
            ->withHeaders(['Accept-Language' => 'de'])
            ->postJson("/v1/employees/{$employee->id}/bwr/export", [
                'format' => 'csv',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Der Mitarbeiter ist nicht bereit für den BWR-Export.');

        expect($response->json('errors'))->toContain('gender')
            ->and($response->json('error_messages'))->toContain('Das Geschlecht muss im Mitarbeiterprofil gesetzt sein.');
    });

    test('returns 422 when employee already left not_registered status', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'bwr_status' => 'pending',
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/v1/employees/{$employee->id}/bwr/export", [
                'format' => 'csv',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'BWR export is only available for employees with status not_registered.');
    });

    test('returns 422 when export format is unsupported', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'bwr_status' => 'not_registered',
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/v1/employees/{$employee->id}/bwr/export", [
                'format' => 'pdf',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['format']);
    });

    test('exports a bwr-ready employee as xml', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'first_name' => 'Taylor',
            'last_name' => 'Export',
            'date_of_birth' => '1990-01-15',
            'gender' => 'female',
            'birth_name' => 'Taylor Birthname',
            'previous_names' => ['Taylor Previous'],
            'birth_city' => 'Berlin',
            'birth_country' => 'DE',
            'nationalities' => ['DE'],
            'intended_activities' => ['object_protection'],
            'id_document_type' => 'id_card',
            'id_document_number' => 'L01X00T47',
            'id_document_expiry' => now()->addYear()->toDateString(),
            'sachkunde_type' => '34a_new',
            'sachkunde_certificate' => 'IHK-123456',
            'bwr_status' => 'not_registered',
            'status' => Employee::STATUS_PRE_CONTRACT,
            'position' => 'Security Guard',
            'contract_type' => 'full_time',
            'contract_start_date' => now()->toDateString(),
            'management_level' => 0,
            'work_permit_type' => 'none',
        ]);

        $employee->addresses()->delete();

        EmployeeAddress::factory()->create([
            'employee_id' => $employee->id,
            'tenant_id' => $this->tenant->id,
            'street' => 'Altstrasse',
            'house_number' => '5',
            'postal_code' => '20095',
            'city' => 'Hamburg',
            'country' => 'DE',
            'resided_from' => '2021-01-01',
            'resided_until' => '2023-12-31',
        ]);

        EmployeeAddress::factory()->current()->create([
            'employee_id' => $employee->id,
            'tenant_id' => $this->tenant->id,
            'street' => 'Hauptstrasse',
            'house_number' => '42A',
            'postal_code' => '10115',
            'city' => 'Berlin',
            'country' => 'DE',
            'resided_from' => '2024-01-01',
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/v1/employees/{$employee->id}/bwr/export", [
                'format' => 'xml',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.employee_id', $employee->id)
            ->assertJsonPath('data.format', 'xml');

        $downloadPath = parse_url((string) $response->json('data.download_url'), PHP_URL_PATH);

        $downloadResponse = $this->withToken($this->token)->get($downloadPath);

        $downloadResponse->assertOk();
        expect($downloadResponse->headers->get('content-type'))->toContain('application/xml')
            ->and((string) $downloadResponse->headers->get('content-disposition'))->toContain('.xml')
            ->and($downloadResponse->getContent())->toContain('<bewacherregisterExport>');
    });

    test('completes the bwr workflow from export to activation with audit side effects', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        Mail::fake();
        Storage::fake('local');
        Storage::disk('local')->put('id_documents/bwr-end-to-end-test.pdf', 'test content');

        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'first_name' => 'Taylor',
            'last_name' => 'Workflow',
            'date_of_birth' => '1990-01-15',
            'gender' => 'female',
            'birth_name' => 'Taylor Birthname',
            'previous_names' => ['Taylor Previous'],
            'birth_city' => 'Berlin',
            'birth_country' => 'DE',
            'nationalities' => ['DE'],
            'intended_activities' => ['object_protection'],
            'id_document_type' => 'id_card',
            'id_document_number' => 'L01X00T47',
            'id_document_expiry' => now()->addYear()->toDateString(),
            'id_document_copy_path' => 'id_documents/bwr-end-to-end-test.pdf',
            'id_document_copy_deleted_at' => null,
            'sachkunde_type' => '34a_new',
            'sachkunde_certificate' => 'IHK-123456',
            'bwr_status' => 'not_registered',
            'bwr_submission_date' => null,
            'bwr_registered_at' => null,
            'status' => Employee::STATUS_PRE_CONTRACT,
            'position' => 'Security Guard',
            'contract_type' => 'full_time',
            'contract_start_date' => now()->toDateString(),
            'management_level' => 0,
            'work_permit_type' => 'none',
        ]);

        $employee->addresses()->delete();

        EmployeeAddress::factory()->create([
            'employee_id' => $employee->id,
            'tenant_id' => $this->tenant->id,
            'street' => 'Altstrasse',
            'house_number' => '5',
            'postal_code' => '20095',
            'city' => 'Hamburg',
            'country' => 'DE',
            'resided_from' => '2021-01-01',
            'resided_until' => '2023-12-31',
        ]);

        EmployeeAddress::factory()->current()->create([
            'employee_id' => $employee->id,
            'tenant_id' => $this->tenant->id,
            'street' => 'Hauptstrasse',
            'house_number' => '42A',
            'postal_code' => '10115',
            'city' => 'Berlin',
            'country' => 'DE',
            'resided_from' => '2024-01-01',
        ]);

        $exportResponse = $this->withToken($this->token)
            ->postJson("/v1/employees/{$employee->id}/bwr/export", [
                'format' => 'csv',
            ]);

        $exportResponse->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.format', 'csv');

        $employee->refresh();

        expect($employee->bwr_status)->toBe('pending')
            ->and($employee->bwr_submission_date)->not->toBeNull()
            ->and($employee->id_document_copy_deleted_at)->toBeNull()
            ->and(Storage::disk('local')->exists('id_documents/bwr-end-to-end-test.pdf'))->toBeTrue();

        $downloadPath = parse_url((string) $exportResponse->json('data.download_url'), PHP_URL_PATH);

        $this->withToken($this->token)
            ->get($downloadPath)
            ->assertOk();

        $activationResponse = $this->withToken($this->token)
            ->putJson("/v1/employees/{$employee->id}/bwr/status", [
                'status' => 'active',
                'bwr_id' => '1234567',
                'notes' => 'Approved after manual authority submission',
            ]);

        $activationResponse->assertOk()
            ->assertJsonPath('data.bwr_status', 'active')
            ->assertJsonPath('data.bwr_id', '1234567');

        $employee->refresh();

        expect($employee->bwr_status)->toBe('active')
            ->and($employee->bwr_id)->toBe('1234567')
            ->and($employee->bwr_notes)->toBe('Approved after manual authority submission')
            ->and($employee->bwr_submission_date)->not->toBeNull()
            ->and($employee->bwr_registered_at)->not->toBeNull()
            ->and($employee->id_document_copy_deleted_at)->not->toBeNull()
            ->and(Storage::disk('local')->exists('id_documents/bwr-end-to-end-test.pdf'))->toBeFalse();

        // Export file remains downloadable after activation (only the ID document copy is deleted)
        $this->withToken($this->token)
            ->get($downloadPath)
            ->assertOk();

        $exportActivity = Activity::query()
            ->where('subject_type', Employee::class)
            ->where('subject_id', $employee->id)
            ->where('description', 'BWR export generated')
            ->latest()
            ->first();

        $statusActivity = Activity::query()
            ->where('subject_type', Employee::class)
            ->where('subject_id', $employee->id)
            ->where('description', 'BWR status updated')
            ->latest()
            ->first();

        $deletionActivity = Activity::query()
            ->where('subject_type', Employee::class)
            ->where('subject_id', $employee->id)
            ->where('description', 'ID document copy automatically deleted (BWR active)')
            ->latest()
            ->first();

        expect($exportActivity)->not->toBeNull()
            ->and($exportActivity?->properties->get('old_bwr_status'))->toBe('not_registered')
            ->and($exportActivity?->properties->get('new_bwr_status'))->toBe('pending')
            ->and($exportActivity?->properties->get('file_path'))->toBeString();

        expect($statusActivity)->not->toBeNull()
            ->and($statusActivity?->properties->get('old_bwr_status'))->toBe('pending')
            ->and($statusActivity?->properties->get('new_bwr_status'))->toBe('active')
            ->and($statusActivity?->properties->get('bwr_id'))->toBe('1234567')
            ->and($statusActivity?->properties->get('notes'))->toBe('Approved after manual authority submission');

        expect($deletionActivity)->not->toBeNull()
            ->and($deletionActivity?->properties->get('action'))->toBe('id_document_auto_deleted')
            ->and($deletionActivity?->properties->get('bwr_status'))->toBe('active');

        Mail::assertQueued(BwrIdDocumentAutoDeletedMail::class, function (BwrIdDocumentAutoDeletedMail $mail) use ($employee): bool {
            return $mail->employee->is($employee);
        });
    });
});

describe('GET /v1/employees/{employee}/bwr/exports/{file}/download', function (): void {
    test('downloads a generated bwr export', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'first_name' => 'Taylor',
            'last_name' => 'Export',
            'date_of_birth' => '1990-01-15',
            'gender' => 'female',
            'birth_city' => 'Berlin',
            'birth_country' => 'DE',
            'nationalities' => ['DE'],
            'intended_activities' => ['object_protection'],
            'id_document_type' => 'id_card',
            'id_document_number' => 'L01X00T47',
            'id_document_expiry' => now()->addYear()->toDateString(),
            'sachkunde_type' => '34a_new',
            'sachkunde_certificate' => 'IHK-123456',
            'bwr_status' => 'not_registered',
            'status' => Employee::STATUS_PRE_CONTRACT,
            'position' => 'Security Guard',
            'contract_type' => 'full_time',
            'contract_start_date' => now()->toDateString(),
            'management_level' => 0,
            'work_permit_type' => 'none',
        ]);

        $employee->addresses()->delete();

        EmployeeAddress::factory()->create([
            'employee_id' => $employee->id,
            'tenant_id' => $this->tenant->id,
            'street' => 'Altstrasse',
            'house_number' => '5',
            'postal_code' => '20095',
            'city' => 'Hamburg',
            'country' => 'DE',
            'resided_from' => '2021-01-01',
            'resided_until' => '2023-12-31',
        ]);

        EmployeeAddress::factory()->current()->create([
            'employee_id' => $employee->id,
            'tenant_id' => $this->tenant->id,
            'street' => 'Hauptstrasse',
            'house_number' => '42A',
            'postal_code' => '10115',
            'city' => 'Berlin',
            'country' => 'DE',
            'resided_from' => '2024-01-01',
        ]);

        $exportResponse = $this->withToken($this->token)
            ->postJson("/v1/employees/{$employee->id}/bwr/export", [
                'format' => 'csv',
            ]);

        $downloadPath = parse_url((string) $exportResponse->json('data.download_url'), PHP_URL_PATH);

        $response = $this->withToken($this->token)->get($downloadPath);

        $response->assertOk();
        expect($response->headers->get('content-type'))->toContain('text/csv')
            ->and((string) $response->headers->get('content-disposition'))->toContain('.csv')
            ->and($response->getContent())->toContain('last_name;first_name;birth_name');
    });
});

describe('PUT /v1/employees/{employee}/bwr/status', function (): void {
    test('returns 401 when not authenticated', function (): void {
        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'bwr_status' => 'pending',
        ]);

        $response = $this->putJson("/v1/employees/{$employee->id}/bwr/status", [
            'status' => 'active',
            'bwr_id' => '1234567',
        ]);

        $response->assertStatus(401);
    });

    test('returns 403 when user lacks employee.write permission', function (): void {
        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'bwr_status' => 'pending',
        ]);

        $response = $this->withToken($this->token)
            ->putJson("/v1/employees/{$employee->id}/bwr/status", [
                'status' => 'active',
                'bwr_id' => '1234567',
            ]);

        $response->assertStatus(403);
    });

    test('returns 422 when activating without a bwr id', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'bwr_status' => 'pending',
        ]);

        $response = $this->withToken($this->token)
            ->putJson("/v1/employees/{$employee->id}/bwr/status", [
                'status' => 'active',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['bwr_id']);
    });

    test('returns 422 when bwr transition is not allowed', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'bwr_status' => 'not_registered',
        ]);

        $response = $this->withToken($this->token)
            ->putJson("/v1/employees/{$employee->id}/bwr/status", [
                'status' => 'active',
                'bwr_id' => '1234567',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'BWR status transition from not_registered to active is not allowed.');
    });

    test('activates a pending employee, persists bwr data, and logs the change', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        Storage::fake('local');
        Storage::disk('local')->put('id_documents/bwr-status-test.pdf', 'test content');

        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'bwr_status' => 'pending',
            'id_document_copy_path' => 'id_documents/bwr-status-test.pdf',
            'id_document_copy_deleted_at' => null,
            'bwr_registered_at' => null,
            'bwr_id' => null,
        ]);

        $response = $this->withToken($this->token)
            ->putJson("/v1/employees/{$employee->id}/bwr/status", [
                'status' => 'active',
                'bwr_id' => '1234567',
                'notes' => 'Approved by authority',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.bwr_status', 'active')
            ->assertJsonPath('data.bwr_id', '1234567');

        $employee->refresh();

        expect($employee->bwr_status)->toBe('active')
            ->and($employee->bwr_id)->toBe('1234567')
            ->and($employee->bwr_notes)->toBe('Approved by authority')
            ->and($employee->bwr_registered_at)->not->toBeNull()
            ->and($employee->id_document_copy_deleted_at)->not->toBeNull()
            ->and(Storage::disk('local')->exists('id_documents/bwr-status-test.pdf'))->toBeFalse();

        $activity = Activity::query()
            ->where('subject_type', Employee::class)
            ->where('subject_id', $employee->id)
            ->where('description', 'BWR status updated')
            ->latest()
            ->first();

        expect($activity)->not->toBeNull()
            ->and($activity?->properties->get('old_bwr_status'))->toBe('pending')
            ->and($activity?->properties->get('new_bwr_status'))->toBe('active')
            ->and($activity?->properties->get('bwr_id'))->toBe('1234567');
    });

    test('idempotent re-put with same status succeeds and updates notes', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'bwr_status' => 'active',
            'bwr_id' => '1234567',
            'bwr_registered_at' => now()->subDay(),
        ]);

        $response = $this->withToken($this->token)
            ->putJson("/v1/employees/{$employee->id}/bwr/status", [
                'status' => 'active',
                'notes' => 'Re-confirmed by authority',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.bwr_status', 'active');

        $employee->refresh();
        expect($employee->bwr_notes)->toBe('Re-confirmed by authority');
    });
});

describe('DELETE /v1/employees/{employee}', function () {
    test('returns 401 when not authenticated', function (): void {
        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
        ]);

        $response = $this->deleteJson("/v1/employees/{$employee->id}");
        $response->assertStatus(401);
    });

    test('returns 403 when user lacks employee.write permission', function (): void {
        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
        ]);

        $response = $this->withToken($this->token)
            ->deleteJson("/v1/employees/{$employee->id}");

        $response->assertStatus(403);
    });

    test('soft deletes employee with valid permission', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
        ]);

        $response = $this->withToken($this->token)
            ->deleteJson("/v1/employees/{$employee->id}");

        $response->assertNoContent();
        expect(Employee::withTrashed()->find($employee->id)->deleted_at)->not->toBeNull();
    });

    test('returns 403 for deletion when the actor has an organizational scope', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');
        $this->user->organizationalScopes()->delete();
        grantDualManagementScopes($this->user, $this->organizationalUnit->id);
        $this->user->organizationalScopes()->create([
            'organizational_unit_id' => $this->organizationalUnit->id,
            'access_level' => 'manage',
            'include_descendants' => false,
            'min_viewable_rank' => 0,
            'max_viewable_rank' => 0,
            'min_assignable_rank' => 0,
            'max_assignable_rank' => 0,
            'allow_self_access' => true,
        ]);

        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'management_level' => 3,
        ]);

        $this->withToken($this->token)
            ->deleteJson("/v1/employees/{$employee->id}")
            ->assertForbidden();
    });

    test('deleting an employee securely deprovisions and removes the linked user account', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $linkedUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'status' => Employee::STATUS_PRE_CONTRACT,
            'user_id' => $linkedUser->id,
            'user_account_active' => true,
        ]);

        expect($linkedUser)->toBeInstanceOf(User::class);

        $linkedUser->createToken('employee-delete-test');

        DB::table('sessions')->insert([
            'id' => 'employee-delete-session',
            'user_id' => $linkedUser->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Pest',
            'payload' => base64_encode('test'),
            'last_activity' => now()->timestamp,
        ]);

        $response = $this->withToken($this->token)
            ->deleteJson("/v1/employees/{$employee->id}");

        $response->assertNoContent();

        expect(Employee::withTrashed()->find($employee->id)->deleted_at)->not->toBeNull()
            ->and(User::query()->find($linkedUser->id))->toBeNull()
            ->and(DB::table('personal_access_tokens')->where('tokenable_id', $linkedUser->id)->count())->toBe(0)
            ->and(DB::table('sessions')->where('user_id', $linkedUser->id)->count())->toBe(0);
    });
});

describe('POST /v1/employees/{employee}/activate', function () {
    test('returns 401 when not authenticated', function (): void {
        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'status' => Employee::STATUS_PRE_CONTRACT,
        ]);

        $response = $this->postJson("/v1/employees/{$employee->id}/activate");
        $response->assertStatus(401);
    });

    test('returns 403 when user lacks employee.write permission', function (): void {
        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'status' => Employee::STATUS_PRE_CONTRACT,
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/v1/employees/{$employee->id}/activate");

        $response->assertStatus(403);
    });

    test('activates pre-contract employee with valid conditions', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'status' => Employee::STATUS_PRE_CONTRACT,
            'onboarding_completed' => true,
            'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_READY_FOR_ACTIVATION,
            'contract_start_date' => now()->subDay()->toDateString(),
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/v1/employees/{$employee->id}/activate");

        $response->assertStatus(200);
        expect($response->json('data.status'))->toBe(Employee::STATUS_ACTIVE);
        expect($response->json('data.onboarding_workflow.status'))->toBe(Employee::WORKFLOW_STATUS_ACTIVE);
    });

    test('returns 403 for activation when the actor has an organizational scope', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');
        $this->user->organizationalScopes()->delete();
        grantDualManagementScopes($this->user, $this->organizationalUnit->id);

        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'management_level' => 3,
            'status' => Employee::STATUS_PRE_CONTRACT,
            'onboarding_completed' => true,
            'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_READY_FOR_ACTIVATION,
            'contract_start_date' => now()->subDay()->toDateString(),
        ]);

        $this->withToken($this->token)
            ->postJson("/v1/employees/{$employee->id}/activate")
            ->assertForbidden();
    });

    test('returns 422 when onboarding not completed', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'status' => Employee::STATUS_PRE_CONTRACT,
            'onboarding_completed' => false,
            'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_READY_FOR_ACTIVATION,
            'contract_start_date' => now()->subDay()->toDateString(),
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/v1/employees/{$employee->id}/activate");

        $response->assertStatus(422);
    });

    test('returns 422 when onboarding workflow is not ready for activation', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'status' => Employee::STATUS_PRE_CONTRACT,
            'onboarding_completed' => true,
            'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_CONTRACT_CONFIRMED,
            'contract_start_date' => now()->subDay()->toDateString(),
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/v1/employees/{$employee->id}/activate");

        $response->assertStatus(422);
    });

    test('returns 422 when contract_start_date is future', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'status' => Employee::STATUS_PRE_CONTRACT,
            'onboarding_completed' => true,
            'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_READY_FOR_ACTIVATION,
            'contract_start_date' => now()->addWeek()->toDateString(),
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/v1/employees/{$employee->id}/activate");

        $response->assertStatus(422);
    });

    test('returns 422 when employee has no linked user account', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'status' => Employee::STATUS_PRE_CONTRACT,
            'onboarding_completed' => true,
            'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_READY_FOR_ACTIVATION,
            'contract_start_date' => now()->subDay()->toDateString(),
        ]);

        $employee->updateQuietly([
            'user_id' => null,
            'user_account_active' => false,
            'user_account_activated_at' => null,
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/v1/employees/{$employee->id}/activate");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['employee']);
    });
});

describe('POST /v1/employees/{employee}/terminate', function () {
    test('returns 401 when not authenticated', function (): void {
        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'status' => Employee::STATUS_ACTIVE,
        ]);

        $response = $this->postJson("/v1/employees/{$employee->id}/terminate", [
            'termination_date' => now()->toDateString(),
        ]);

        $response->assertStatus(401);
    });

    test('returns 403 when user lacks employee.write permission', function (): void {
        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'status' => Employee::STATUS_ACTIVE,
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/v1/employees/{$employee->id}/terminate", [
                'termination_date' => now()->toDateString(),
            ]);

        $response->assertStatus(403);
    });

    test('terminates active employee with valid data', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'status' => Employee::STATUS_ACTIVE,
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/v1/employees/{$employee->id}/terminate", [
                'termination_date' => now()->toDateString(),
                'termination_reason' => 'resignation',
            ]);

        $response->assertStatus(200);
        expect($response->json('data.status'))->toBe(Employee::STATUS_TERMINATED);
    });

    test('returns 403 for termination when the actor has an organizational scope', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');
        $this->user->organizationalScopes()->delete();
        grantDualManagementScopes($this->user, $this->organizationalUnit->id);

        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'management_level' => 3,
            'status' => Employee::STATUS_ACTIVE,
        ]);

        $this->withToken($this->token)
            ->postJson("/v1/employees/{$employee->id}/terminate", [
                'termination_date' => now()->toDateString(),
                'termination_reason' => 'resignation',
            ])
            ->assertForbidden();
    });

    test('returns 422 when terminating pre-contract employee', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'status' => Employee::STATUS_PRE_CONTRACT,
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/v1/employees/{$employee->id}/terminate", [
                'termination_date' => now()->toDateString(),
            ]);

        $response->assertStatus(422);
    });
});

describe('POST /v1/employees/{employee}/leave', function () {
    test('returns 401 when not authenticated', function (): void {
        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'status' => Employee::STATUS_ACTIVE,
        ]);

        $response = $this->postJson("/v1/employees/{$employee->id}/leave");
        $response->assertStatus(401);
    });

    test('returns 403 when user lacks employee.write permission', function (): void {
        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'status' => Employee::STATUS_ACTIVE,
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/v1/employees/{$employee->id}/leave");

        $response->assertStatus(403);
    });

    test('places an active employee on leave with read-only runtime access', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'status' => Employee::STATUS_PRE_CONTRACT,
            'onboarding_completed' => true,
            'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_READY_FOR_ACTIVATION,
            'contract_start_date' => now()->subDay()->toDateString(),
        ]);

        $this->withToken($this->token)
            ->postJson("/v1/employees/{$employee->id}/activate")
            ->assertStatus(200);

        $response = $this->withToken($this->token)
            ->postJson("/v1/employees/{$employee->id}/leave");

        $response->assertStatus(200);
        expect($response->json('data.status'))->toBe(Employee::STATUS_ON_LEAVE);
        expect($employee->fresh()?->user?->hasRole('Employee Read Only'))->toBeTrue();
        expect($employee->fresh()?->user?->hasRole('Employee'))->toBeFalse();
    });

    test('returns 422 when employee is not active', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'status' => Employee::STATUS_PRE_CONTRACT,
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/v1/employees/{$employee->id}/leave");

        $response->assertStatus(422);
    });
});

describe('POST /v1/employees/{employee}/return-from-leave', function () {
    test('returns 401 when not authenticated', function (): void {
        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'status' => Employee::STATUS_ON_LEAVE,
        ]);

        $response = $this->postJson("/v1/employees/{$employee->id}/return-from-leave");
        $response->assertStatus(401);
    });

    test('returns 403 when user lacks employee.write permission', function (): void {
        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'status' => Employee::STATUS_ON_LEAVE,
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/v1/employees/{$employee->id}/return-from-leave");

        $response->assertStatus(403);
    });

    test('restores the prior employee role when returning from leave', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'status' => Employee::STATUS_PRE_CONTRACT,
            'onboarding_completed' => true,
            'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_READY_FOR_ACTIVATION,
            'contract_start_date' => now()->subDay()->toDateString(),
        ]);

        $this->withToken($this->token)
            ->postJson("/v1/employees/{$employee->id}/activate")
            ->assertStatus(200);

        $this->withToken($this->token)
            ->postJson("/v1/employees/{$employee->id}/leave")
            ->assertStatus(200);

        $response = $this->withToken($this->token)
            ->postJson("/v1/employees/{$employee->id}/return-from-leave");

        $response->assertStatus(200);
        expect($response->json('data.status'))->toBe(Employee::STATUS_ACTIVE);
        expect($employee->fresh()?->user?->hasRole('Employee'))->toBeTrue();
        expect($employee->fresh()?->user?->hasRole('Employee Read Only'))->toBeFalse();
    });

    test('returns 422 when employee is not on leave', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');

        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'legal_entity_id' => $this->legalEntity->id,
            'establishment_id' => $this->establishment->id,
            'status' => Employee::STATUS_ACTIVE,
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/v1/employees/{$employee->id}/return-from-leave");

        $response->assertStatus(422);
    });
});

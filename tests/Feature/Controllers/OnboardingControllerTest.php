<?php

/*
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use App\Models\Employee;
use App\Models\OnboardingFormSubmission;
use App\Models\OnboardingFormTemplate;
use App\Models\OrganizationalUnit;
use App\Models\Permission;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * @property TenantKey $tenant
 * @property User $user
 * @property mixed $token
 * @property Employee $employee
 * @property OnboardingFormTemplate $template
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('test-device')->plainTextToken;

    Permission::firstOrCreate(['name' => 'onboarding.read', 'guard_name' => 'sanctum']);
    Permission::firstOrCreate(['name' => 'onboarding.write', 'guard_name' => 'sanctum']);
    Permission::firstOrCreate(['name' => 'onboarding.approve', 'guard_name' => 'sanctum']);

    $organizationalUnit = OrganizationalUnit::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $this->employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $organizationalUnit->id,
        'user_id' => $this->user->id,
        'status' => Employee::STATUS_PRE_CONTRACT,
        'onboarding_steps' => [
            ['step' => 'personal_info', 'completed' => false],
            ['step' => 'documents', 'completed' => false],
        ],
    ]);

    $this->template = OnboardingFormTemplate::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

describe('GET /v1/onboarding/steps', function () {
    test('returns 401 when not authenticated', function (): void {
        $response = $this->getJson('/v1/onboarding/steps');
        $response->assertStatus(401);
    });

    test('returns 403 when user lacks onboarding.read permission', function (): void {
        $response = $this->withToken($this->token)
            ->getJson('/v1/onboarding/steps');

        $response->assertStatus(403);
    });

    test('returns onboarding steps for pre-contract employee', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.read');

        $response = $this->withToken($this->token)
            ->getJson('/v1/onboarding/steps');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'employee_id',
                    'status',
                    'onboarding_steps' => [
                        '*' => ['step', 'completed'],
                    ],
                ],
            ]);

        expect($response->json('data.status'))->toBe(Employee::STATUS_PRE_CONTRACT);
    });

    test('returns 403 when employee is not pre-contract', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.read');

        $this->employee->update(['status' => Employee::STATUS_ACTIVE]);

        $response = $this->withToken($this->token)
            ->getJson('/v1/onboarding/steps');

        $response->assertStatus(403);
    });

    test('returns 403 when user has no employee record', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.read');

        $otherUser = User::factory()->create();
        $otherToken = $otherUser->createToken('test-device')->plainTextToken;

        $response = $this->withToken($otherToken)
            ->getJson('/v1/onboarding/steps');

        $response->assertStatus(403);
    });
});

describe('GET /v1/onboarding/templates', function () {
    test('returns 401 when not authenticated', function (): void {
        $response = $this->getJson('/v1/onboarding/templates');
        $response->assertStatus(401);
    });

    test('returns 403 when user lacks onboarding.read permission', function (): void {
        $response = $this->withToken($this->token)
            ->getJson('/v1/onboarding/templates');

        $response->assertStatus(403);
    });

    test('returns system and tenant templates', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.read');

        OnboardingFormTemplate::factory()->create([
            'tenant_id' => null,
            'is_system_template' => true,
            'name' => 'System Template',
        ]);

        OnboardingFormTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_system_template' => false,
            'name' => 'Tenant Template',
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/v1/onboarding/templates');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'description', 'is_required', 'is_system_template'],
                ],
            ]);

        expect($response->json('data'))->toHaveCount(3); // 2 created + 1 in beforeEach
    });
});

describe('GET /v1/onboarding/templates/{template}', function () {
    test('returns 401 when not authenticated', function (): void {
        $response = $this->getJson("/v1/onboarding/templates/{$this->template->id}");
        $response->assertStatus(401);
    });

    test('returns 403 when user lacks onboarding.read permission', function (): void {
        $response = $this->withToken($this->token)
            ->getJson("/v1/onboarding/templates/{$this->template->id}");

        $response->assertStatus(403);
    });

    test('returns template details with form_schema', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.read');

        $response = $this->withToken($this->token)
            ->getJson("/v1/onboarding/templates/{$this->template->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'description',
                    'form_schema',
                    'is_required',
                ],
            ]);
    });
});

describe('GET /v1/onboarding/submissions', function () {
    test('returns 401 when not authenticated', function (): void {
        $response = $this->getJson('/v1/onboarding/submissions');
        $response->assertStatus(401);
    });

    test('returns 403 when user lacks onboarding.read permission', function (): void {
        $response = $this->withToken($this->token)
            ->getJson('/v1/onboarding/submissions');

        $response->assertStatus(403);
    });

    test('returns employee own submissions', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.read');

        OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $this->template->id,
        ]);

        // Create submission for different employee (should not be returned)
        $otherEmployee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => $this->employee->organizational_unit_id,
        ]);

        OnboardingFormSubmission::factory()->create([
            'employee_id' => $otherEmployee->id,
            'form_template_id' => $this->template->id,
        ]);

        $response = $this->withToken($this->token)
            ->getJson('/v1/onboarding/submissions');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'employee_id', 'form_template_id', 'status'],
                ],
            ]);

        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data')[0]['employee_id'])->toBe($this->employee->id);
    });
});

describe('POST /v1/onboarding/submissions', function () {
    test('returns 401 when not authenticated', function (): void {
        $response = $this->postJson('/v1/onboarding/submissions', [
            'form_template_id' => $this->template->id,
            'form_data' => ['field' => 'value'],
        ]);

        $response->assertStatus(401);
    });

    test('returns 403 when user lacks onboarding.write permission', function (): void {
        $response = $this->withToken($this->token)
            ->postJson('/v1/onboarding/submissions', [
                'form_template_id' => $this->template->id,
                'form_data' => ['field' => 'value'],
                'status' => 'draft',
            ]);

        $response->assertStatus(403);
    });

    test('returns 422 when required fields are missing', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        $response = $this->withToken($this->token)
            ->postJson('/v1/onboarding/submissions', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['form_template_id', 'form_data']);
    });

    test('creates new submission with draft status', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        $response = $this->withToken($this->token)
            ->postJson('/v1/onboarding/submissions', [
                'form_template_id' => $this->template->id,
                'form_data' => ['name' => 'John Doe', 'email' => 'john@example.com'],
                'status' => 'draft',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'employee_id',
                    'form_template_id',
                    'form_data',
                    'status',
                ],
            ]);

        expect($response->json('data.status'))->toBe('draft');
        expect($response->json('data.submitted_at'))->toBeNull();
    });

    test('creates submission with submitted status and timestamp', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        $response = $this->withToken($this->token)
            ->postJson('/v1/onboarding/submissions', [
                'form_template_id' => $this->template->id,
                'form_data' => ['name' => 'Jane Smith'],
                'status' => 'submitted',
            ]);

        $response->assertStatus(201);
        expect($response->json('data.status'))->toBe('submitted');
        expect($response->json('data.submitted_at'))->not->toBeNull();
    });

    test('updates existing draft submission', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $this->template->id,
            'form_data' => ['name' => 'Original'],
            'status' => 'draft',
        ]);

        $response = $this->withToken($this->token)
            ->postJson('/v1/onboarding/submissions', [
                'form_template_id' => $this->template->id,
                'form_data' => ['name' => 'Updated'],
                'status' => 'draft',
            ]);

        $response->assertStatus(200);
        expect($response->json('data.id'))->toBe($submission->id);
        expect($response->json('data.form_data')['name'])->toBe('Updated');
    });

    test('does not update already submitted submission', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $this->template->id,
            'status' => 'submitted',
        ]);

        $response = $this->withToken($this->token)
            ->postJson('/v1/onboarding/submissions', [
                'form_template_id' => $this->template->id,
                'form_data' => ['name' => 'Attempt Update'],
                'status' => 'draft',
            ]);

        $response->assertStatus(409)
            ->assertJson(['message' => 'Form has already been submitted and is awaiting review']);
        expect(OnboardingFormSubmission::where('employee_id', $this->employee->id)->count())->toBe(1);
    });
});

describe('POST /v1/admin/onboarding/submissions/{submission}/approve', function () {
    test('returns 401 when not authenticated', function (): void {
        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $this->template->id,
            'status' => 'submitted',
        ]);

        $response = $this->postJson("/v1/admin/onboarding/submissions/{$submission->id}/approve");
        $response->assertStatus(401);
    });

    test('returns 403 when user lacks onboarding.approve permission', function (): void {
        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $this->template->id,
            'status' => 'submitted',
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/v1/admin/onboarding/submissions/{$submission->id}/approve");

        $response->assertStatus(403);
    });

    test('approves submitted submission with valid permission', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.approve');

        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $this->template->id,
            'status' => 'submitted',
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/v1/admin/onboarding/submissions/{$submission->id}/approve");

        $response->assertStatus(200);
        expect($response->json('data.status'))->toBe('approved');
        expect($response->json('data.reviewed_by'))->toBe($this->user->id);
        expect($response->json('data.reviewed_at'))->not->toBeNull();
    });

    test('returns 422 when attempting to approve non-submitted submission', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.approve');

        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $this->template->id,
            'status' => 'draft',
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/v1/admin/onboarding/submissions/{$submission->id}/approve");

        $response->assertStatus(422);
    });
});

describe('POST /v1/admin/onboarding/submissions/{submission}/reject', function () {
    test('returns 401 when not authenticated', function (): void {
        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $this->template->id,
            'status' => 'submitted',
        ]);

        $response = $this->postJson("/v1/admin/onboarding/submissions/{$submission->id}/reject", [
            'reason' => 'Incomplete information',
        ]);

        $response->assertStatus(401);
    });

    test('returns 403 when user lacks onboarding.approve permission', function (): void {
        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $this->template->id,
            'status' => 'submitted',
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/v1/admin/onboarding/submissions/{$submission->id}/reject", [
                'reason' => 'Incomplete information',
            ]);

        $response->assertStatus(403);
    });

    test('returns 422 when reason is missing', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.approve');

        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $this->template->id,
            'status' => 'submitted',
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/v1/admin/onboarding/submissions/{$submission->id}/reject", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    });

    test('rejects submitted submission with reason', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.approve');

        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $this->template->id,
            'status' => 'submitted',
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/v1/admin/onboarding/submissions/{$submission->id}/reject", [
                'reason' => 'Missing required documents',
            ]);

        $response->assertStatus(200);
        expect($response->json('data.status'))->toBe('rejected');
        expect($response->json('data.review_notes'))->toBe('Missing required documents');
        expect($response->json('data.reviewed_by'))->toBe($this->user->id);
        expect($response->json('data.reviewed_at'))->not->toBeNull();
    });

    test('returns 422 when attempting to reject non-submitted submission', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.approve');

        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $this->template->id,
            'status' => 'approved',
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/v1/admin/onboarding/submissions/{$submission->id}/reject", [
                'reason' => 'Attempt to reject approved submission',
            ]);

        $response->assertStatus(422);
    });
});

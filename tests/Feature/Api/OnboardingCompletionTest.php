<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

use App\Models\Employee;
use App\Models\OnboardingFormSubmission;
use App\Models\OnboardingFormTemplate;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Setup TenantKey for encryption and permission system
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    // Set tenant context for permission system
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($this->tenant->id);

    // Create pre-contract employee with user account
    $this->user = User::factory()->create();
    $this->employee = Employee::factory()->preContract()->create([
        'user_id' => $this->user->id,
        'status' => Employee::STATUS_PRE_CONTRACT,
        'onboarding_completed' => false,
        'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_ACCOUNT_INITIALIZED,
    ]);

    // Grant onboarding.write permission for submission tests
    Permission::firstOrCreate([
        'name' => 'onboarding.write',
        'guard_name' => 'sanctum',
    ]);
    $this->user->givePermissionTo('onboarding.write');

    // Helper method to make authenticated requests with tenant header
    $this->authGet = fn ($uri) => $this->actingAs($this->user, 'sanctum')
        ->withHeaders(['X-Tenant-ID' => (string) $this->tenant->id])
        ->getJson($uri);

    $this->authPost = fn ($uri, $data = []) => $this->actingAs($this->user, 'sanctum')
        ->withHeaders(['X-Tenant-ID' => (string) $this->tenant->id])
        ->postJson($uri, $data);

    // Isolate completion assertions from migration-created default templates.
    OnboardingFormTemplate::query()->forceDelete();
});

afterEach(function () {
    // Reset tenant context
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

describe('GET /api/v1/onboarding/completion-status', function () {
    it('requires authentication', function () {
        $this->withHeaders(['X-Tenant-ID' => (string) $this->tenant->id])
            ->getJson('/v1/onboarding/completion-status')
            ->assertStatus(401);
    });

    it('returns 404 when user has no employee record', function () {
        $userWithoutEmployee = User::factory()->create();

        actingAs($userWithoutEmployee, 'sanctum')
            ->withHeaders(['X-Tenant-ID' => (string) $this->tenant->id])
            ->getJson('/v1/onboarding/completion-status')
            ->assertStatus(404)
            ->assertJson([
                'message' => 'No employee record found for user',
            ]);
    });

    it('returns accurate completion status with no submissions', function () {
        $template1 = OnboardingFormTemplate::factory()->create([
            'tenant_id' => null,
            'is_required' => true,
            'is_system_template' => true,
            'name' => 'Personal Information',
            'description' => 'Your personal details',
            'sort_order' => 1,
        ]);

        $template2 = OnboardingFormTemplate::factory()->create([
            'tenant_id' => null,
            'is_required' => true,
            'is_system_template' => true,
            'name' => 'Bank Account',
            'description' => 'Banking information',
            'sort_order' => 2,
        ]);

        actingAs($this->user, 'sanctum')
            ->withHeaders(['X-Tenant-ID' => (string) $this->tenant->id])
            ->getJson('/v1/onboarding/completion-status')
            ->assertOk()
            ->assertJson([
                'data' => [
                    'is_completed' => false,
                    'total_required' => 2,
                    'completed_required' => 0,
                    'missing_templates' => [
                        [
                            'id' => $template1->id,
                            'name' => 'Personal Information',
                            'description' => 'Your personal details',
                        ],
                        [
                            'id' => $template2->id,
                            'name' => 'Bank Account',
                            'description' => 'Banking information',
                        ],
                    ],
                ],
            ]);
    });

    it('returns accurate status with partial completion', function () {
        $template1 = OnboardingFormTemplate::factory()->create([
            'tenant_id' => null,
            'is_required' => true,
            'is_system_template' => true,
            'name' => 'Personal Information',
        ]);

        $template2 = OnboardingFormTemplate::factory()->create([
            'tenant_id' => null,
            'is_required' => true,
            'is_system_template' => true,
            'name' => 'Bank Account',
        ]);

        // Only template1 approved
        OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $template1->id,
            'status' => 'approved',
        ]);

        actingAs($this->user, 'sanctum')
            ->withHeaders(['X-Tenant-ID' => (string) $this->tenant->id])
            ->getJson('/v1/onboarding/completion-status')
            ->assertOk()
            ->assertJson([
                'data' => [
                    'is_completed' => false,
                    'total_required' => 2,
                    'completed_required' => 1,
                ],
            ])
            ->assertJsonCount(1, 'data.missing_templates')
            ->assertJsonPath('data.missing_templates.0.name', 'Bank Account');
    });

    it('returns completed status when all required forms approved', function () {
        $template1 = OnboardingFormTemplate::factory()->create([
            'tenant_id' => null,
            'is_required' => true,
            'is_system_template' => true,
        ]);

        $template2 = OnboardingFormTemplate::factory()->create([
            'tenant_id' => null,
            'is_required' => true,
            'is_system_template' => true,
        ]);

        // Both approved
        OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $template1->id,
            'status' => 'approved',
        ]);

        OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $template2->id,
            'status' => 'approved',
        ]);

        actingAs($this->user, 'sanctum')
            ->withHeaders(['X-Tenant-ID' => (string) $this->tenant->id])
            ->getJson('/v1/onboarding/completion-status')
            ->assertOk()
            ->assertJson([
                'data' => [
                    'is_completed' => true,
                    'total_required' => 2,
                    'completed_required' => 2,
                    'missing_templates' => [],
                ],
            ]);
    });

    it('ignores optional templates in completion calculation', function () {
        $requiredTemplate = OnboardingFormTemplate::factory()->create([
            'tenant_id' => null,
            'is_required' => true,
            'is_system_template' => true,
        ]);

        $optionalTemplate = OnboardingFormTemplate::factory()->create([
            'tenant_id' => null,
            'is_required' => false,
            'is_system_template' => true,
        ]);

        // Only required template approved (optional not submitted)
        OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $requiredTemplate->id,
            'status' => 'approved',
        ]);

        actingAs($this->user, 'sanctum')
            ->withHeaders(['X-Tenant-ID' => (string) $this->tenant->id])
            ->getJson('/v1/onboarding/completion-status')
            ->assertOk()
            ->assertJson([
                'data' => [
                    'is_completed' => true,
                    'total_required' => 1,
                    'completed_required' => 1,
                    'missing_templates' => [],
                ],
            ]);
    });

    it('does not count pending submissions as completed', function () {
        $template = OnboardingFormTemplate::factory()->create([
            'tenant_id' => null,
            'is_required' => true,
            'is_system_template' => true,
        ]);

        // Submitted but not approved
        OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $template->id,
            'status' => 'submitted', // Pending
        ]);

        actingAs($this->user, 'sanctum')
            ->withHeaders(['X-Tenant-ID' => (string) $this->tenant->id])
            ->getJson('/v1/onboarding/completion-status')
            ->assertOk()
            ->assertJson([
                'data' => [
                    'is_completed' => false,
                    'total_required' => 1,
                    'completed_required' => 0,
                ],
            ])
            ->assertJsonCount(1, 'data.missing_templates');
    });

    it('does not count rejected submissions as completed', function () {
        $template = OnboardingFormTemplate::factory()->create([
            'tenant_id' => null,
            'is_required' => true,
            'is_system_template' => true,
        ]);

        // Rejected submission
        OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $template->id,
            'status' => 'rejected',
        ]);

        actingAs($this->user, 'sanctum')
            ->withHeaders(['X-Tenant-ID' => (string) $this->tenant->id])
            ->getJson('/v1/onboarding/completion-status')
            ->assertOk()
            ->assertJson([
                'data' => [
                    'is_completed' => false,
                    'total_required' => 1,
                    'completed_required' => 0,
                ],
            ]);
    });

    it('returns instant completion when no required templates exist', function () {
        // Only optional templates
        OnboardingFormTemplate::factory()->count(2)->create([
            'tenant_id' => null,
            'is_required' => false,
            'is_system_template' => true,
        ]);

        actingAs($this->user, 'sanctum')
            ->withHeaders(['X-Tenant-ID' => (string) $this->tenant->id])
            ->getJson('/v1/onboarding/completion-status')
            ->assertOk()
            ->assertJson([
                'data' => [
                    'is_completed' => true,
                    'total_required' => 0,
                    'completed_required' => 0,
                    'missing_templates' => [],
                ],
            ]);
    });
});

describe('OnboardingController::submitForm completion integration', function () {
    it('does not trigger completion check when saving as draft', function () {
        $template = OnboardingFormTemplate::factory()->create([
            'tenant_id' => null,
            'is_required' => true,
            'is_system_template' => true,
            'form_schema' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                ],
            ],
        ]);

        actingAs($this->user, 'sanctum')
            ->withHeaders(['X-Tenant-ID' => (string) $this->tenant->id])
            ->postJson('/v1/onboarding/submissions', [
                'form_template_id' => $template->id,
                'form_data' => ['name' => 'John Doe'],
                'status' => 'draft', // Draft status
            ])
            ->assertCreated();

        // Onboarding should NOT be marked complete for drafts
        expect($this->employee->fresh()->onboarding_completed)->toBeFalse();
    });

    it('triggers completion check when submitting form', function () {
        $template = OnboardingFormTemplate::factory()->create([
            'tenant_id' => null,
            'is_required' => true,
            'is_system_template' => true,
            'form_schema' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                ],
            ],
        ]);

        actingAs($this->user, 'sanctum')
            ->withHeaders(['X-Tenant-ID' => (string) $this->tenant->id])
            ->postJson('/v1/onboarding/submissions', [
                'form_template_id' => $template->id,
                'form_data' => ['name' => 'John Doe'],
                'status' => 'submitted',
            ])
            ->assertCreated();

        // Completion check was triggered (but not complete since not approved yet)
        expect($this->employee->fresh()->onboarding_completed)->toBeFalse();
    });
});

describe('OnboardingController::approveSubmission completion integration', function () {
    it('triggers completion check when approving last required form', function () {
        // Create HR user with approval permissions
        Permission::firstOrCreate(['name' => 'onboarding.approve', 'guard_name' => 'sanctum']);
        Role::firstOrCreate(['name' => 'hr_admin', 'guard_name' => 'sanctum']);
        $hrUser = User::factory()->create();
        $hrUser->assignRole('hr_admin');
        $hrUser->givePermissionTo('onboarding.approve');

        $template = OnboardingFormTemplate::factory()->create([
            'tenant_id' => null,
            'is_required' => true,
            'is_system_template' => true,
        ]);

        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $template->id,
            'status' => 'submitted',
        ]);

        // Before approval - not complete
        expect($this->employee->onboarding_completed)->toBeFalse();

        // Approve submission
        actingAs($hrUser, 'sanctum')
            ->withHeaders(['X-Tenant-ID' => (string) $this->tenant->id])
            ->postJson("/v1/onboarding-review/submissions/{$submission->id}/approve")
            ->assertOk();

        // After approval - should be complete (only 1 required template)
        expect($this->employee->fresh()->onboarding_completed)->toBeTrue();
        expect($this->employee->fresh()->onboarding_completed_at)->not->toBeNull();
    });

    it('marks completion when all required forms are approved', function () {
        Permission::firstOrCreate(['name' => 'onboarding.approve', 'guard_name' => 'sanctum']);
        Role::firstOrCreate(['name' => 'hr_admin', 'guard_name' => 'sanctum']);
        $hrUser = User::factory()->create();
        $hrUser->assignRole('hr_admin');
        $hrUser->givePermissionTo('onboarding.approve');

        $template1 = OnboardingFormTemplate::factory()->create([
            'tenant_id' => null,
            'is_required' => true,
            'is_system_template' => true,
        ]);

        $template2 = OnboardingFormTemplate::factory()->create([
            'tenant_id' => null,
            'is_required' => true,
            'is_system_template' => true,
        ]);

        $submission1 = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $template1->id,
            'status' => 'submitted',
        ]);

        $submission2 = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $template2->id,
            'status' => 'submitted',
        ]);

        // Approve first submission
        actingAs($hrUser, 'sanctum')
            ->withHeaders(['X-Tenant-ID' => (string) $this->tenant->id])
            ->postJson("/v1/onboarding-review/submissions/{$submission1->id}/approve")
            ->assertOk();

        // Not complete yet (1 of 2 approved)
        expect($this->employee->fresh()->onboarding_completed)->toBeFalse();

        // Approve second submission
        actingAs($hrUser, 'sanctum')
            ->withHeaders(['X-Tenant-ID' => (string) $this->tenant->id])
            ->postJson("/v1/onboarding-review/submissions/{$submission2->id}/approve")
            ->assertOk();

        // Now complete (2 of 2 approved)
        expect($this->employee->fresh()->onboarding_completed)->toBeTrue();
        expect($this->employee->fresh()->onboarding_completed_at)->not->toBeNull();
    });
});

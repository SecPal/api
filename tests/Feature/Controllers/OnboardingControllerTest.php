<?php

/*
 * SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

/**
 * @property TenantKey $tenant
 * @property User $user
 * @property mixed $token
 * @property Employee $employee
 * @property OnboardingFormTemplate $template
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);

    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('test-device')->plainTextToken;

    Permission::firstOrCreate(['name' => 'onboarding.read', 'guard_name' => 'sanctum']);
    Permission::firstOrCreate(['name' => 'onboarding.write', 'guard_name' => 'sanctum']);
    Permission::firstOrCreate(['name' => 'onboarding.approve', 'guard_name' => 'sanctum']);
    Permission::firstOrCreate(['name' => 'onboarding.confirm', 'guard_name' => 'sanctum']);

    $organizationalUnit = OrganizationalUnit::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $this->employee = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'organizational_unit_id' => $organizationalUnit->id,
        'user_id' => $this->user->id,
        'status' => Employee::STATUS_PRE_CONTRACT,
        'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_ACCOUNT_INITIALIZED,
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

    test('allows pre-contract employees to fetch onboarding steps without onboarding.read permission', function (): void {
        $response = $this->withToken($this->token)
            ->getJson('/v1/onboarding/steps');

        $response->assertOk();
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

    test('allows pre-contract employees to list templates without onboarding.read permission', function (): void {
        $response = $this->withToken($this->token)
            ->getJson('/v1/onboarding/templates');

        $response->assertOk();
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

    test('localizes template list metadata using request locale', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.read');

        $this->artisan('db:seed', ['--class' => Database\Seeders\OnboardingFormTemplatesSeeder::class])
            ->assertSuccessful();

        $response = $this->withToken($this->token)
            ->withHeader('Accept-Language', 'de-DE,de;q=0.9,en;q=0.8')
            ->getJson('/v1/onboarding/templates');

        $response->assertOk();

        $templates = collect($response->json('data'));
        $personalInformationTemplate = $templates->firstWhere('name', 'Persönliche Informationen');

        expect($personalInformationTemplate)->not->toBeNull()
            ->and($personalInformationTemplate['description'])->toBe('Ihre persönlichen Angaben für das Onboarding; fehlende Bewacherregister-Felder kann die Personalabteilung später ergänzen.');
    });
});

describe('GET /v1/onboarding/templates/{template}', function () {
    test('returns 401 when not authenticated', function (): void {
        $response = $this->getJson("/v1/onboarding/templates/{$this->template->id}");
        $response->assertStatus(401);
    });

    test('allows pre-contract employees to view a template without onboarding.read permission', function (): void {
        $response = $this->withToken($this->token)
            ->getJson("/v1/onboarding/templates/{$this->template->id}");

        $response->assertOk();
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

    test('localizes system template schema using accept language header', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.read');

        $this->artisan('db:seed', ['--class' => Database\Seeders\OnboardingFormTemplatesSeeder::class])
            ->assertSuccessful();

        $template = OnboardingFormTemplate::query()
            ->whereNull('tenant_id')
            ->where('name', 'Personal Information Form')
            ->firstOrFail();

        $response = $this->withToken($this->token)
            ->withHeader('Accept-Language', 'de-DE,de;q=0.9,en;q=0.8')
            ->getJson("/v1/onboarding/templates/{$template->id}");

        $response->assertOk()
            ->assertJsonPath('data.name', 'Persönliche Informationen')
            ->assertJsonPath('data.description', 'Ihre persönlichen Angaben für das Onboarding; fehlende Bewacherregister-Felder kann die Personalabteilung später ergänzen.')
            ->assertJsonPath('data.form_schema.title', 'Persönliche Informationen')
            ->assertJsonPath('data.form_schema.properties.gender.title', 'Geschlecht')
            ->assertJsonPath('data.form_schema.properties.gender.enumNames.0', 'Männlich')
            ->assertJsonPath('data.form_schema.properties.intended_activities.items.enumNames.3', 'Geld- und Werttransport');
    });

    test('prefers user locale over accept language header when localizing templates', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.read');

        $this->user->forceFill(['preferred_locale' => 'de'])->save();

        $this->artisan('db:seed', ['--class' => Database\Seeders\OnboardingFormTemplatesSeeder::class])
            ->assertSuccessful();

        $template = OnboardingFormTemplate::query()
            ->whereNull('tenant_id')
            ->where('name', 'Bank Account Details')
            ->firstOrFail();

        $response = $this->withToken($this->token)
            ->withHeader('Accept-Language', 'en-US,en;q=0.9')
            ->getJson("/v1/onboarding/templates/{$template->id}");

        $response->assertOk()
            ->assertJsonPath('data.name', 'Bankverbindung')
            ->assertJsonPath('data.form_schema.title', 'Bankverbindung')
            ->assertJsonPath('data.form_schema.properties.account_holder.title', 'Kontoinhaber');
    });
});

describe('GET /v1/onboarding/submissions', function () {
    test('returns 401 when not authenticated', function (): void {
        $response = $this->getJson('/v1/onboarding/submissions');
        $response->assertStatus(401);
    });

    test('allows pre-contract employees to list submissions without onboarding.read permission', function (): void {
        $response = $this->withToken($this->token)
            ->getJson('/v1/onboarding/submissions');

        $response->assertOk();
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

    test('localizes nested submission form template metadata using request locale', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.read');

        $this->artisan('db:seed', ['--class' => Database\Seeders\OnboardingFormTemplatesSeeder::class])
            ->assertSuccessful();

        $template = OnboardingFormTemplate::query()
            ->whereNull('tenant_id')
            ->where('name', 'Personal Information Form')
            ->firstOrFail();

        OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $template->id,
        ]);

        $response = $this->withToken($this->token)
            ->withHeader('Accept-Language', 'de-DE,de;q=0.9,en;q=0.8')
            ->getJson('/v1/onboarding/submissions');

        $response->assertOk()
            ->assertJsonPath('data.0.form_template.name', 'Persönliche Informationen')
            ->assertJsonPath('data.0.form_template.description', 'Ihre persönlichen Angaben für das Onboarding; fehlende Bewacherregister-Felder kann die Personalabteilung später ergänzen.')
            ->assertJsonPath('data.0.form_template.form_schema.title', 'Persönliche Informationen');
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

    test('allows pre-contract employees to create submissions without onboarding.write permission', function (): void {
        $response = $this->withToken($this->token)
            ->postJson('/v1/onboarding/submissions', [
                'form_template_id' => $this->template->id,
                'form_data' => ['field' => 'value'],
                'status' => 'draft',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'draft');
    });

    test('returns 422 when required fields are missing', function (): void {
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
        expect($this->employee->fresh()->onboarding_workflow_status)->toBe(Employee::WORKFLOW_STATUS_IN_PROGRESS);
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
        expect($this->employee->fresh()->onboarding_workflow_status)->toBe(Employee::WORKFLOW_STATUS_SUBMITTED_FOR_REVIEW);
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

    test('allows rejected submission to be resubmitted', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $this->template->id,
            'form_data' => ['name' => 'Original'],
            'status' => 'rejected',
            'review_notes' => 'Missing document',
            'reviewed_at' => now(),
            'reviewed_by' => $this->user->id,
        ]);

        $response = $this->withToken($this->token)
            ->postJson('/v1/onboarding/submissions', [
                'form_template_id' => $this->template->id,
                'form_data' => ['name' => 'Corrected'],
                'status' => 'submitted',
            ]);

        $response->assertStatus(200);
        expect($response->json('data.id'))->toBe($submission->id);
        expect($response->json('data.status'))->toBe('submitted');
        expect($response->json('data.review_notes'))->toBeNull();
        expect($response->json('data.reviewed_at'))->toBeNull();
        expect($this->employee->fresh()->onboarding_workflow_status)->toBe(Employee::WORKFLOW_STATUS_SUBMITTED_FOR_REVIEW);
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

    test('returns 422 with field errors when submitted data does not match the template JSON schema', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        $template = OnboardingFormTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_required' => true,
            'form_schema' => [
                'type' => 'object',
                'properties' => [
                    'iban' => [
                        'type' => 'string',
                        'pattern' => '^[A-Z]{2}\d{2}[A-Z0-9]+$',
                    ],
                ],
                'required' => ['iban'],
            ],
        ]);

        $response = $this->withToken($this->token)
            ->postJson('/v1/onboarding/submissions', [
                'form_template_id' => $template->id,
                'form_data' => ['iban' => 'not-an-iban'],
                'status' => 'submitted',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['iban']);
    });

    test('allows draft saves even when data would fail JSON schema on submit', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        $template = OnboardingFormTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
            'form_schema' => [
                'type' => 'object',
                'properties' => [
                    'iban' => [
                        'type' => 'string',
                        'pattern' => '^[A-Z]{2}\d{2}[A-Z0-9]+$',
                    ],
                ],
                'required' => ['iban'],
            ],
        ]);

        $response = $this->withToken($this->token)
            ->postJson('/v1/onboarding/submissions', [
                'form_template_id' => $template->id,
                'form_data' => ['iban' => 'bad'],
                'status' => 'draft',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'draft');
    });

    test('skips full schema enforcement for optional templates with semantically empty payloads on submit', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        $template = OnboardingFormTemplate::factory()->optional()->create([
            'tenant_id' => $this->tenant->id,
            'form_schema' => [
                'type' => 'object',
                'properties' => [
                    'iban' => [
                        'type' => 'string',
                        'pattern' => '^[A-Z]{2}\d{2}[A-Z0-9]+$',
                    ],
                ],
                'required' => ['iban'],
            ],
        ]);

        $response = $this->withToken($this->token)
            ->postJson('/v1/onboarding/submissions', [
                'form_template_id' => $template->id,
                'form_data' => [],
                'status' => 'submitted',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'submitted');
    });

    test('returns 404 when employee submits a template from a different tenant', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        // Create a template belonging to a different tenant
        $otherTenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
        $otherTemplate = OnboardingFormTemplate::factory()->create([
            'tenant_id' => $otherTenant->id,
            'form_schema' => [
                'type' => 'object',
                'properties' => ['name' => ['type' => 'string']],
                'required' => ['name'],
            ],
        ]);

        $response = $this->withToken($this->token)
            ->postJson('/v1/onboarding/submissions', [
                'form_template_id' => $otherTemplate->id,
                'form_data' => ['name' => 'test'],
                'status' => 'draft',
            ]);

        $response->assertStatus(404);
    });

    test('rejects partial optional-template payload when required schema fields are missing on submit', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        $template = OnboardingFormTemplate::factory()->optional()->create([
            'tenant_id' => $this->tenant->id,
            'form_schema' => [
                'type' => 'object',
                'properties' => [
                    'contact_name' => ['type' => 'string'],
                    'contact_phone' => ['type' => 'string'],
                ],
                'required' => ['contact_name', 'contact_phone'],
            ],
        ]);

        // Partial payload: provides one required field but not the other
        $response = $this->withToken($this->token)
            ->postJson('/v1/onboarding/submissions', [
                'form_template_id' => $template->id,
                'form_data' => ['contact_name' => 'Jane'],
                'status' => 'submitted',
            ]);

        $response->assertStatus(422);
    });

    test('does not treat false boolean values as semantically empty for optional templates', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        $template = OnboardingFormTemplate::factory()->optional()->create([
            'tenant_id' => $this->tenant->id,
            'form_schema' => [
                'type' => 'object',
                'properties' => [
                    'consent' => [
                        'type' => 'boolean',
                        'enum' => [true],
                    ],
                ],
                'required' => ['consent'],
            ],
        ]);

        $response = $this->withToken($this->token)
            ->postJson('/v1/onboarding/submissions', [
                'form_template_id' => $template->id,
                'form_data' => ['consent' => false],
                'status' => 'submitted',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['consent']);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['consent']);
    });

    test('does not treat invalid string input for integer fields as semantically empty on submit', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        $template = OnboardingFormTemplate::factory()->optional()->create([
            'tenant_id' => $this->tenant->id,
            'form_schema' => [
                'type' => 'object',
                'properties' => [
                    'count' => [
                        'type' => 'integer',
                        'minimum' => 0,
                    ],
                ],
                'required' => ['count'],
            ],
        ]);

        // 'abc' is not a valid integer; it must not be silently treated as "empty"
        // but must trigger schema validation and return 422.
        $response = $this->withToken($this->token)
            ->postJson('/v1/onboarding/submissions', [
                'form_template_id' => $template->id,
                'form_data' => ['count' => 'abc'],
                'status' => 'submitted',
            ]);

        $response->assertStatus(422);
    });
});

describe('PATCH /v1/onboarding/submissions/{submission}', function () {
    test('returns 401 when not authenticated', function (): void {
        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $this->template->id,
            'status' => 'draft',
        ]);

        $response = $this->patchJson("/v1/onboarding/submissions/{$submission->id}", [
            'form_data' => ['name' => 'Updated'],
        ]);

        $response->assertStatus(401);
    });

    test('allows pre-contract employees to update submissions without onboarding.write permission', function (): void {
        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $this->template->id,
            'status' => 'draft',
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/v1/onboarding/submissions/{$submission->id}", [
                'form_data' => ['name' => 'Updated'],
            ]);

        $response->assertOk()
            ->assertJsonPath('data.form_data.name', 'Updated');
    });

    test('returns 422 when neither form data nor status is provided', function (): void {
        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $this->template->id,
            'status' => 'draft',
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/v1/onboarding/submissions/{$submission->id}", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['form_data', 'status']);
    });

    test('updates an existing draft submission without requiring form template id', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $this->template->id,
            'form_data' => ['name' => 'Original'],
            'status' => 'draft',
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/v1/onboarding/submissions/{$submission->id}", [
                'form_data' => ['name' => 'Updated'],
            ]);

        $response->assertStatus(200);
        expect($response->json('data.id'))->toBe($submission->id)
            ->and($response->json('data.form_template_id'))->toBe($this->template->id)
            ->and($response->json('data.form_data')['name'])->toBe('Updated')
            ->and($response->json('data.status'))->toBe('draft');
    });

    test('allows a rejected submission to be corrected and resubmitted', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $this->template->id,
            'form_data' => ['name' => 'Original'],
            'status' => 'rejected',
            'review_notes' => 'Missing document',
            'reviewed_at' => now(),
            'reviewed_by' => $this->user->id,
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/v1/onboarding/submissions/{$submission->id}", [
                'form_data' => ['name' => 'Corrected'],
                'status' => 'submitted',
            ]);

        $response->assertStatus(200);
        expect($response->json('data.id'))->toBe($submission->id)
            ->and($response->json('data.status'))->toBe('submitted')
            ->and($response->json('data.form_data')['name'])->toBe('Corrected')
            ->and($response->json('data.review_notes'))->toBeNull()
            ->and($response->json('data.reviewed_at'))->toBeNull()
            ->and($this->employee->fresh()->onboarding_workflow_status)->toBe(Employee::WORKFLOW_STATUS_SUBMITTED_FOR_REVIEW);
    });

    test('persists the same empty array payload that gets schema validated during submit', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        $template = OnboardingFormTemplate::factory()->optional()->create([
            'tenant_id' => $this->tenant->id,
            'form_schema' => [
                'type' => 'object',
                'properties' => [
                    'iban' => [
                        'type' => 'string',
                        'pattern' => '^[A-Z]{2}\d{2}[A-Z0-9]+$',
                    ],
                ],
                'required' => ['iban'],
            ],
        ]);

        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $template->id,
            'form_data' => null,
            'status' => 'draft',
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/v1/onboarding/submissions/{$submission->id}", [
                'status' => 'submitted',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.form_data', []);

        expect($submission->fresh()->form_data)->toBe([]);
    });

    test('does not allow patching another employees submission', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        $otherUser = User::factory()->create();
        $otherEmployee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => $this->employee->organizational_unit_id,
            'user_id' => $otherUser->id,
            'status' => Employee::STATUS_PRE_CONTRACT,
        ]);

        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $otherEmployee->id,
            'form_template_id' => $this->template->id,
            'status' => 'draft',
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/v1/onboarding/submissions/{$submission->id}", [
                'form_data' => ['name' => 'Attempted update'],
            ]);

        $response->assertStatus(403);
    });

    test('does not update already submitted submission', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $this->template->id,
            'status' => 'submitted',
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/v1/onboarding/submissions/{$submission->id}", [
                'form_data' => ['name' => 'Attempt Update'],
            ]);

        $response->assertStatus(409)
            ->assertJson(['message' => 'Form has already been submitted and is awaiting review']);
    });

    test('returns 409 with approved message for approved submission', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $this->template->id,
            'status' => 'approved',
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/v1/onboarding/submissions/{$submission->id}", [
                'form_data' => ['name' => 'Attempt Update'],
            ]);

        $response->assertStatus(409)
            ->assertJson(['message' => 'Form has already been reviewed and approved']);
    });

    test('defaults rejected status to draft when status is not provided', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $this->template->id,
            'status' => 'rejected',
            'review_notes' => 'Needs correction',
            'reviewed_at' => now(),
            'reviewed_by' => $this->user->id,
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/v1/onboarding/submissions/{$submission->id}", [
                'form_data' => ['name' => 'Corrected'],
            ]);

        $response->assertStatus(200);
        expect($response->json('data.status'))->toBe('draft')
            ->and($response->json('data.form_data')['name'])->toBe('Corrected')
            ->and($response->json('data.review_notes'))->toBeNull()
            ->and($response->json('data.reviewed_at'))->toBeNull();
    });

    test('returns 403 for non-pre-contract employee', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        $this->employee->update(['status' => Employee::STATUS_ACTIVE]);

        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $this->template->id,
            'status' => 'draft',
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/v1/onboarding/submissions/{$submission->id}", [
                'form_data' => ['name' => 'Updated'],
            ]);

        $response->assertStatus(403);
    });
});

describe('POST /v1/onboarding/submissions/{submission}/files', function () {
    test('returns 401 when not authenticated', function (): void {
        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $this->template->id,
            'status' => 'draft',
        ]);

        $response = $this->post("/v1/onboarding/submissions/{$submission->id}/files", [
            'file' => UploadedFile::fake()->create('contract.pdf', 100, 'application/pdf'),
            'document_type' => 'contract',
        ]);

        $response->assertStatus(401);
    });

    test('allows self-service upload for an own draft submission without onboarding.write permission', function (): void {
        Storage::fake('local');

        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $this->template->id,
            'status' => 'draft',
        ]);

        $response = $this->withToken($this->token)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/v1/onboarding/submissions/{$submission->id}/files", [
                'file' => UploadedFile::fake()->create('contract.pdf', 100, 'application/pdf'),
                'document_type' => 'contract',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => ['id', 'filename'],
            ]);

        expect($response->json('data.filename'))->toBe('contract.pdf');

        $this->assertDatabaseHas('onboarding_submission_files', [
            'onboarding_form_submission_id' => $submission->id,
            'document_type' => 'contract',
            'file_name' => 'contract.pdf',
        ]);
    });

    test('uploads a file for an employee draft submission', function (): void {
        Storage::fake('local');
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $this->template->id,
            'status' => 'draft',
        ]);

        $response = $this->withToken($this->token)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/v1/onboarding/submissions/{$submission->id}/files", [
                'file' => UploadedFile::fake()->create('contract.pdf', 100, 'application/pdf'),
                'document_type' => 'contract',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => ['id', 'filename'],
            ]);

        expect($response->json('data.filename'))->toBe('contract.pdf');

        $this->assertDatabaseHas('onboarding_submission_files', [
            'onboarding_form_submission_id' => $submission->id,
            'document_type' => 'contract',
            'file_name' => 'contract.pdf',
        ]);

        // Verify that the encrypted blob was stored on disk with the expected JSON structure.
        $fileId = $response->json('data.id');
        $record = DB::table('onboarding_submission_files')->where('id', $fileId)->first();
        $this->assertNotNull($record);
        $this->assertNotNull($record->file_path ?? null);

        Storage::disk('local')->assertExists($record->file_path);

        $raw = Storage::disk('local')->get($record->file_path);
        $decoded = json_decode($raw, true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('ciphertext', $decoded);
        $this->assertArrayHasKey('nonce', $decoded);
        $this->assertNotEmpty($decoded['ciphertext']);
        $this->assertNotEmpty($decoded['nonce']);
    });

    test('returns 403 when uploading a file to another employee submission', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        $otherUser = User::factory()->create();
        $otherEmployee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'organizational_unit_id' => $this->employee->organizational_unit_id,
            'user_id' => $otherUser->id,
            'status' => Employee::STATUS_PRE_CONTRACT,
        ]);

        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $otherEmployee->id,
            'form_template_id' => $this->template->id,
            'status' => 'draft',
        ]);

        $response = $this->withToken($this->token)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/v1/onboarding/submissions/{$submission->id}/files", [
                'file' => UploadedFile::fake()->create('contract.pdf', 100, 'application/pdf'),
                'document_type' => 'contract',
            ]);

        $response->assertStatus(403);
    });

    test('returns 422 when attempting to upload a file to a submitted submission', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $this->template->id,
            'status' => 'submitted',
        ]);

        $response = $this->withToken($this->token)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/v1/onboarding/submissions/{$submission->id}/files", [
                'file' => UploadedFile::fake()->create('contract.pdf', 100, 'application/pdf'),
                'document_type' => 'contract',
            ]);

        $response->assertStatus(422);
    });
});

describe('POST /v1/onboarding-review/submissions/{submission}/approve', function () {
    test('returns 401 when not authenticated', function (): void {
        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $this->template->id,
            'status' => 'submitted',
        ]);

        $response = $this->postJson("/v1/onboarding-review/submissions/{$submission->id}/approve");
        $response->assertStatus(401);
    });

    test('returns 403 when user lacks onboarding.approve permission', function (): void {
        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $this->template->id,
            'status' => 'submitted',
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/v1/onboarding-review/submissions/{$submission->id}/approve");

        $response->assertStatus(403);
    });

    test('approves submitted submission with valid permission', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.approve');
        $this->employee->update([
            'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_SUBMITTED_FOR_REVIEW,
        ]);

        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $this->template->id,
            'status' => 'submitted',
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/v1/onboarding-review/submissions/{$submission->id}/approve");

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
            ->postJson("/v1/onboarding-review/submissions/{$submission->id}/approve");

        $response->assertStatus(422);
    });
});

describe('POST /v1/onboarding-review/submissions/{submission}/reject', function () {
    test('returns 401 when not authenticated', function (): void {
        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $this->template->id,
            'status' => 'submitted',
        ]);

        $response = $this->postJson("/v1/onboarding-review/submissions/{$submission->id}/reject", [
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
            ->postJson("/v1/onboarding-review/submissions/{$submission->id}/reject", [
                'reason' => 'Incomplete information',
            ]);

        $response->assertStatus(403);
    });

    test('returns 422 when reason is missing', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.approve');
        $this->employee->update([
            'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_SUBMITTED_FOR_REVIEW,
        ]);

        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $this->template->id,
            'status' => 'submitted',
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/v1/onboarding-review/submissions/{$submission->id}/reject", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    });

    test('rejects submitted submission with reason', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.approve');
        $this->employee->update([
            'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_SUBMITTED_FOR_REVIEW,
        ]);

        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $this->template->id,
            'status' => 'submitted',
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/v1/onboarding-review/submissions/{$submission->id}/reject", [
                'reason' => 'Missing required documents',
            ]);

        $response->assertStatus(200);
        expect($response->json('data.status'))->toBe('rejected');
        expect($response->json('data.review_notes'))->toBe('Missing required documents');
        expect($response->json('data.reviewed_by'))->toBe($this->user->id);
        expect($response->json('data.reviewed_at'))->not->toBeNull();
        expect($this->employee->fresh()->onboarding_workflow_status)->toBe(Employee::WORKFLOW_STATUS_CHANGES_REQUESTED);
    });

    test('returns 422 when attempting to reject non-submitted submission', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.approve');

        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $this->template->id,
            'status' => 'approved',
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/v1/onboarding-review/submissions/{$submission->id}/reject", [
                'reason' => 'Attempt to reject approved submission',
            ]);

        $response->assertStatus(422);
    });
});

describe('POST /v1/onboarding-review/employees/{employee}/confirm', function () {
    test('returns 401 when not authenticated', function (): void {
        $response = $this->postJson("/v1/onboarding-review/employees/{$this->employee->id}/confirm");

        $response->assertStatus(401);
    });

    test('returns 403 when user lacks onboarding.confirm permission', function (): void {
        $response = $this->withToken($this->token)
            ->postJson("/v1/onboarding-review/employees/{$this->employee->id}/confirm");

        $response->assertStatus(403);
    });

    test('returns 422 when employee workflow is not submitted for review', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.confirm');

        $response = $this->withToken($this->token)
            ->postJson("/v1/onboarding-review/employees/{$this->employee->id}/confirm");

        $response->assertStatus(422);
    });

    test('returns 422 when onboarding dossier is not complete', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.confirm');

        $this->employee->update([
            'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_SUBMITTED_FOR_REVIEW,
            'onboarding_completed' => false,
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/v1/onboarding-review/employees/{$this->employee->id}/confirm");

        $response->assertStatus(422);
    });

    test('returns 422 when confirmation notes exceed the maximum length', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.confirm');

        $this->employee->update([
            'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_SUBMITTED_FOR_REVIEW,
            'onboarding_completed' => true,
            'contract_start_date' => now()->addWeek()->toDateString(),
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/v1/onboarding-review/employees/{$this->employee->id}/confirm", [
                'notes' => str_repeat('A', 1001),
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['notes']);
    });

    test('confirms onboarding dossier and keeps contract confirmed when contract start is in the future', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.confirm');

        $this->employee->update([
            'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_SUBMITTED_FOR_REVIEW,
            'onboarding_completed' => true,
            'contract_start_date' => now()->addWeek()->toDateString(),
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/v1/onboarding-review/employees/{$this->employee->id}/confirm", [
                'notes' => 'Contract signed and reviewed.',
            ]);

        $response->assertStatus(200);
        expect($response->json('data.onboarding_workflow.status'))->toBe(Employee::WORKFLOW_STATUS_CONTRACT_CONFIRMED);
        expect($this->employee->fresh()->onboarding_workflow_status)->toBe(Employee::WORKFLOW_STATUS_CONTRACT_CONFIRMED);

        $activity = Activity::where('subject_id', $this->employee->id)
            ->where('event', 'onboarding_contract_confirmed')
            ->latest('id')
            ->first();

        expect($activity)->not->toBeNull();
        expect($activity?->causer_id)->toBe($this->user->id);
        expect($activity?->properties['to_workflow_status'] ?? null)->toBe(Employee::WORKFLOW_STATUS_CONTRACT_CONFIRMED);
    });

    test('confirms onboarding dossier and promotes employee to ready for activation when contract start has passed', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.confirm');

        $this->employee->update([
            'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_SUBMITTED_FOR_REVIEW,
            'onboarding_completed' => true,
            'contract_start_date' => now()->subDay()->toDateString(),
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/v1/onboarding-review/employees/{$this->employee->id}/confirm");

        $response->assertStatus(200);
        expect($response->json('data.onboarding_workflow.status'))->toBe(Employee::WORKFLOW_STATUS_READY_FOR_ACTIVATION);
        expect($this->employee->fresh()->onboarding_workflow_status)->toBe(Employee::WORKFLOW_STATUS_READY_FOR_ACTIVATION);
    });
});

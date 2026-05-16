<?php

/*
 * SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use App\Models\Employee;
use App\Models\OnboardingFormSubmission;
use App\Models\OnboardingFormTemplate;
use App\Models\OnboardingSubmissionFile;
use App\Models\OrganizationalUnit;
use App\Models\Permission;
use App\Models\TenantKey;
use App\Models\User;
use App\Services\OnboardingCompletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;

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

    test('does not promote invited employees when fetching onboarding steps', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.read');

        $this->employee->update([
            'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_INVITED,
        ]);

        $this->withToken($this->token)
            ->getJson('/v1/onboarding/steps')
            ->assertOk();

        expect($this->employee->fresh()->onboarding_workflow_status)
            ->toBe(Employee::WORKFLOW_STATUS_INVITED);
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

        expect(count($response->json('data')))->toBeGreaterThanOrEqual(3); // 2 created + beforeEach/default templates
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

describe('GET /v1/onboarding/nationalities', function () {
    test('returns 401 when not authenticated', function (): void {
        $response = $this->getJson('/v1/onboarding/nationalities');
        $response->assertStatus(401);
    });

    test('allows pre-contract employees to list nationalities without onboarding.read permission', function (): void {
        $response = $this->withToken($this->token)
            ->getJson('/v1/onboarding/nationalities');

        $response->assertOk();
    });

    test('returns a file-based ISO country list with localized names', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.read');

        $response = $this->withToken($this->token)
            ->withHeader('Accept-Language', 'en-US,en;q=0.9')
            ->getJson('/v1/onboarding/nationalities');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['code', 'name'],
                ],
            ]);

        $options = collect($response->json('data'));
        $deOption = $options->firstWhere('code', 'DE');
        $vaOption = $options->firstWhere('code', 'VA');
        $xkOption = $options->firstWhere('code', 'XK');
        $xaOption = $options->firstWhere('code', 'XA');

        expect($deOption)->not->toBeNull()
            ->and($deOption['name'] ?? null)->toBe('Germany')
            ->and($vaOption)->not->toBeNull()
            ->and($xkOption['name'] ?? null)->toBe('Kosovo')
            ->and($xaOption['name'] ?? null)->toBe('Stateless')
            ->and($options)->toHaveCount(199);
    });

    test('prefers user locale over accept language for nationality names', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.read');
        $this->user->forceFill(['preferred_locale' => 'de'])->save();

        $response = $this->withToken($this->token)
            ->withHeader('Accept-Language', 'en-US,en;q=0.9')
            ->getJson('/v1/onboarding/nationalities');

        $response->assertOk();

        $options = collect($response->json('data'));
        $deOption = $options->firstWhere('code', 'DE');
        $xaOption = $options->firstWhere('code', 'XA');

        expect($deOption)->not->toBeNull()
            ->and($deOption['name'] ?? null)->toBe('Deutschland')
            ->and($xaOption['name'] ?? null)->toBe('Staatenlos');
    });

    test('sorts localized nationality names with locale-aware collation', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.read');
        $this->user->forceFill(['preferred_locale' => 'de'])->save();

        $response = $this->withToken($this->token)
            ->withHeader('Accept-Language', 'de-DE,de;q=0.9,en;q=0.8')
            ->getJson('/v1/onboarding/nationalities');

        $response->assertOk();

        $options = collect($response->json('data'));
        $codes = $options->pluck('code')->all();
        $namesByCode = $options->pluck('name', 'code');
        $egyptIndex = array_search('EG', $codes, true);
        $austriaIndex = array_search('AT', $codes, true);
        $zambiaIndex = array_search('ZM', $codes, true);

        expect($namesByCode['EG'] ?? null)->toBe('Ägypten')
            ->and($namesByCode['AT'] ?? null)->toBe('Österreich')
            ->and($egyptIndex)->not->toBeFalse()
            ->and($austriaIndex)->not->toBeFalse()
            ->and($zambiaIndex)->not->toBeFalse()
            ->and($egyptIndex)->toBeLessThan($zambiaIndex)
            ->and($austriaIndex)->toBeLessThan($zambiaIndex);
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

    test('does not promote invited employees when listing submissions', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.read');

        $this->employee->update([
            'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_INVITED,
        ]);

        $this->withToken($this->token)
            ->getJson('/v1/onboarding/submissions')
            ->assertOk();

        expect($this->employee->fresh()->onboarding_workflow_status)
            ->toBe(Employee::WORKFLOW_STATUS_INVITED);
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

    test('promotes invited authenticated employees before creating a draft submission', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        $this->employee->update([
            'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_INVITED,
        ]);

        $response = $this->withToken($this->token)
            ->postJson('/v1/onboarding/submissions', [
                'form_template_id' => $this->template->id,
                'form_data' => ['name' => 'John Doe', 'email' => 'john@example.com'],
                'status' => 'draft',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'draft');

        expect($this->employee->fresh()->onboarding_workflow_status)
            ->toBe(Employee::WORKFLOW_STATUS_IN_PROGRESS);
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

    test('rejects submitted onboarding data when residence permit expiry date is in the past', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        $template = OnboardingFormTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_required' => true,
            'form_schema' => [
                'type' => 'object',
                'properties' => [
                    'residence_permit_expiry' => [
                        'type' => 'string',
                        'pattern' => '^\d{4}-\d{2}-\d{2}$',
                    ],
                ],
                'required' => ['residence_permit_expiry'],
            ],
        ]);

        $response = $this->withToken($this->token)
            ->postJson('/v1/onboarding/submissions', [
                'form_template_id' => $template->id,
                'form_data' => [
                    'residence_permit_expiry' => now()->subDay()->toDateString(),
                ],
                'status' => 'submitted',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['residence_permit_expiry']);
    });

    test('accepts unlimited residence permits even when a stale expiry date is still present', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        $template = OnboardingFormTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_required' => true,
            'form_schema' => [
                'type' => 'object',
                'properties' => [
                    'nationalities' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'string',
                            'pattern' => '^[A-Z]{2}$',
                        ],
                        'minItems' => 1,
                    ],
                    'residence_permit_title' => ['type' => 'string'],
                    'residence_permit_employment_allowed' => [
                        'type' => 'string',
                        'enum' => ['yes', 'no'],
                    ],
                    'residence_permit_unlimited' => ['type' => 'boolean'],
                    'residence_permit_expiry' => [
                        'type' => 'string',
                        'pattern' => '^\d{4}-\d{2}-\d{2}$',
                    ],
                ],
                'required' => [
                    'nationalities',
                    'residence_permit_title',
                    'residence_permit_employment_allowed',
                    'residence_permit_unlimited',
                ],
            ],
        ]);

        $response = $this->withToken($this->token)
            ->postJson('/v1/onboarding/submissions', [
                'form_template_id' => $template->id,
                'form_data' => [
                    'nationalities' => ['IN'],
                    'id_document_upload_now' => 'no',
                    'residence_permit_title' => 'Niederlassungserlaubnis',
                    'residence_permit_employment_allowed' => 'yes',
                    'residence_permit_unlimited' => true,
                    'residence_permit_upload_now' => 'no',
                    'residence_permit_expiry' => now()->subDay()->toDateString(),
                ],
                'status' => 'submitted',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'submitted');
    });

    test('rejects submitted onboarding data when employment is not permitted for residence title', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        $template = OnboardingFormTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_required' => true,
            'form_schema' => [
                'type' => 'object',
                'properties' => [
                    'residence_permit_title' => ['type' => 'string'],
                    'residence_permit_employment_allowed' => [
                        'type' => 'string',
                        'enum' => ['yes', 'no'],
                    ],
                ],
                'required' => ['residence_permit_title', 'residence_permit_employment_allowed'],
            ],
        ]);

        $response = $this->withToken($this->token)
            ->postJson('/v1/onboarding/submissions', [
                'form_template_id' => $template->id,
                'form_data' => [
                    'residence_permit_title' => 'Aufenthaltserlaubnis',
                    'residence_permit_employment_allowed' => 'no',
                ],
                'status' => 'submitted',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['residence_permit_employment_allowed']);
    });

    test('rejects submitted onboarding data when employment authorization value is invalid', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        $template = OnboardingFormTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_required' => true,
            'form_schema' => [
                'type' => 'object',
                'properties' => [
                    'residence_permit_title' => ['type' => 'string'],
                    'residence_permit_employment_allowed' => ['type' => 'string'],
                ],
                'required' => ['residence_permit_title', 'residence_permit_employment_allowed'],
            ],
        ]);

        $response = $this->withToken($this->token)
            ->postJson('/v1/onboarding/submissions', [
                'form_template_id' => $template->id,
                'form_data' => [
                    'residence_permit_title' => 'Aufenthaltserlaubnis',
                    'residence_permit_employment_allowed' => 'sometimes',
                ],
                'status' => 'submitted',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['residence_permit_employment_allowed']);
    });

    test('rejects submitted onboarding data for non-exempt nationality when residence permit fields are missing', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        $template = OnboardingFormTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_required' => true,
            'form_schema' => [
                'type' => 'object',
                'properties' => [
                    'nationalities' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'string',
                            'pattern' => '^[A-Z]{2}$',
                        ],
                        'minItems' => 1,
                    ],
                    'residence_permit_title' => ['type' => 'string'],
                    'residence_permit_employment_allowed' => [
                        'type' => 'string',
                        'enum' => ['yes', 'no'],
                    ],
                    'residence_permit_unlimited' => ['type' => 'boolean'],
                    'residence_permit_expiry' => [
                        'type' => 'string',
                        'pattern' => '^\d{4}-\d{2}-\d{2}$',
                    ],
                ],
                'required' => ['nationalities'],
            ],
        ]);

        $response = $this->withToken($this->token)
            ->postJson('/v1/onboarding/submissions', [
                'form_template_id' => $template->id,
                'form_data' => [
                    'nationalities' => ['IN'],
                ],
                'status' => 'submitted',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['residence_permit_title']);
    });

    test('rejects submitted onboarding data when a limited residence permit expires on or before contract start date', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');
        $this->employee->update(['contract_start_date' => '2026-06-01']);

        $template = OnboardingFormTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_required' => true,
            'form_schema' => [
                'type' => 'object',
                'properties' => [
                    'nationalities' => [
                        'type' => 'array',
                        'items' => ['type' => 'string', 'pattern' => '^[A-Z]{2}$'],
                        'minItems' => 1,
                    ],
                    'id_document_upload_now' => ['type' => 'string'],
                    'residence_permit_title' => ['type' => 'string'],
                    'residence_permit_employment_allowed' => ['type' => 'string'],
                    'residence_permit_unlimited' => ['type' => 'boolean'],
                    'residence_permit_expiry' => [
                        'type' => 'string',
                        'pattern' => '^\d{4}-\d{2}-\d{2}$',
                    ],
                ],
                'required' => ['nationalities', 'residence_permit_title', 'residence_permit_expiry'],
            ],
        ]);

        $response = $this->withToken($this->token)
            ->postJson('/v1/onboarding/submissions', [
                'form_template_id' => $template->id,
                'form_data' => [
                    'nationalities' => ['IN'],
                    'id_document_upload_now' => 'no',
                    'residence_permit_title' => 'Aufenthaltserlaubnis',
                    'residence_permit_unlimited' => false,
                    'residence_permit_expiry' => '2026-06-01',
                ],
                'status' => 'submitted',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['residence_permit_expiry']);
    });

    test('does not require identity upload decisions on templates that do not define that field', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        $template = OnboardingFormTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_required' => true,
            'form_schema' => [
                'type' => 'object',
                'properties' => [
                    'nationalities' => [
                        'type' => 'array',
                        'items' => ['type' => 'string', 'pattern' => '^[A-Z]{2}$'],
                        'minItems' => 1,
                    ],
                    'id_document_kind' => ['type' => 'string'],
                ],
                'required' => ['nationalities'],
            ],
        ]);

        $response = $this->withToken($this->token)
            ->postJson('/v1/onboarding/submissions', [
                'form_template_id' => $template->id,
                'form_data' => [
                    'nationalities' => ['DE'],
                    'id_document_kind' => 'passport',
                ],
                'status' => 'submitted',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'submitted');
    });

    test('allows first submitted onboarding data to choose identity upload before a draft submission exists', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        $template = OnboardingFormTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_required' => true,
            'form_schema' => [
                'type' => 'object',
                'properties' => [
                    'nationalities' => [
                        'type' => 'array',
                        'items' => ['type' => 'string', 'pattern' => '^[A-Z]{2}$'],
                        'minItems' => 1,
                    ],
                    'id_document_upload_now' => ['type' => 'string'],
                    'id_document_kind' => ['type' => 'string'],
                ],
                'required' => ['nationalities'],
            ],
        ]);

        $response = $this->withToken($this->token)
            ->postJson('/v1/onboarding/submissions', [
                'form_template_id' => $template->id,
                'form_data' => [
                    'nationalities' => ['DE'],
                    'id_document_upload_now' => 'yes',
                    'id_document_kind' => 'passport',
                ],
                'status' => 'submitted',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'submitted');
    });

    test('rejects submitted onboarding data when identity upload is set to yes but no identity file exists on an existing draft submission', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        $template = OnboardingFormTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_required' => true,
            'form_schema' => [
                'type' => 'object',
                'properties' => [
                    'nationalities' => [
                        'type' => 'array',
                        'items' => ['type' => 'string', 'pattern' => '^[A-Z]{2}$'],
                        'minItems' => 1,
                    ],
                    'id_document_upload_now' => ['type' => 'string'],
                    'id_document_kind' => ['type' => 'string'],
                ],
                'required' => ['nationalities'],
            ],
        ]);

        OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $template->id,
            'status' => 'draft',
        ]);

        $response = $this->withToken($this->token)
            ->postJson('/v1/onboarding/submissions', [
                'form_template_id' => $template->id,
                'form_data' => [
                    'nationalities' => ['DE'],
                    'id_document_upload_now' => 'yes',
                    'id_document_kind' => 'passport',
                ],
                'status' => 'submitted',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['id_document_upload_now']);
    });

    test('rejects submitted onboarding data when residence upload is set to yes but no residence file exists on an existing draft submission', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        $template = OnboardingFormTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_required' => true,
            'form_schema' => [
                'type' => 'object',
                'properties' => [
                    'nationalities' => [
                        'type' => 'array',
                        'items' => ['type' => 'string', 'pattern' => '^[A-Z]{2}$'],
                        'minItems' => 1,
                    ],
                    'id_document_upload_now' => ['type' => 'string'],
                    'residence_permit_title' => ['type' => 'string'],
                    'residence_permit_unlimited' => ['type' => 'boolean'],
                    'residence_permit_employment_allowed' => ['type' => 'string'],
                    'residence_permit_upload_now' => ['type' => 'string'],
                ],
                'required' => ['nationalities', 'residence_permit_title'],
            ],
        ]);

        OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $template->id,
            'status' => 'draft',
        ]);

        $response = $this->withToken($this->token)
            ->postJson('/v1/onboarding/submissions', [
                'form_template_id' => $template->id,
                'form_data' => [
                    'nationalities' => ['IN'],
                    'id_document_upload_now' => 'no',
                    'residence_permit_title' => 'Niederlassungserlaubnis',
                    'residence_permit_unlimited' => true,
                    'residence_permit_employment_allowed' => 'yes',
                    'residence_permit_upload_now' => 'yes',
                ],
                'status' => 'submitted',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['residence_permit_upload_now']);
    });

    test('does not require residence upload decisions on templates that do not define that field', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');
        $this->employee->update(['contract_start_date' => '2026-06-01']);

        $template = OnboardingFormTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_required' => true,
            'form_schema' => [
                'type' => 'object',
                'properties' => [
                    'nationalities' => [
                        'type' => 'array',
                        'items' => ['type' => 'string', 'pattern' => '^[A-Z]{2}$'],
                        'minItems' => 1,
                    ],
                    'id_document_upload_now' => ['type' => 'string'],
                    'residence_permit_title' => ['type' => 'string'],
                    'residence_permit_unlimited' => ['type' => 'boolean'],
                    'residence_permit_expiry' => [
                        'type' => 'string',
                        'pattern' => '^\d{4}-\d{2}-\d{2}$',
                    ],
                    'residence_permit_employment_allowed' => ['type' => 'string'],
                ],
                'required' => ['nationalities', 'residence_permit_title', 'residence_permit_expiry'],
            ],
        ]);

        $response = $this->withToken($this->token)
            ->postJson('/v1/onboarding/submissions', [
                'form_template_id' => $template->id,
                'form_data' => [
                    'nationalities' => ['IN'],
                    'id_document_upload_now' => 'no',
                    'residence_permit_title' => 'Niederlassungserlaubnis',
                    'residence_permit_unlimited' => true,
                    'residence_permit_expiry' => '2027-06-01',
                    'residence_permit_employment_allowed' => 'yes',
                ],
                'status' => 'submitted',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'submitted');
    });

    test('rejects legacy identity uploads without a document_subtype when resubmitting an existing draft', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        $template = OnboardingFormTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_required' => true,
            'form_schema' => [
                'type' => 'object',
                'properties' => [
                    'nationalities' => [
                        'type' => 'array',
                        'items' => ['type' => 'string', 'pattern' => '^[A-Z]{2}$'],
                        'minItems' => 1,
                    ],
                    'id_document_upload_now' => ['type' => 'string'],
                    'id_document_kind' => ['type' => 'string'],
                ],
                'required' => ['nationalities'],
            ],
        ]);

        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $template->id,
            'status' => 'draft',
        ]);

        OnboardingSubmissionFile::create([
            'onboarding_form_submission_id' => $submission->id,
            'uploaded_by' => $this->user->id,
            'document_type' => 'id_document',
            'document_subtype' => null,
            'file_path' => "employees/{$this->employee->id}/onboarding-submissions/{$submission->id}/legacy-id.enc",
            'file_name' => 'legacy-passport.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
        ]);

        $response = $this->withToken($this->token)
            ->postJson('/v1/onboarding/submissions', [
                'form_template_id' => $template->id,
                'form_data' => [
                    'nationalities' => ['DE'],
                    'id_document_upload_now' => 'yes',
                    'id_document_kind' => 'passport',
                ],
                'status' => 'submitted',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['id_document_upload_now']);
    });

    test('does not require residence permit fields on templates that only keep copied nationalities', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        $template = OnboardingFormTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_required' => true,
            'form_schema' => [
                'type' => 'object',
                'properties' => [
                    'gender' => [
                        'type' => 'string',
                        'enum' => ['male', 'female', 'diverse'],
                    ],
                ],
                'required' => ['gender'],
            ],
        ]);

        $response = $this->withToken($this->token)
            ->postJson('/v1/onboarding/submissions', [
                'form_template_id' => $template->id,
                'form_data' => [
                    'gender' => 'male',
                    'nationalities' => ['IN'],
                ],
                'status' => 'submitted',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'submitted');
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

    test('skips full schema enforcement for optional templates with missing array fields on submit', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        $template = OnboardingFormTemplate::factory()->optional()->create([
            'tenant_id' => $this->tenant->id,
            'form_schema' => [
                'type' => 'object',
                'properties' => [
                    'nationalities' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'minItems' => 1,
                    ],
                ],
                'required' => ['nationalities'],
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

    test('returns 422 when employee submits a template from a different tenant', function (): void {
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

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['form_template_id']);
    });

    test('returns 422 when employee submits a deleted template', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        $deletedTemplate = OnboardingFormTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
            'form_schema' => [
                'type' => 'object',
                'properties' => ['name' => ['type' => 'string']],
                'required' => ['name'],
            ],
        ]);
        $deletedTemplate->delete();

        $response = $this->withToken($this->token)
            ->postJson('/v1/onboarding/submissions', [
                'form_template_id' => $deletedTemplate->id,
                'form_data' => ['name' => 'test'],
                'status' => 'draft',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['form_template_id']);
    });

    test('returns 422 when employee submits a nonexistent template identifier', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        $response = $this->withToken($this->token)
            ->postJson('/v1/onboarding/submissions', [
                'form_template_id' => (string) Illuminate\Support\Str::uuid(),
                'form_data' => ['name' => 'test'],
                'status' => 'draft',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['form_template_id']);
    });

    test('does not skip schema validation for undeclared keys when additional properties are forbidden on optional templates', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        $template = OnboardingFormTemplate::factory()->optional()->create([
            'tenant_id' => $this->tenant->id,
            'form_schema' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                ],
                'additionalProperties' => false,
            ],
        ]);

        $response = $this->withToken($this->token)
            ->postJson('/v1/onboarding/submissions', [
                'form_template_id' => $template->id,
                'form_data' => ['unknown_key' => 'unexpected'],
                'status' => 'submitted',
            ]);

        $response->assertStatus(422);
    });

    test('does not treat undeclared keys as semantically empty when additional properties define their schema on optional templates', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        $template = OnboardingFormTemplate::factory()->optional()->create([
            'tenant_id' => $this->tenant->id,
            'form_schema' => [
                'type' => 'object',
                'additionalProperties' => ['type' => 'integer'],
            ],
        ]);

        $response = $this->withToken($this->token)
            ->postJson('/v1/onboarding/submissions', [
                'form_template_id' => $template->id,
                'form_data' => ['dynamic_key' => 'unexpected'],
                'status' => 'submitted',
            ]);

        $response->assertStatus(422);
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
    });

    test('does not treat non-string values for string fields as semantically empty on submit', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        $template = OnboardingFormTemplate::factory()->optional()->create([
            'tenant_id' => $this->tenant->id,
            'form_schema' => [
                'type' => 'object',
                'properties' => [
                    'name' => [
                        'type' => 'string',
                        'minLength' => 1,
                    ],
                ],
                'required' => ['name'],
            ],
        ]);

        // 5 is not a valid string value; it must not be silently treated as "empty"
        // but must trigger schema validation and return 422.
        $response = $this->withToken($this->token)
            ->postJson('/v1/onboarding/submissions', [
                'form_template_id' => $template->id,
                'form_data' => ['name' => 5],
                'status' => 'submitted',
            ]);

        $response->assertStatus(422);
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

    test('does not treat a non-array value for an array-type field as semantically empty on submit', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        $template = OnboardingFormTemplate::factory()->optional()->create([
            'tenant_id' => $this->tenant->id,
            'form_schema' => [
                'type' => 'object',
                'properties' => [
                    'nationalities' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'minItems' => 1,
                    ],
                ],
                'required' => ['nationalities'],
            ],
        ]);

        // 'DE' is not a valid array; it must not be silently treated as "empty"
        // but must trigger schema validation and return 422.
        $response = $this->withToken($this->token)
            ->postJson('/v1/onboarding/submissions', [
                'form_template_id' => $template->id,
                'form_data' => ['nationalities' => 'DE'],
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

    test('merges patch form data with the existing draft payload before validating a submitted update', function (): void {
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
                    'account_holder' => [
                        'type' => 'string',
                        'minLength' => 1,
                    ],
                ],
                'required' => ['iban', 'account_holder'],
            ],
        ]);

        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $template->id,
            'form_data' => [
                'iban' => 'DE44500105175407324931',
            ],
            'status' => 'draft',
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/v1/onboarding/submissions/{$submission->id}", [
                'form_data' => [
                    'account_holder' => 'Jane Doe',
                ],
                'status' => 'submitted',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.form_data.iban', 'DE44500105175407324931')
            ->assertJsonPath('data.form_data.account_holder', 'Jane Doe');
    });

    test('rejects patch submit when merged form data becomes invalid', function (): void {
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
                    'account_holder' => [
                        'type' => 'string',
                        'minLength' => 1,
                    ],
                ],
                'required' => ['iban', 'account_holder'],
            ],
        ]);

        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $template->id,
            'form_data' => [
                'iban' => 'DE44500105175407324931',
                'account_holder' => 'Jane Doe',
            ],
            'status' => 'draft',
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/v1/onboarding/submissions/{$submission->id}", [
                'form_data' => [
                    'iban' => 'not-an-iban',
                ],
                'status' => 'submitted',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['iban']);
    });

    test('deep-merges nested associative objects on patch without dropping stored sibling keys', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        $template = OnboardingFormTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_required' => true,
            'form_schema' => [
                'type' => 'object',
                'properties' => [
                    'address' => [
                        'type' => 'object',
                        'properties' => [
                            'city' => ['type' => 'string'],
                            'country' => ['type' => 'string'],
                        ],
                        'required' => ['city', 'country'],
                    ],
                ],
                'required' => ['address'],
            ],
        ]);

        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $template->id,
            'form_data' => ['address' => ['country' => 'DE']],
            'status' => 'draft',
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/v1/onboarding/submissions/{$submission->id}", [
                'form_data' => ['address' => ['city' => 'Berlin']],
                'status' => 'submitted',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.form_data.address.city', 'Berlin')
            ->assertJsonPath('data.form_data.address.country', 'DE');
    });

    test('removes a stored top-level key when patch form data sets it to null', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        $template = OnboardingFormTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_required' => false,
            'form_schema' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                    'nickname' => ['type' => 'string'],
                ],
            ],
        ]);

        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $template->id,
            'form_data' => [
                'name' => 'Jane Doe',
                'nickname' => 'JD',
            ],
            'status' => 'draft',
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/v1/onboarding/submissions/{$submission->id}", [
                'form_data' => [
                    'nickname' => null,
                ],
                'status' => 'draft',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.form_data.name', 'Jane Doe');

        expect($response->json('data.form_data'))
            ->not->toHaveKey('nickname');

        expect($submission->fresh()->form_data)
            ->toBe(['name' => 'Jane Doe']);
    });

    test('removes a stored nested key when patch form data sets it to null', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        $template = OnboardingFormTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_required' => false,
            'form_schema' => [
                'type' => 'object',
                'properties' => [
                    'address' => [
                        'type' => 'object',
                        'properties' => [
                            'city' => ['type' => 'string'],
                            'country' => ['type' => 'string'],
                            'line2' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ]);

        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $template->id,
            'form_data' => [
                'address' => [
                    'city' => 'Berlin',
                    'country' => 'DE',
                    'line2' => 'Floor 3',
                ],
            ],
            'status' => 'draft',
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/v1/onboarding/submissions/{$submission->id}", [
                'form_data' => [
                    'address' => [
                        'line2' => null,
                    ],
                ],
                'status' => 'draft',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.form_data.address.city', 'Berlin')
            ->assertJsonPath('data.form_data.address.country', 'DE');

        expect($response->json('data.form_data.address'))
            ->not->toHaveKey('line2');

        expect($submission->fresh()->form_data)
            ->toBe([
                'address' => [
                    'city' => 'Berlin',
                    'country' => 'DE',
                ],
            ]);
    });

    test('rejects patch submit when null sentinel removes a required field from the effective payload', function (): void {
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
                    'account_holder' => [
                        'type' => 'string',
                        'minLength' => 1,
                    ],
                ],
                'required' => ['iban', 'account_holder'],
            ],
        ]);

        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $template->id,
            'form_data' => [
                'iban' => 'DE44500105175407324931',
                'account_holder' => 'Jane Doe',
            ],
            'status' => 'draft',
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/v1/onboarding/submissions/{$submission->id}", [
                'form_data' => [
                    'iban' => null,
                ],
                'status' => 'submitted',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['form_data']);
    });

    test('replaces a list-type nested value on patch without deep-merging it', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        $template = OnboardingFormTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_required' => true,
            'form_schema' => [
                'type' => 'object',
                'properties' => [
                    'languages' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                ],
                'required' => ['languages'],
            ],
        ]);

        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $template->id,
            'form_data' => ['languages' => ['fr']],
            'status' => 'draft',
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/v1/onboarding/submissions/{$submission->id}", [
                'form_data' => ['languages' => ['en', 'de']],
                'status' => 'submitted',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.form_data.languages', ['en', 'de']);

        expect($submission->fresh()->form_data['languages'])->toBe(['en', 'de']);
    });

    test('ignores a root list-type form_data payload and preserves the stored object without numeric key corruption', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        $template = OnboardingFormTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_required' => true,
            'form_schema' => [
                'type' => 'object',
                'properties' => ['name' => ['type' => 'string']],
                'required' => ['name'],
            ],
        ]);

        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $template->id,
            'form_data' => ['name' => 'Stored'],
            'status' => 'draft',
        ]);

        // A list-type form_data must not corrupt the stored object with numeric keys.
        // The list is treated as if no form_data was provided; stored data is preserved.
        $response = $this->withToken($this->token)
            ->patchJson("/v1/onboarding/submissions/{$submission->id}", [
                'form_data' => ['unexpected', 'list'],
                'status' => 'draft',
            ]);

        $response->assertOk();

        $fresh = $submission->fresh();
        expect($fresh->form_data)
            ->not->toHaveKey(0)
            ->not->toHaveKey(1)
            ->and($fresh->form_data['name'])->toBe('Stored');
    });

    test('returns 422 when patch submit provides form data that violates the template schema', function (): void {
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

        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $template->id,
            'status' => 'draft',
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/v1/onboarding/submissions/{$submission->id}", [
                'form_data' => ['iban' => 'not-an-iban'],
                'status' => 'submitted',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['iban']);
    });

    test('returns 422 when a rejected submission is resubmitted with schema-invalid form data', function (): void {
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

        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $template->id,
            'status' => 'rejected',
            'review_notes' => 'Please correct the banking data.',
            'reviewed_at' => now(),
            'reviewed_by' => $this->user->id,
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/v1/onboarding/submissions/{$submission->id}", [
                'form_data' => ['iban' => 'not-an-iban'],
                'status' => 'submitted',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['iban']);
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

    test('rejects patch submit when existing residence permit expiry date is in the past', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        $template = OnboardingFormTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_required' => true,
            'form_schema' => [
                'type' => 'object',
                'properties' => [
                    'residence_permit_expiry' => [
                        'type' => 'string',
                        'pattern' => '^\d{4}-\d{2}-\d{2}$',
                    ],
                ],
                'required' => ['residence_permit_expiry'],
            ],
        ]);

        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $template->id,
            'form_data' => [
                'residence_permit_expiry' => now()->subDay()->toDateString(),
            ],
            'status' => 'draft',
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/v1/onboarding/submissions/{$submission->id}", [
                'status' => 'submitted',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['residence_permit_expiry']);
    });

    test('rejects patch submit when existing residence title has employment not permitted', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        $template = OnboardingFormTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_required' => true,
            'form_schema' => [
                'type' => 'object',
                'properties' => [
                    'residence_permit_title' => ['type' => 'string'],
                    'residence_permit_employment_allowed' => [
                        'type' => 'string',
                        'enum' => ['yes', 'no'],
                    ],
                ],
                'required' => ['residence_permit_title', 'residence_permit_employment_allowed'],
            ],
        ]);

        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $template->id,
            'form_data' => [
                'residence_permit_title' => 'Aufenthaltserlaubnis',
                'residence_permit_employment_allowed' => 'no',
            ],
            'status' => 'draft',
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/v1/onboarding/submissions/{$submission->id}", [
                'status' => 'submitted',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['residence_permit_employment_allowed']);
    });

    test('accepts patch submit for exempt nationalities even when stale residence permit fields remain', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        $template = OnboardingFormTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_required' => true,
            'form_schema' => [
                'type' => 'object',
                'properties' => [
                    'nationalities' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'string',
                            'pattern' => '^[A-Z]{2}$',
                        ],
                        'minItems' => 1,
                    ],
                    'residence_permit_title' => ['type' => 'string'],
                    'residence_permit_employment_allowed' => [
                        'type' => 'string',
                        'enum' => ['yes', 'no'],
                    ],
                    'residence_permit_expiry' => [
                        'type' => 'string',
                        'pattern' => '^\d{4}-\d{2}-\d{2}$',
                    ],
                ],
                'required' => ['nationalities'],
            ],
        ]);

        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $template->id,
            'form_data' => [
                'nationalities' => ['DE'],
                'id_document_upload_now' => 'no',
                'residence_permit_title' => 'Aufenthaltserlaubnis',
                'residence_permit_employment_allowed' => 'no',
                'residence_permit_expiry' => now()->subDay()->toDateString(),
            ],
            'status' => 'draft',
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/v1/onboarding/submissions/{$submission->id}", [
                'status' => 'submitted',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'submitted');
    });

    test('rejects patch submit when existing employment authorization value is invalid', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        $template = OnboardingFormTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_required' => true,
            'form_schema' => [
                'type' => 'object',
                'properties' => [
                    'residence_permit_title' => ['type' => 'string'],
                    'residence_permit_employment_allowed' => ['type' => 'string'],
                ],
                'required' => ['residence_permit_title', 'residence_permit_employment_allowed'],
            ],
        ]);

        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $template->id,
            'form_data' => [
                'residence_permit_title' => 'Aufenthaltserlaubnis',
                'residence_permit_employment_allowed' => 'sometimes',
            ],
            'status' => 'draft',
        ]);

        $response = $this->withToken($this->token)
            ->patchJson("/v1/onboarding/submissions/{$submission->id}", [
                'status' => 'submitted',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['residence_permit_employment_allowed']);
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

    test('requires a document_subtype when uploading id_document files', function (): void {
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
                'file' => UploadedFile::fake()->create('id.pdf', 100, 'application/pdf'),
                'document_type' => 'id_document',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['document_subtype']);
    });

    test('stores document_subtype for id_document uploads', function (): void {
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
                'file' => UploadedFile::fake()->create('passport.pdf', 100, 'application/pdf'),
                'document_type' => 'id_document',
                'document_subtype' => 'identity_document',
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('onboarding_submission_files', [
            'onboarding_form_submission_id' => $submission->id,
            'document_type' => 'id_document',
            'document_subtype' => 'identity_document',
            'file_name' => 'passport.pdf',
        ]);
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

    test('returns a detailed upload error when PHP rejects the file before validation', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.write');

        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $this->template->id,
            'status' => 'draft',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'upload-fail-');
        expect($path)->not->toBeFalse();
        file_put_contents($path, 'x');

        $brokenSymfonyFile = new SymfonyUploadedFile(
            $path,
            'too-large.pdf',
            'application/pdf',
            UPLOAD_ERR_INI_SIZE,
            true
        );
        $brokenFile = UploadedFile::createFromBase($brokenSymfonyFile, true);

        $response = $this->withToken($this->token)
            ->withHeaders(['Accept' => 'application/json'])
            ->post("/v1/onboarding/submissions/{$submission->id}/files", [
                'file' => $brokenFile,
                'document_type' => 'contract',
            ]);

        @unlink($path);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);

        $firstFileError = (string) ($response->json('errors.file.0') ?? '');
        expect($firstFileError)->toContain('upload_max_filesize')
            ->and($firstFileError)->toContain('post_max_size');
    });
});

describe('DELETE /v1/onboarding/submissions/{submission}/files/{file}', function () {
    test('deletes an uploaded file for an editable own submission', function (): void {
        Storage::fake('local');

        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $this->template->id,
            'status' => 'draft',
        ]);

        $path = "employees/{$this->employee->id}/onboarding-submissions/{$submission->id}/test.enc";
        Storage::disk('local')->put($path, '{"ciphertext":"abc","nonce":"def"}');

        $uploadedFile = OnboardingSubmissionFile::create([
            'onboarding_form_submission_id' => $submission->id,
            'uploaded_by' => $this->user->id,
            'document_type' => 'id_document',
            'document_subtype' => 'identity_document',
            'file_path' => $path,
            'file_name' => 'passport.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
        ]);

        $response = $this->withToken($this->token)
            ->delete("/v1/onboarding/submissions/{$submission->id}/files/{$uploadedFile->id}");

        $response->assertNoContent();

        Storage::disk('local')->assertMissing($path);
        expect(OnboardingSubmissionFile::withTrashed()->find($uploadedFile->id)?->deleted_at)->not->toBeNull();
    });

    test('returns 404 when file does not belong to the submission', function (): void {
        Storage::fake('local');

        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $this->template->id,
            'status' => 'draft',
        ]);

        $otherSubmission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => OnboardingFormTemplate::factory()->create([
                'tenant_id' => $this->tenant->id,
            ])->id,
            'status' => 'draft',
        ]);

        $uploadedFile = OnboardingSubmissionFile::create([
            'onboarding_form_submission_id' => $otherSubmission->id,
            'uploaded_by' => $this->user->id,
            'document_type' => 'id_document',
            'document_subtype' => 'identity_document',
            'file_path' => "employees/{$this->employee->id}/onboarding-submissions/{$otherSubmission->id}/test.enc",
            'file_name' => 'passport.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
        ]);

        $response = $this->withToken($this->token)
            ->delete("/v1/onboarding/submissions/{$submission->id}/files/{$uploadedFile->id}");

        $response->assertStatus(404);
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

    test('approving tax identification submission stores identifiers on employee', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.approve');
        $this->employee->update([
            'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_SUBMITTED_FOR_REVIEW,
            'tax_id' => null,
            'social_security_number' => null,
        ]);

        $template = OnboardingFormTemplate::factory()->create([
            'tenant_id' => null,
            'is_system_template' => true,
            'template_key' => 'tax_identification_number',
            'name' => 'Tax Identification Number',
        ]);
        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $template->id,
            'form_data' => [
                'tax_id' => '12345678901',
                'social_security_number' => '65 123456 A 123',
            ],
            'status' => 'submitted',
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/v1/onboarding-review/submissions/{$submission->id}/approve");

        $response->assertOk();

        $freshEmployee = $this->employee->fresh();
        expect($freshEmployee->tax_id)->toBe('12345678901')
            ->and($freshEmployee->social_security_number)->toBe('65 123456 A 123');
    });

    test('approval changes are rolled back when completion check fails', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.approve');
        $this->employee->update([
            'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_SUBMITTED_FOR_REVIEW,
        ]);

        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $this->template->id,
            'status' => 'submitted',
        ]);

        $this->mock(OnboardingCompletionService::class, function ($mock): void {
            $mock->shouldReceive('checkCompletion')
                ->once()
                ->andThrow(new RuntimeException('forced completion failure'));
        });

        $response = $this->withToken($this->token)
            ->postJson("/v1/onboarding-review/submissions/{$submission->id}/approve");

        $response->assertStatus(500);
        expect($submission->fresh()->status)->toBe('submitted')
            ->and($submission->fresh()->reviewed_by)->toBeNull()
            ->and($submission->fresh()->reviewed_at)->toBeNull();
    });

    test('tax identifiers are not synced when template key is not tax identification', function (): void {
        givePermissionWithTenant($this->user, $this->tenant->id, 'onboarding.approve');
        $this->employee->update([
            'onboarding_workflow_status' => Employee::WORKFLOW_STATUS_SUBMITTED_FOR_REVIEW,
            'tax_id' => null,
            'social_security_number' => null,
        ]);

        $template = OnboardingFormTemplate::factory()->create([
            'tenant_id' => $this->tenant->id,
            'template_key' => 'custom_tax_form',
            'name' => 'Tax Identification Follow-up',
        ]);
        $submission = OnboardingFormSubmission::factory()->create([
            'employee_id' => $this->employee->id,
            'form_template_id' => $template->id,
            'form_data' => [
                'tax_id' => '12345678901',
                'social_security_number' => '65 123456 A 123',
            ],
            'status' => 'submitted',
        ]);

        $response = $this->withToken($this->token)
            ->postJson("/v1/onboarding-review/submissions/{$submission->id}/approve");

        $response->assertOk();

        $freshEmployee = $this->employee->fresh();
        expect($freshEmployee->tax_id)->toBeNull()
            ->and($freshEmployee->social_security_number)->toBeNull();
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

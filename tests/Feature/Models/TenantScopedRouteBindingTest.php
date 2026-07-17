<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

use App\Models\Activity;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\EmployeeQualification;
use App\Models\OnboardingFormSubmission;
use App\Models\OnboardingFormTemplate;
use App\Models\OrganizationalUnit;
use App\Models\Person;
use App\Models\Qualification;
use App\Models\Site;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/**
 * @return array{tenant: TenantKey, otherTenant: TenantKey}
 */
function createTenantRouteBindingContext(): array
{
    $tenantKeys = TenantKey::generateEnvelopeKeys();
    $tenant = TenantKey::create($tenantKeys);

    $otherTenantKeys = TenantKey::generateEnvelopeKeys();
    $otherTenant = TenantKey::create($otherTenantKeys);

    $authUser = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);

    actingAs($authUser, 'sanctum');

    return [
        'tenant' => $tenant,
        'otherTenant' => $otherTenant,
    ];
}

beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('user route binding resolves only within the authenticated tenant', function (): void {
    ['tenant' => $tenant, 'otherTenant' => $otherTenant] = createTenantRouteBindingContext();

    $sameTenantUser = User::factory()->create([
        'tenant_id' => $tenant->id,
    ]);
    $otherTenantUser = User::factory()->create([
        'tenant_id' => $otherTenant->id,
    ]);

    /** @var User|null $resolvedSameTenantUser */
    $resolvedSameTenantUser = (new User)->resolveRouteBindingQuery(User::query(), $sameTenantUser->id)->first();
    /** @var User|null $resolvedOtherTenantUser */
    $resolvedOtherTenantUser = (new User)->resolveRouteBindingQuery(User::query(), $otherTenantUser->id)->first();

    expect($resolvedSameTenantUser?->id)->toBe($sameTenantUser->id)
        ->and($resolvedOtherTenantUser)->toBeNull();
});

test('customer route binding resolves only within the authenticated tenant', function (): void {
    ['tenant' => $tenant, 'otherTenant' => $otherTenant] = createTenantRouteBindingContext();

    $sameTenantCustomer = Customer::factory()->forTenant($tenant->id)->create();
    $otherTenantCustomer = Customer::factory()->forTenant($otherTenant->id)->create();

    /** @var Customer|null $resolvedSameTenantCustomer */
    $resolvedSameTenantCustomer = (new Customer)->resolveRouteBindingQuery(Customer::query(), $sameTenantCustomer->id)->first();
    /** @var Customer|null $resolvedOtherTenantCustomer */
    $resolvedOtherTenantCustomer = (new Customer)->resolveRouteBindingQuery(Customer::query(), $otherTenantCustomer->id)->first();

    expect($resolvedSameTenantCustomer?->id)->toBe($sameTenantCustomer->id)
        ->and($resolvedOtherTenantCustomer)->toBeNull();
});

test('employee route binding resolves only within the authenticated tenant', function (): void {
    ['tenant' => $tenant, 'otherTenant' => $otherTenant] = createTenantRouteBindingContext();

    $sameTenantEmployee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
    ]);
    $otherTenantEmployee = Employee::factory()->create([
        'tenant_id' => $otherTenant->id,
    ]);

    /** @var Employee|null $resolvedSameTenantEmployee */
    $resolvedSameTenantEmployee = (new Employee)->resolveRouteBindingQuery(Employee::query(), $sameTenantEmployee->id)->first();
    /** @var Employee|null $resolvedOtherTenantEmployee */
    $resolvedOtherTenantEmployee = (new Employee)->resolveRouteBindingQuery(Employee::query(), $otherTenantEmployee->id)->first();

    expect($resolvedSameTenantEmployee?->id)->toBe($sameTenantEmployee->id)
        ->and($resolvedOtherTenantEmployee)->toBeNull();
});

test('organizational unit route binding resolves only within the authenticated tenant', function (): void {
    ['tenant' => $tenant, 'otherTenant' => $otherTenant] = createTenantRouteBindingContext();

    $sameTenantUnit = OrganizationalUnit::factory()->create([
        'tenant_id' => $tenant->id,
    ]);
    $otherTenantUnit = OrganizationalUnit::factory()->create([
        'tenant_id' => $otherTenant->id,
    ]);

    /** @var OrganizationalUnit|null $resolvedSameTenantUnit */
    $resolvedSameTenantUnit = (new OrganizationalUnit)->resolveRouteBindingQuery(OrganizationalUnit::query(), $sameTenantUnit->id)->first();
    /** @var OrganizationalUnit|null $resolvedOtherTenantUnit */
    $resolvedOtherTenantUnit = (new OrganizationalUnit)->resolveRouteBindingQuery(OrganizationalUnit::query(), $otherTenantUnit->id)->first();

    expect($resolvedSameTenantUnit?->id)->toBe($sameTenantUnit->id)
        ->and($resolvedOtherTenantUnit)->toBeNull();
});

test('site route binding resolves only within the authenticated tenant', function (): void {
    ['tenant' => $tenant, 'otherTenant' => $otherTenant] = createTenantRouteBindingContext();

    $sameTenantCustomer = Customer::factory()->forTenant($tenant->id)->create();
    $otherTenantCustomer = Customer::factory()->forTenant($otherTenant->id)->create();

    $sameTenantSite = Site::factory()->create([
        'tenant_id' => $tenant->id,
        'customer_id' => $sameTenantCustomer->id,
    ]);
    $otherTenantUnit = OrganizationalUnit::factory()->forTenant($otherTenant->id)->create();
    $otherTenantSite = Site::factory()->create([
        'tenant_id' => $otherTenant->id,
        'customer_id' => $otherTenantCustomer->id,
    ]);

    /** @var Site|null $resolvedSameTenantSite */
    $resolvedSameTenantSite = (new Site)->resolveRouteBindingQuery(Site::query(), $sameTenantSite->id)->first();
    /** @var Site|null $resolvedOtherTenantSite */
    $resolvedOtherTenantSite = (new Site)->resolveRouteBindingQuery(Site::query(), $otherTenantSite->id)->first();

    expect($resolvedSameTenantSite?->id)->toBe($sameTenantSite->id)
        ->and($resolvedOtherTenantSite)->toBeNull();
});

test('person route binding resolves only within the authenticated tenant', function (): void {
    ['tenant' => $tenant, 'otherTenant' => $otherTenant] = createTenantRouteBindingContext();

    $sameTenantPerson = Person::factory()->create([
        'tenant_id' => $tenant->id,
    ]);
    $otherTenantPerson = Person::factory()->create([
        'tenant_id' => $otherTenant->id,
    ]);

    /** @var Person|null $resolvedSameTenantPerson */
    $resolvedSameTenantPerson = (new Person)->resolveRouteBindingQuery(Person::query(), $sameTenantPerson->id)->first();
    /** @var Person|null $resolvedOtherTenantPerson */
    $resolvedOtherTenantPerson = (new Person)->resolveRouteBindingQuery(Person::query(), $otherTenantPerson->id)->first();

    expect($resolvedSameTenantPerson?->id)->toBe($sameTenantPerson->id)
        ->and($resolvedOtherTenantPerson)->toBeNull();
});

test('tenant route binding rejects invalid UUID values before querying UUID primary keys', function (): void {
    createTenantRouteBindingContext();

    expect(fn () => (new Customer)->resolveRouteBindingQuery(Customer::query(), '1'))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => (new Employee)->resolveRouteBindingQuery(Employee::query(), '1'))
        ->toThrow(ModelNotFoundException::class);
});

test('tenant route binding formats bool and null invalid route keys via var_export', function (): void {
    createTenantRouteBindingContext();

    expect(fn () => (new Customer)->resolveRouteBindingQuery(Customer::query(), true))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => (new Customer)->resolveRouteBindingQuery(Customer::query(), null))
        ->toThrow(ModelNotFoundException::class);
});

test('tenant route binding formats non-scalar invalid route keys via get_debug_type', function (): void {
    createTenantRouteBindingContext();

    expect(fn () => (new Customer)->resolveRouteBindingQuery(Customer::query(), []))
        ->toThrow(ModelNotFoundException::class);
});

test('qualification route binding includes global records and rejects other tenant records', function (): void {
    ['tenant' => $tenant, 'otherTenant' => $otherTenant] = createTenantRouteBindingContext();

    $sameTenantQualification = Qualification::factory()->create([
        'tenant_id' => $tenant->id,
        'is_system_qualification' => false,
    ]);
    $globalQualification = Qualification::factory()->create([
        'tenant_id' => null,
        'is_system_qualification' => true,
    ]);
    $otherTenantQualification = Qualification::factory()->create([
        'tenant_id' => $otherTenant->id,
        'is_system_qualification' => false,
    ]);

    /** @var Qualification|null $resolvedSameTenantQualification */
    $resolvedSameTenantQualification = (new Qualification)->resolveRouteBindingQuery(Qualification::query(), $sameTenantQualification->id)->first();
    /** @var Qualification|null $resolvedGlobalQualification */
    $resolvedGlobalQualification = (new Qualification)->resolveRouteBindingQuery(Qualification::query(), $globalQualification->id)->first();
    /** @var Qualification|null $resolvedOtherTenantQualification */
    $resolvedOtherTenantQualification = (new Qualification)->resolveRouteBindingQuery(Qualification::query(), $otherTenantQualification->id)->first();

    expect($resolvedSameTenantQualification?->id)->toBe($sameTenantQualification->id)
        ->and($resolvedGlobalQualification?->id)->toBe($globalQualification->id)
        ->and($resolvedOtherTenantQualification)->toBeNull();
});

test('employee qualification route binding resolves only through same-tenant employees', function (): void {
    ['tenant' => $tenant, 'otherTenant' => $otherTenant] = createTenantRouteBindingContext();

    $sameTenantEmployee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
    ]);
    $otherTenantEmployee = Employee::factory()->create([
        'tenant_id' => $otherTenant->id,
    ]);
    $sameTenantQualification = Qualification::factory()->create([
        'tenant_id' => $tenant->id,
    ]);
    $otherTenantQualification = Qualification::factory()->create([
        'tenant_id' => $otherTenant->id,
    ]);

    $sameTenantEmployeeQualification = EmployeeQualification::factory()->create([
        'employee_id' => $sameTenantEmployee->id,
        'qualification_id' => $sameTenantQualification->id,
    ]);
    $otherTenantEmployeeQualification = EmployeeQualification::factory()->create([
        'employee_id' => $otherTenantEmployee->id,
        'qualification_id' => $otherTenantQualification->id,
    ]);

    /** @var EmployeeQualification|null $resolvedSameTenantEmployeeQualification */
    $resolvedSameTenantEmployeeQualification = (new EmployeeQualification)->resolveRouteBindingQuery(EmployeeQualification::query(), $sameTenantEmployeeQualification->id)->first();
    /** @var EmployeeQualification|null $resolvedOtherTenantEmployeeQualification */
    $resolvedOtherTenantEmployeeQualification = (new EmployeeQualification)->resolveRouteBindingQuery(EmployeeQualification::query(), $otherTenantEmployeeQualification->id)->first();

    expect($resolvedSameTenantEmployeeQualification?->id)->toBe($sameTenantEmployeeQualification->id)
        ->and($resolvedOtherTenantEmployeeQualification)->toBeNull();
});

test('onboarding submission route binding resolves only through same-tenant employees', function (): void {
    ['tenant' => $tenant, 'otherTenant' => $otherTenant] = createTenantRouteBindingContext();

    $sameTenantEmployee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
    ]);
    $otherTenantEmployee = Employee::factory()->create([
        'tenant_id' => $otherTenant->id,
    ]);
    $sameTenantTemplate = OnboardingFormTemplate::factory()->create([
        'tenant_id' => $tenant->id,
    ]);
    $otherTenantTemplate = OnboardingFormTemplate::factory()->create([
        'tenant_id' => $otherTenant->id,
    ]);

    $sameTenantSubmission = OnboardingFormSubmission::factory()->create([
        'employee_id' => $sameTenantEmployee->id,
        'form_template_id' => $sameTenantTemplate->id,
    ]);
    $otherTenantSubmission = OnboardingFormSubmission::factory()->create([
        'employee_id' => $otherTenantEmployee->id,
        'form_template_id' => $otherTenantTemplate->id,
    ]);

    /** @var OnboardingFormSubmission|null $resolvedSameTenantSubmission */
    $resolvedSameTenantSubmission = (new OnboardingFormSubmission)->resolveRouteBindingQuery(OnboardingFormSubmission::query(), $sameTenantSubmission->id)->first();
    /** @var OnboardingFormSubmission|null $resolvedOtherTenantSubmission */
    $resolvedOtherTenantSubmission = (new OnboardingFormSubmission)->resolveRouteBindingQuery(OnboardingFormSubmission::query(), $otherTenantSubmission->id)->first();

    expect($resolvedSameTenantSubmission?->id)->toBe($sameTenantSubmission->id)
        ->and($resolvedOtherTenantSubmission)->toBeNull();
});

test('onboarding template route binding includes global records and rejects other tenant records', function (): void {
    ['tenant' => $tenant, 'otherTenant' => $otherTenant] = createTenantRouteBindingContext();

    $sameTenantTemplate = OnboardingFormTemplate::factory()->create([
        'tenant_id' => $tenant->id,
        'is_system_template' => false,
    ]);
    $globalTemplate = OnboardingFormTemplate::factory()->create([
        'tenant_id' => null,
        'is_system_template' => true,
    ]);
    $otherTenantTemplate = OnboardingFormTemplate::factory()->create([
        'tenant_id' => $otherTenant->id,
        'is_system_template' => false,
    ]);

    /** @var OnboardingFormTemplate|null $resolvedSameTenantTemplate */
    $resolvedSameTenantTemplate = (new OnboardingFormTemplate)->resolveRouteBindingQuery(OnboardingFormTemplate::query(), $sameTenantTemplate->id)->first();
    /** @var OnboardingFormTemplate|null $resolvedGlobalTemplate */
    $resolvedGlobalTemplate = (new OnboardingFormTemplate)->resolveRouteBindingQuery(OnboardingFormTemplate::query(), $globalTemplate->id)->first();
    /** @var OnboardingFormTemplate|null $resolvedOtherTenantTemplate */
    $resolvedOtherTenantTemplate = (new OnboardingFormTemplate)->resolveRouteBindingQuery(OnboardingFormTemplate::query(), $otherTenantTemplate->id)->first();

    expect($resolvedSameTenantTemplate?->id)->toBe($sameTenantTemplate->id)
        ->and($resolvedGlobalTemplate?->id)->toBe($globalTemplate->id)
        ->and($resolvedOtherTenantTemplate)->toBeNull();
});

test('activity route binding resolves only within the authenticated tenant', function (): void {
    ['tenant' => $tenant, 'otherTenant' => $otherTenant] = createTenantRouteBindingContext();

    $sameTenantActivity = Activity::factory()->create([
        'tenant_id' => $tenant->id,
    ]);
    $otherTenantActivity = Activity::factory()->create([
        'tenant_id' => $otherTenant->id,
    ]);

    /** @var Activity|null $resolvedSameTenantActivity */
    $resolvedSameTenantActivity = (new Activity)->resolveRouteBindingQuery(Activity::query(), $sameTenantActivity->id)->first();
    /** @var Activity|null $resolvedOtherTenantActivity */
    $resolvedOtherTenantActivity = (new Activity)->resolveRouteBindingQuery(Activity::query(), $otherTenantActivity->id)->first();

    expect($resolvedSameTenantActivity?->id)->toBe($sameTenantActivity->id)
        ->and($resolvedOtherTenantActivity)->toBeNull();
});

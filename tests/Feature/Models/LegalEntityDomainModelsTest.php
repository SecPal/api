<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

declare(strict_types=1);

use App\Models\Customer;
use App\Models\CustomerEstablishment;
use App\Models\Establishment;
use App\Models\LegalEntity;
use App\Models\OrganizationalUnit;
use App\Models\TenantKey;
use App\Models\User;
use App\Models\UserInternalOrganizationalScope;
use App\Policies\EmployeePolicy;
use App\Policies\SitePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('domain factories create one tenant-consistent legal entity graph', function (): void {
    $link = CustomerEstablishment::factory()->create();

    expect($link->customer)->toBeInstanceOf(Customer::class)
        ->and($link->establishment)->toBeInstanceOf(Establishment::class)
        ->and($link->customer->legalEntity)->toBeInstanceOf(LegalEntity::class)
        ->and($link->establishment->legalEntity)->toBeInstanceOf(LegalEntity::class)
        ->and($link->tenant_id)->toBe($link->customer->tenant_id)
        ->and($link->tenant_id)->toBe($link->establishment->tenant_id)
        ->and($link->customer->legal_entity_id)->toBe($link->establishment->legal_entity_id);
});

test('legal entity and establishment expose explicit domain relationships', function (): void {
    $legalEntity = LegalEntity::factory()->create();
    $establishment = Establishment::factory()->for($legalEntity)->create([
        'tenant_id' => $legalEntity->tenant_id,
    ]);

    expect($legalEntity->tenant())->toBeInstanceOf(BelongsTo::class)
        ->and($legalEntity->establishments())->toBeInstanceOf(HasMany::class)
        ->and($legalEntity->customers())->toBeInstanceOf(HasMany::class)
        ->and($establishment->tenant())->toBeInstanceOf(BelongsTo::class)
        ->and($establishment->legalEntity())->toBeInstanceOf(BelongsTo::class)
        ->and($establishment->customerEstablishments())->toBeInstanceOf(HasMany::class);
});

test('customer establishment stores local contact data outside customer master data', function (): void {
    $link = CustomerEstablishment::factory()->create([
        'contact_name' => 'Local Contact',
        'phone' => '+49 30 123456',
        'email' => 'local@example.com',
        'comments' => 'Local instructions',
    ]);

    expect($link->contact_name)->toBe('Local Contact')
        ->and($link->customer->getAttributes())->not->toHaveKeys(['contact', 'notes', 'metadata']);
});

test('domain creation policies do not infer Legal Entity access from OU scopes', function (): void {
    $tenant = TenantKey::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
    Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    givePermissionWithTenant($user, $tenant->id, 'employee.create');
    givePermissionWithTenant($user, $tenant->id, 'sites.create');

    $unit = OrganizationalUnit::factory()->create(['tenant_id' => $tenant->id]);
    UserInternalOrganizationalScope::factory()->create([
        'user_id' => $user->id,
        'organizational_unit_id' => $unit->id,
        'access_level' => 'write',
    ]);

    expect(app(EmployeePolicy::class)->create($user))->toBeTrue()
        ->and((new SitePolicy)->create($user))->toBeFalse();
});

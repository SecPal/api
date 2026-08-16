<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\Employee;
use App\Models\Establishment;
use App\Models\LegalEntity;
use App\Models\OrganizationalUnit;
use App\Models\TenantKey;
use App\Models\User;
use App\Policies\EmployeePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $this->tenant = TenantKey::create(TenantKey::generateEnvelopeKeys());
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
    Artisan::call('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    givePermissionWithTenant($this->user, $this->tenant->id, 'employee.read');
    givePermissionWithTenant($this->user, $this->tenant->id, 'employee.write');
    $this->legalEntity = LegalEntity::factory()->forTenant((string) $this->tenant->id)->create();
    $this->establishment = Establishment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->legalEntity->id,
    ]);
    $this->policy = app(EmployeePolicy::class);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('employee policy combines domain assignment access with existing viewable and assignable ranks', function (): void {
    $scopeUnit = OrganizationalUnit::factory()->forTenant((string) $this->tenant->id)->create();
    grantEmployeeEstablishmentAccess(
        $this->user,
        $this->tenant,
        $this->legalEntity,
        $this->establishment,
        $scopeUnit,
        maximumRank: 2,
    );
    $allowed = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->legalEntity->id,
        'establishment_id' => $this->establishment->id,
        'management_level' => 2,
    ]);
    $rankDenied = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->legalEntity->id,
        'establishment_id' => $this->establishment->id,
        'management_level' => 3,
    ]);
    $hiddenEstablishment = Establishment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->legalEntity->id,
    ]);
    $domainDenied = Employee::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $this->legalEntity->id,
        'establishment_id' => $hiddenEstablishment->id,
        'management_level' => 2,
    ]);

    expect($this->policy->view($this->user, $allowed))->toBeTrue()
        ->and($this->policy->update($this->user, $allowed))->toBeTrue()
        ->and($this->policy->view($this->user, $rankDenied))->toBeFalse()
        ->and($this->policy->update($this->user, $rankDenied))->toBeFalse()
        ->and($this->policy->view($this->user, $domainDenied))->toBeFalse();
});

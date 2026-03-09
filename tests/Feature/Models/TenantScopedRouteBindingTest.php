<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\TenantKey;
use App\Models\User;
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

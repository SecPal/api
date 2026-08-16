<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Models\CustomerEstablishment;
use App\Models\Site;
use App\Models\TenantKey;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('sites stores required domain assignments without an OU column', function (): void {
    expect(Schema::hasColumns('sites', [
        'id', 'tenant_id', 'customer_id', 'legal_entity_id', 'establishment_id',
        'site_number', 'name', 'type', 'address', 'contact', 'access_instructions',
        'notes', 'metadata', 'is_active', 'valid_from', 'valid_until',
        'created_at', 'updated_at', 'deleted_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumn('sites', 'organizational_unit_id'))->toBeFalse();
});

test('site factory creates a customer establishment backed assignment', function (): void {
    $site = Site::factory()->create();

    expect(CustomerEstablishment::query()
        ->where('tenant_id', $site->tenant_id)
        ->where('customer_id', $site->customer_id)
        ->where('establishment_id', $site->establishment_id)
        ->exists())->toBeTrue();
});

test('site number remains unique per tenant', function (): void {
    $site = Site::factory()->create(['site_number' => 'OBJ-UNIQUE']);

    Site::factory()->create([
        'tenant_id' => $site->tenant_id,
        'customer_id' => $site->customer_id,
        'legal_entity_id' => $site->legal_entity_id,
        'establishment_id' => $site->establishment_id,
        'site_number' => 'OBJ-UNIQUE',
    ]);
})->throws(QueryException::class);

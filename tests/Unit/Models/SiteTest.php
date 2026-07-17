<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

use App\Models\Customer;
use App\Models\CustomerEstablishment;
use App\Models\Establishment;
use App\Models\LegalEntity;
use App\Models\Site;
use App\Models\TenantKey;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Unit tests for Site model.
 *
 * @covers \App\Models\Site
 */
uses(RefreshDatabase::class)->group('unit', 'models', 'site');

/**
 * @property TenantKey $tenant
 */
beforeEach(function () {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());

    TenantKey::ensureKekExists();

    // Create tenant for testing
    if (! TenantKey::first()) {
        $keys = TenantKey::generateEnvelopeKeys();
        $this->tenant = TenantKey::create($keys);
    } else {
        $this->tenant = TenantKey::first();
    }
});

afterEach(function () {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

test('site can be created with factory', function () {
    $site = Site::factory()->create();

    expect($site->id)->not->toBeNull();
    expect($site->site_number)->not->toBeNull();
    expect($site->name)->not->toBeNull();
    expect($site->address)->toBeArray();
    expect($site->is_active)->toBeTrue();
});

test('site factory restores an existing soft-deleted customer establishment link', function () {
    $customer = Customer::factory()->create(['tenant_id' => $this->tenant->id]);
    $establishment = Establishment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $customer->legal_entity_id,
    ]);
    $link = CustomerEstablishment::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $customer->legal_entity_id,
        'customer_id' => $customer->id,
        'establishment_id' => $establishment->id,
    ]);
    $link->delete();

    Site::factory()->create([
        'tenant_id' => $this->tenant->id,
        'legal_entity_id' => $customer->legal_entity_id,
        'customer_id' => $customer->id,
        'establishment_id' => $establishment->id,
    ]);

    expect($link->fresh()?->trashed())->toBeFalse();
});

test('site number is auto generated with correct format', function () {
    $site = Site::factory()->create();

    // Format: OBJ-YYYY-NNNN
    $pattern = '/^OBJ-\d{4}-\d{4}$/';
    expect($site->site_number)->toMatch($pattern);
});

test('generate site number starts at 0001 for new year', function () {
    $year = now()->year;
    $number = Site::generateSiteNumber($this->tenant->id);

    $expected = sprintf('OBJ-%d-0001', $year);
    expect($number)->toBe($expected);
});

test('generate site number increments correctly', function () {
    // Create first site
    Site::factory()->create([
        'tenant_id' => $this->tenant->id,
        'site_number' => Site::generateSiteNumber($this->tenant->id),
    ]);

    // Generate next number
    $nextNumber = Site::generateSiteNumber($this->tenant->id);

    $year = now()->year;
    $expected = sprintf('OBJ-%d-0002', $year);
    expect($nextNumber)->toBe($expected);
});

test('generate site number handles soft deleted records', function () {
    // Create and soft delete a site
    $site = Site::factory()->create([
        'tenant_id' => $this->tenant->id,
        'site_number' => Site::generateSiteNumber($this->tenant->id),
    ]);
    $site->delete();

    // Next number should skip the deleted one
    $nextNumber = Site::generateSiteNumber($this->tenant->id);

    $year = now()->year;
    $expected = sprintf('OBJ-%d-0002', $year);
    expect($nextNumber)->toBe($expected);
});

test('scope active returns only active sites', function () {
    Site::factory()->create(['is_active' => true]);
    Site::factory()->create(['is_active' => true]);
    Site::factory()->create(['is_active' => false]);

    $activeSites = Site::active()->get();

    expect($activeSites)->toHaveCount(2);
    expect($activeSites->every(fn ($site) => $site->is_active))->toBeTrue();
});

test('scope permanent returns only permanent sites', function () {
    Site::factory()->permanent()->create();
    Site::factory()->permanent()->create();
    Site::factory()->temporary()->create();

    $permanentSites = Site::permanent()->get();

    expect($permanentSites)->toHaveCount(2);
    expect($permanentSites->every(fn ($site) => $site->type === 'permanent'))->toBeTrue();
});

test('scope temporary returns only temporary sites', function () {
    Site::factory()->temporary()->create();
    Site::factory()->temporary()->create();
    Site::factory()->permanent()->create();

    $temporarySites = Site::temporary()->get();

    expect($temporarySites)->toHaveCount(2);
    expect($temporarySites->every(fn ($site) => $site->type === 'temporary'))->toBeTrue();
});

test('scope currently valid filters by validity period', function () {
    // Create valid sites
    Site::factory()->create([
        'valid_from' => now()->subDays(10),
        'valid_until' => now()->addDays(10),
    ]);

    Site::factory()->create([
        'valid_from' => null,
        'valid_until' => null,
    ]);

    // Create expired site
    Site::factory()->create([
        'valid_from' => now()->subMonths(6),
        'valid_until' => now()->subDays(10),
    ]);

    // Create future site
    Site::factory()->create([
        'valid_from' => now()->addDays(10),
        'valid_until' => now()->addMonths(6),
    ]);

    $currentlyValid = Site::currentlyValid()->get();

    expect($currentlyValid)->toHaveCount(2);
});

test('scope for establishment filters correctly', function (): void {
    $establishment1 = Establishment::factory()->create();
    $establishment2 = Establishment::factory()->create();

    Site::factory()->forEstablishment($establishment1)->create();
    Site::factory()->forEstablishment($establishment2)->create();

    $sites = Site::forEstablishment($establishment1->id)->get();

    expect($sites)->toHaveCount(1);
    expect($sites->first()->establishment_id)->toBe($establishment1->id);
});

test('site has tenant relationship', function () {
    $site = Site::factory()->create(['tenant_id' => $this->tenant->id]);

    expect($site->tenant)->toBeInstanceOf(TenantKey::class);
    expect($site->tenant->id)->toBe($this->tenant->id);
});

test('site has customer relationship', function () {
    $customer = Customer::factory()->create();
    $site = Site::factory()->forCustomer($customer)->create();

    expect($site->customer)->toBeInstanceOf(Customer::class);
    expect($site->customer->id)->toBe($customer->id);
});

test('site has legal entity and establishment relationships', function (): void {
    $establishment = Establishment::factory()->create();
    $site = Site::factory()->forEstablishment($establishment)->create();

    expect($site->legalEntity)->toBeInstanceOf(LegalEntity::class);
    expect($site->establishment)->toBeInstanceOf(Establishment::class);
    expect($site->establishment->id)->toBe($establishment->id);
});

test('site has assignments relationship', function () {
    $site = Site::factory()->create();

    expect($site->assignments())->toBeInstanceOf(Illuminate\Database\Eloquent\Relations\HasMany::class);
    expect($site->assignments)->toBeInstanceOf(Illuminate\Database\Eloquent\Collection::class);
});

test('site has cost centers relationship', function () {
    $site = Site::factory()->create();

    expect($site->costCenters())->toBeInstanceOf(Illuminate\Database\Eloquent\Relations\HasMany::class);
    expect($site->costCenters)->toBeInstanceOf(Illuminate\Database\Eloquent\Collection::class);
});

test('full address accessor formats address correctly', function () {
    $site = Site::factory()->create([
        'address' => [
            'street' => 'Teststr. 123',
            'city' => 'Berlin',
            'postal_code' => '10115',
            'country' => 'DE',
        ],
    ]);

    $expected = 'Teststr. 123, 10115, Berlin';
    expect($site->full_address)->toBe($expected);
});

test('full address accessor handles missing fields', function () {
    $site = Site::factory()->create([
        'address' => [
            'street' => 'Teststr. 123',
            'city' => 'Berlin',
            // postal_code missing
            'country' => 'DE',
        ],
    ]);

    $expected = 'Teststr. 123, Berlin';
    expect($site->full_address)->toBe($expected);
});

test('full address accessor ignores non-string address parts', function () {
    $site = Site::factory()->create([
        'address' => [
            'street' => 'Teststr. 123',
            'postal_code' => 10115,
            'city' => 'Berlin',
        ],
    ]);

    expect($site->full_address)->toBe('Teststr. 123, Berlin');
});

test('is expired accessor returns true for expired sites', function () {
    $site = Site::factory()->expired()->create();

    expect($site->is_expired)->toBeTrue();
});

test('is expired accessor returns false for valid sites', function () {
    $site = Site::factory()->create([
        'valid_from' => now()->subDays(10),
        'valid_until' => now()->addDays(10),
    ]);

    expect($site->is_expired)->toBeFalse();
});

test('is expired accessor returns false for sites without validity', function () {
    $site = Site::factory()->create([
        'valid_from' => null,
        'valid_until' => null,
    ]);

    expect($site->is_expired)->toBeFalse();
});

test('site address is cast to array', function () {
    $site = Site::factory()->create([
        'address' => [
            'street' => 'Teststr. 123',
            'city' => 'Berlin',
            'postal_code' => '10115',
            'country' => 'DE',
            'lat' => 52.5200,
            'lng' => 13.4050,
        ],
    ]);

    expect($site->address)->toBeArray();
    expect($site->address['street'])->toBe('Teststr. 123');
    expect($site->address['lat'])->toBe(52.5200);
});

test('site can be soft deleted', function () {
    $site = Site::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);
    $siteId = $site->id;

    $site->delete();

    $this->assertSoftDeleted('sites', ['id' => $siteId]);
    expect($site->fresh()?->deleted_at)->not->toBeNull();
});

test('site can be inactive', function () {
    $site = Site::factory()->inactive()->create();

    expect($site->is_active)->toBeFalse();
});

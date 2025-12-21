<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Tests\Unit\Models;

use App\Models\Customer;
use App\Models\OrganizationalUnit;
use App\Models\Site;
use App\Models\TenantKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for Site model.
 *
 * @covers \App\Models\Site
 */
class SiteTest extends TestCase
{
    use RefreshDatabase;

    protected TenantKey $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        TenantKey::setKekPath(getTestKekPath());

        // Ensure KEK exists
        if (! file_exists(TenantKey::getKekPath())) {
            TenantKey::generateKek();
        }

        // Create tenant for testing
        if (! TenantKey::first()) {
            $keys = TenantKey::generateEnvelopeKeys();
            $this->tenant = TenantKey::create($keys);
        } else {
            $this->tenant = TenantKey::first();
        }
    }

    protected function tearDown(): void
    {
        cleanupTestKekFile();
        TenantKey::setKekPath(null);
        parent::tearDown();
    }

    public function test_site_can_be_created_with_factory(): void
    {
        $site = Site::factory()->create();

        $this->assertNotNull($site->id);
        $this->assertNotNull($site->site_number);
        $this->assertNotNull($site->name);
        $this->assertIsArray($site->address);
        $this->assertTrue($site->is_active);
    }

    public function test_site_number_is_auto_generated_with_correct_format(): void
    {
        $site = Site::factory()->create();

        // Format: OBJ-YYYY-NNNN
        $pattern = '/^OBJ-\d{4}-\d{4}$/';
        $this->assertMatchesRegularExpression($pattern, $site->site_number);
    }

    public function test_generate_site_number_starts_at_0001_for_new_year(): void
    {
        $year = now()->year;
        $number = Site::generateSiteNumber($this->tenant->id);

        $expected = sprintf('OBJ-%d-0001', $year);
        $this->assertSame($expected, $number);
    }

    public function test_generate_site_number_increments_correctly(): void
    {
        // Create first site
        Site::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_number' => Site::generateSiteNumber($this->tenant->id),
        ]);

        // Generate next number
        $nextNumber = Site::generateSiteNumber($this->tenant->id);

        $year = now()->year;
        $expected = sprintf('OBJ-%d-0002', $year);
        $this->assertSame($expected, $nextNumber);
    }

    public function test_generate_site_number_handles_soft_deleted_records(): void
    {
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
        $this->assertSame($expected, $nextNumber);
    }

    public function test_scope_active_returns_only_active_sites(): void
    {
        Site::factory()->create(['is_active' => true]);
        Site::factory()->create(['is_active' => true]);
        Site::factory()->create(['is_active' => false]);

        $activeSites = Site::active()->get();

        $this->assertCount(2, $activeSites);
        $this->assertTrue($activeSites->every(fn ($site) => $site->is_active));
    }

    public function test_scope_permanent_returns_only_permanent_sites(): void
    {
        Site::factory()->permanent()->create();
        Site::factory()->permanent()->create();
        Site::factory()->temporary()->create();

        $permanentSites = Site::permanent()->get();

        $this->assertCount(2, $permanentSites);
        $this->assertTrue($permanentSites->every(fn ($site) => $site->type === 'permanent'));
    }

    public function test_scope_temporary_returns_only_temporary_sites(): void
    {
        Site::factory()->temporary()->create();
        Site::factory()->temporary()->create();
        Site::factory()->permanent()->create();

        $temporarySites = Site::temporary()->get();

        $this->assertCount(2, $temporarySites);
        $this->assertTrue($temporarySites->every(fn ($site) => $site->type === 'temporary'));
    }

    public function test_scope_currently_valid_filters_by_validity_period(): void
    {
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

        $this->assertCount(2, $currentlyValid);
    }

    public function test_scope_for_organizational_unit_filters_correctly(): void
    {
        $orgUnit1 = OrganizationalUnit::factory()->create();
        $orgUnit2 = OrganizationalUnit::factory()->create();

        Site::factory()->forOrganizationalUnit($orgUnit1)->create();
        Site::factory()->forOrganizationalUnit($orgUnit2)->create();

        $sites = Site::forOrganizationalUnit($orgUnit1->id)->get();

        $this->assertCount(1, $sites);
        $this->assertEquals($orgUnit1->id, $sites->first()->organizational_unit_id);
    }

    public function test_site_has_tenant_relationship(): void
    {
        $site = Site::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->assertInstanceOf(TenantKey::class, $site->tenant);
        $this->assertEquals($this->tenant->id, $site->tenant->id);
    }

    public function test_site_has_customer_relationship(): void
    {
        $customer = Customer::factory()->create();
        $site = Site::factory()->forCustomer($customer)->create();

        $this->assertInstanceOf(Customer::class, $site->customer);
        $this->assertEquals($customer->id, $site->customer->id);
    }

    public function test_site_has_organizational_unit_relationship(): void
    {
        $orgUnit = OrganizationalUnit::factory()->create();
        $site = Site::factory()->forOrganizationalUnit($orgUnit)->create();

        $this->assertInstanceOf(OrganizationalUnit::class, $site->organizationalUnit);
        $this->assertEquals($orgUnit->id, $site->organizationalUnit->id);
    }

    public function test_site_has_assignments_relationship(): void
    {
        $site = Site::factory()->create();

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class, $site->assignments());
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $site->assignments);
    }

    public function test_site_has_cost_centers_relationship(): void
    {
        $site = Site::factory()->create();

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class, $site->costCenters());
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $site->costCenters);
    }

    public function test_full_address_accessor_formats_address_correctly(): void
    {
        $site = Site::factory()->create([
            'address' => [
                'street' => 'Teststr. 123',
                'city' => 'Berlin',
                'postal_code' => '10115',
                'country' => 'DE',
            ],
        ]);

        $expected = 'Teststr. 123, 10115, Berlin';
        $this->assertEquals($expected, $site->full_address);
    }

    public function test_full_address_accessor_handles_missing_fields(): void
    {
        $site = Site::factory()->create([
            'address' => [
                'street' => 'Teststr. 123',
                'city' => 'Berlin',
                // postal_code missing
                'country' => 'DE',
            ],
        ]);

        $expected = 'Teststr. 123, Berlin';
        $this->assertEquals($expected, $site->full_address);
    }

    public function test_is_expired_accessor_returns_true_for_expired_sites(): void
    {
        $site = Site::factory()->expired()->create();

        $this->assertTrue($site->is_expired);
    }

    public function test_is_expired_accessor_returns_false_for_valid_sites(): void
    {
        $site = Site::factory()->create([
            'valid_from' => now()->subDays(10),
            'valid_until' => now()->addDays(10),
        ]);

        $this->assertFalse($site->is_expired);
    }

    public function test_is_expired_accessor_returns_false_for_sites_without_validity(): void
    {
        $site = Site::factory()->create([
            'valid_from' => null,
            'valid_until' => null,
        ]);

        $this->assertFalse($site->is_expired);
    }

    public function test_site_address_is_cast_to_array(): void
    {
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

        $this->assertIsArray($site->address);
        $this->assertEquals('Teststr. 123', $site->address['street']);
        $this->assertEquals(52.5200, $site->address['lat']);
    }

    public function test_site_can_be_soft_deleted(): void
    {
        $site = Site::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
        $siteId = $site->id;

        $site->delete();

        $this->assertSoftDeleted('sites', ['id' => $siteId]);
        $this->assertNotNull($site->fresh()?->deleted_at);
    }

    public function test_site_can_be_inactive(): void
    {
        $site = Site::factory()->inactive()->create();

        $this->assertFalse($site->is_active);
    }
}

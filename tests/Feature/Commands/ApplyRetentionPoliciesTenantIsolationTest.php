<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use App\Models\Activity;
use App\Models\Customer;
use App\Models\TenantKey;
use Illuminate\Support\Facades\Artisan;

use function Pest\Laravel\travel;

/**
 * Critical Security Test: ApplyRetentionPolicies MUST maintain tenant isolation.
 *
 * Security Requirements:
 * - MUST NOT delete logs from other tenants
 * - MUST process each tenant independently
 * - MUST respect tenant_id filtering in ALL queries
 *
 * @see Issue #441: Retention refactoring
 */
describe('ApplyRetentionPolicies → Tenant Isolation', function () {
    beforeEach(function () {
        // Tenant 1
        $this->tenant1 = TenantKey::factory()->create();
        $this->customer1 = Customer::factory()->for($this->tenant1, 'tenant')->create();

        // Tenant 2
        $this->tenant2 = TenantKey::factory()->create();
        $this->customer2 = Customer::factory()->for($this->tenant2, 'tenant')->create();
    });

    test('processes all tenants independently', function () {
        // Create old logs for both tenants (4 years ago = should be deleted for 3-year retention)
        travel(-4)->years();

        activity('shift_management')->performedOn($this->customer1)->log('Tenant 1 - Old shift');
        activity('shift_management')->performedOn($this->customer2)->log('Tenant 2 - Old shift');

        travel()->back();

        // Create recent logs for both tenants
        activity('shift_management')->performedOn($this->customer1)->log('Tenant 1 - Recent');
        activity('shift_management')->performedOn($this->customer2)->log('Tenant 2 - Recent');

        expect(Activity::where('tenant_id', $this->tenant1->id)->count())->toBe(2);
        expect(Activity::where('tenant_id', $this->tenant2->id)->count())->toBe(2);

        // Apply retention (should delete 1 log per tenant)
        Artisan::call('activity:apply-retention');

        // Each tenant should have 1 log deleted + 1 recent remaining
        expect(Activity::where('tenant_id', $this->tenant1->id)->count())->toBe(1);
        expect(Activity::where('tenant_id', $this->tenant2->id)->count())->toBe(1);

        // Verify correct logs deleted
        expect(Activity::where('tenant_id', $this->tenant1->id)->first()->description)
            ->toBe('Tenant 1 - Recent');
        expect(Activity::where('tenant_id', $this->tenant2->id)->first()->description)
            ->toBe('Tenant 2 - Recent');
    });

    test('does NOT cross-contaminate tenants during deletion', function () {
        // Tenant 1: Old log that should be deleted
        travel(-4)->years();
        activity('shift_management')->performedOn($this->customer1)->log('Tenant 1 - Old');
        travel()->back();

        // Tenant 2: Recent log that should NOT be deleted
        activity('shift_management')->performedOn($this->customer2)->log('Tenant 2 - Recent');

        // Apply retention
        Artisan::call('activity:apply-retention');

        // Tenant 1: Old log deleted
        expect(Activity::where('tenant_id', $this->tenant1->id)->count())->toBe(0);

        // Tenant 2: Recent log untouched (CRITICAL: must not be affected by Tenant 1 deletion)
        expect(Activity::where('tenant_id', $this->tenant2->id)->count())->toBe(1);
        expect(Activity::where('tenant_id', $this->tenant2->id)->first()->description)
            ->toBe('Tenant 2 - Recent');
    });

    test('orphaned genesis markers respect tenant isolation', function () {
        // Tenant 1: Create chain with old first log
        travel(-4)->years();
        activity('shift_management')->performedOn($this->customer1)->log('Tenant 1 - Old first');
        travel()->back();
        activity('shift_management')->performedOn($this->customer1)->log('Tenant 1 - Recent second');

        // Tenant 2: Create separate chain (should not be affected)
        activity('shift_management')->performedOn($this->customer2)->log('Tenant 2 - Genesis');
        activity('shift_management')->performedOn($this->customer2)->log('Tenant 2 - Second');

        // Apply retention (should delete Tenant 1 old log, create orphaned genesis)
        Artisan::call('activity:apply-retention');

        // Tenant 1: Old log deleted, recent log marked as orphaned genesis
        $tenant1Log = Activity::where('tenant_id', $this->tenant1->id)->first();
        expect($tenant1Log->is_orphaned_genesis)->toBeTrue();

        // Tenant 2: Chain intact, NO orphaned genesis (CRITICAL)
        $tenant2Logs = Activity::where('tenant_id', $this->tenant2->id)->orderBy('created_at')->get();
        expect($tenant2Logs)->toHaveCount(2);
        expect($tenant2Logs[0]->is_orphaned_genesis)->toBeFalse();
        expect($tenant2Logs[1]->is_orphaned_genesis)->toBeFalse();
        expect($tenant2Logs[1]->previous_hash)->toBe($tenant2Logs[0]->event_hash);
    });

    test('--tenant option processes only specified tenant', function () {
        // Create old logs for both tenants
        travel(-4)->years();
        activity('shift_management')->performedOn($this->customer1)->log('Tenant 1 - Old');
        activity('shift_management')->performedOn($this->customer2)->log('Tenant 2 - Old');
        travel()->back();

        // Process only Tenant 1
        Artisan::call('activity:apply-retention', ['--tenant' => $this->tenant1->id]);

        // Tenant 1: Log deleted
        expect(Activity::where('tenant_id', $this->tenant1->id)->count())->toBe(0);

        // Tenant 2: Log UNTOUCHED (not processed)
        expect(Activity::where('tenant_id', $this->tenant2->id)->count())->toBe(1);
        expect(Activity::where('tenant_id', $this->tenant2->id)->first()->description)
            ->toBe('Tenant 2 - Old');
    });

    test('large-scale multi-tenant deletion maintains isolation', function () {
        // Create 100 old logs per tenant
        travel(-4)->years();

        for ($i = 1; $i <= 100; $i++) {
            activity('shift_management')->performedOn($this->customer1)->log("Tenant 1 - Old {$i}");
            activity('shift_management')->performedOn($this->customer2)->log("Tenant 2 - Old {$i}");
        }

        travel()->back();

        // Create 50 recent logs per tenant
        for ($i = 1; $i <= 50; $i++) {
            activity('shift_management')->performedOn($this->customer1)->log("Tenant 1 - Recent {$i}");
            activity('shift_management')->performedOn($this->customer2)->log("Tenant 2 - Recent {$i}");
        }

        expect(Activity::where('tenant_id', $this->tenant1->id)->count())->toBe(150);
        expect(Activity::where('tenant_id', $this->tenant2->id)->count())->toBe(150);

        // Apply retention
        Artisan::call('activity:apply-retention');

        // Each tenant should have 50 logs remaining
        expect(Activity::where('tenant_id', $this->tenant1->id)->count())->toBe(50);
        expect(Activity::where('tenant_id', $this->tenant2->id)->count())->toBe(50);

        // Verify only recent logs remain
        $tenant1Logs = Activity::where('tenant_id', $this->tenant1->id)->get();
        foreach ($tenant1Logs as $log) {
            expect($log->description)->toContain('Recent');
        }

        $tenant2Logs = Activity::where('tenant_id', $this->tenant2->id)->get();
        foreach ($tenant2Logs as $log) {
            expect($log->description)->toContain('Recent');
        }
    });
})->group('retention', 'security', 'tenant-isolation', 'critical', 'issue-441');

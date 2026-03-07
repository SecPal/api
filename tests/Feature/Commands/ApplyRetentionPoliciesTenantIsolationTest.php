<?php

/**
 * SPDX-FileCopyrightText: 2025 SecPal Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

use App\Models\Activity;
use App\Models\Customer;
use App\Models\TenantKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\Wormhole;
use Illuminate\Support\Facades\Artisan;

use function Pest\Laravel\travel;

uses(RefreshDatabase::class);

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
        // Tenant 1 - customer will be created in tests as needed
        $this->tenant1 = TenantKey::factory()->create();

        // Tenant 2 - normal customer
        $this->tenant2 = TenantKey::factory()->create();
        $this->customer2 = Customer::factory()->for($this->tenant2, 'tenant')->create();
    });

    test('processes all tenants independently', function () {
        // Create Tenant 1 customer
        $this->customer1 = Customer::factory()->for($this->tenant1, 'tenant')->create();

        // Create old logs for both tenants (4 years ago = should be deleted for 3-year retention)
        travel(-4)->years();

        activity('shift_management')->performedOn($this->customer1)->log('Tenant 1 - Old shift');
        activity('shift_management')->performedOn($this->customer2)->log('Tenant 2 - Old shift');

        Wormhole::back();

        // Create recent logs for both tenants
        activity('shift_management')->performedOn($this->customer1)->log('Tenant 1 - Recent');
        activity('shift_management')->performedOn($this->customer2)->log('Tenant 2 - Recent');

        // Each tenant has: "created" (auto) + "Old shift" + "Recent" = 3 logs
        expect(Activity::where('tenant_id', $this->tenant1->id)->count())->toBe(3);
        expect(Activity::where('tenant_id', $this->tenant2->id)->count())->toBe(3);

        // Apply retention (should delete 1 log per tenant)
        Artisan::call('activity:apply-retention');

        // Each tenant: Old deleted, "created" + Recent remain = 2 logs
        expect(Activity::where('tenant_id', $this->tenant1->id)->count())->toBe(2);
        expect(Activity::where('tenant_id', $this->tenant2->id)->count())->toBe(2);

        // Verify correct logs deleted (exclude auto-created "created" logs)
        expect(Activity::where('tenant_id', $this->tenant1->id)
            ->where('description', '!=', 'created')
            ->orderBy('created_at')
            ->first()->description)
            ->toBe('Tenant 1 - Recent');
        expect(Activity::where('tenant_id', $this->tenant2->id)
            ->where('description', '!=', 'created')
            ->orderBy('created_at')
            ->first()->description)
            ->toBe('Tenant 2 - Recent');
    });

    test('does NOT cross-contaminate tenants during deletion', function () {
        // Create Tenant 1 customer
        $this->customer1 = Customer::factory()->for($this->tenant1, 'tenant')->create();

        // Tenant 1: Old log that should be deleted
        travel(-4)->years();
        activity('shift_management')->performedOn($this->customer1)->log('Tenant 1 - Old');
        Wormhole::back();

        // Tenant 2: Recent log that should NOT be deleted
        activity('shift_management')->performedOn($this->customer2)->log('Tenant 2 - Recent');

        // Apply retention
        Artisan::call('activity:apply-retention');

        // Tenant 1: Old log deleted, "created" remains
        expect(Activity::where('tenant_id', $this->tenant1->id)->count())->toBe(1);

        // Tenant 2: "created" + Recent untouched (CRITICAL: must not be affected by Tenant 1 deletion)
        expect(Activity::where('tenant_id', $this->tenant2->id)->count())->toBe(2);
        expect(Activity::where('tenant_id', $this->tenant2->id)->where('description', 'Tenant 2 - Recent')->first()->description)
            ->toBe('Tenant 2 - Recent');
    });

    test('orphaned genesis markers respect tenant isolation', function () {
        // FIXED: Create Tenant 1 customer in the past so all logs form a proper hash chain
        // This ensures "Recent second" will point to "Old first" in the chain
        travel(-5)->years();
        $oldCustomer1 = Customer::factory()->for($this->tenant1, 'tenant')->create();

        // Tenant 1: Create chain with old first log (still 5 years ago)
        activity('shift_management')->performedOn($oldCustomer1)->log('Tenant 1 - Old first');

        Wormhole::back();

        // Now create recent log (will chain to old first)
        activity('shift_management')->performedOn($oldCustomer1)->log('Tenant 1 - Recent second');
        // Tenant 2: Create separate chain (should not be affected)
        activity('shift_management')->performedOn($this->customer2)->log('Tenant 2 - Genesis');
        activity('shift_management')->performedOn($this->customer2)->log('Tenant 2 - Second');

        // BUGFIX: Ensure all hash chain jobs completed and DB state is consistent
        // dispatchSync() processes jobs immediately, but we need to ensure
        // all database writes are visible across transactions in parallel tests.
        // Force a fresh query to reload all activities with their computed hashes.
        Activity::where('tenant_id', $this->tenant2->id)->get()->each->refresh();

        // Apply retention (should delete Tenant 1 old log + auto-created "created", create orphaned genesis)
        Artisan::call('activity:apply-retention');

        // Tenant 1: Old logs deleted, "created" (customer_changes, 3y retention) + recent second remain
        // Recent second is marked as orphaned genesis because its predecessor was deleted
        $tenant1Logs = Activity::where('tenant_id', $this->tenant1->id)
            ->where('log_name', 'shift_management')
            ->orderBy('created_at')
            ->get();

        expect($tenant1Logs)->toHaveCount(1, 'Only Recent second shift_management log should remain');
        expect($tenant1Logs[0]->description)->toBe('Tenant 1 - Recent second');
        expect($tenant1Logs[0]->is_orphaned_genesis)->toBeTrue('Recent second should be orphaned genesis');

        // Tenant 2: "created" + 2 manual logs = 3 logs, chain intact, NO orphaned genesis (CRITICAL)
        // Use deterministic ordering for parallel CI environments.
        $tenant2Logs = Activity::where('tenant_id', $this->tenant2->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
        expect($tenant2Logs)->toHaveCount(3);
        expect($tenant2Logs[0]->is_orphaned_genesis)->toBeFalse();
        expect($tenant2Logs[1]->is_orphaned_genesis)->toBeFalse();
        expect($tenant2Logs[2]->is_orphaned_genesis)->toBeFalse();

        // Verify chain integrity only for the tenant's shift_management chain.
        $tenant2ShiftLogs = Activity::where('tenant_id', $this->tenant2->id)
            ->where('log_name', 'shift_management')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        expect($tenant2ShiftLogs)->toHaveCount(2);
        expect($tenant2ShiftLogs[1]->previous_hash)->toBe($tenant2ShiftLogs[0]->event_hash);
    });

    test('--tenant option processes only specified tenant', function () {
        // Create Tenant 1 customer
        $this->customer1 = Customer::factory()->for($this->tenant1, 'tenant')->create();

        // Create old logs for both tenants
        travel(-4)->years();
        activity('shift_management')->performedOn($this->customer1)->log('Tenant 1 - Old');
        activity('shift_management')->performedOn($this->customer2)->log('Tenant 2 - Old');
        Wormhole::back();

        // Process only Tenant 1
        Artisan::call('activity:apply-retention', ['--tenant' => $this->tenant1->id]);

        // Tenant 1: Old deleted, "created" remains
        expect(Activity::where('tenant_id', $this->tenant1->id)->count())->toBe(1);

        // Tenant 2: "created" + Old UNTOUCHED (not processed)
        expect(Activity::where('tenant_id', $this->tenant2->id)->count())->toBe(2);
        expect(Activity::where('tenant_id', $this->tenant2->id)->where('description', 'Tenant 2 - Old')->first()->description)
            ->toBe('Tenant 2 - Old');
    });

    test('large-scale multi-tenant deletion maintains isolation', function () {
        // Create Tenant 1 customer
        $this->customer1 = Customer::factory()->for($this->tenant1, 'tenant')->create();

        // Create 100 old logs per tenant
        travel(-4)->years();

        for ($i = 1; $i <= 100; $i++) {
            activity('shift_management')->performedOn($this->customer1)->log("Tenant 1 - Old {$i}");
            activity('shift_management')->performedOn($this->customer2)->log("Tenant 2 - Old {$i}");
        }

        Wormhole::back();

        // Create 50 recent logs per tenant
        for ($i = 1; $i <= 50; $i++) {
            activity('shift_management')->performedOn($this->customer1)->log("Tenant 1 - Recent {$i}");
            activity('shift_management')->performedOn($this->customer2)->log("Tenant 2 - Recent {$i}");
        }

        // Each tenant: "created" (auto) + 100 old + 50 recent = 151 logs
        expect(Activity::where('tenant_id', $this->tenant1->id)->count())->toBe(151);
        expect(Activity::where('tenant_id', $this->tenant2->id)->count())->toBe(151);

        // Apply retention
        Artisan::call('activity:apply-retention');

        // Each tenant: 100 old deleted, "created" + 50 recent remain = 51 logs
        expect(Activity::where('tenant_id', $this->tenant1->id)->count())->toBe(51);
        expect(Activity::where('tenant_id', $this->tenant2->id)->count())->toBe(51);

        // Verify only recent logs remain (excluding auto-created "created")
        $tenant1Logs = Activity::where('tenant_id', $this->tenant1->id)
            ->where('description', '!=', 'created')
            ->get();
        foreach ($tenant1Logs as $log) {
            expect($log->description)->toContain('Recent');
        }

        $tenant2Logs = Activity::where('tenant_id', $this->tenant2->id)
            ->where('description', '!=', 'created')
            ->get();
        foreach ($tenant2Logs as $log) {
            expect($log->description)->toContain('Recent');
        }
    });
})->group('retention', 'security', 'tenant-isolation', 'critical', 'issue-441');

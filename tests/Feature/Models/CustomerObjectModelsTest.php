<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Tests for Customer, Object, and related Models created in Issue #232.
 *
 * These tests verify:
 * - Factory functionality and states
 * - Model relationships
 * - Soft deletes
 * - Closure table auto-creation via model events
 *
 * Note: Tests are aligned with actual database schema from migrations.
 * Customer model has automatic closure table entry creation via booted().
 */

use App\Models\Customer;
use App\Models\CustomerClosure;
use App\Models\ObjectArea;
use App\Models\SecPalObject;
use App\Models\TenantKey;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Use proper TenantKey initialization as per project pattern
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();

    // Create a tenant for testing
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

describe('Customer Model', function (): void {
    describe('factory', function (): void {
        it('creates a valid customer using factory', function (): void {
            $customer = Customer::factory()->forTenant($this->tenant->id)->create();

            expect($customer)->toBeInstanceOf(Customer::class)
                ->and($customer->id)->toBeString()
                ->and($customer->name)->toBeString()
                ->and($customer->customer_number)->toMatch('/^CUST-\d{6}$/')
                ->and($customer->tenant_id)->toBeInt();
        });

        it('creates corporate customer via factory state', function (): void {
            $customer = Customer::factory()->forTenant($this->tenant->id)->corporate()->create();

            expect($customer->type)->toBe('corporate');
        });

        it('creates regional customer via factory state', function (): void {
            $customer = Customer::factory()->forTenant($this->tenant->id)->regional()->create();

            expect($customer->type)->toBe('regional');
        });

        it('creates local customer via factory state', function (): void {
            $customer = Customer::factory()->forTenant($this->tenant->id)->local()->create();

            expect($customer->type)->toBe('local');
        });
    });

    describe('tenant isolation', function (): void {
        it('requires tenant_id', function (): void {
            $customer = Customer::factory()->make(['tenant_id' => null]);

            expect(fn () => $customer->save())->toThrow(\Illuminate\Database\QueryException::class);
        });
    });

    describe('relationships', function (): void {
        it('has many objects', function (): void {
            $customer = Customer::factory()->forTenant($this->tenant->id)->create();
            SecPalObject::factory()->forCustomer($customer)->forTenant($this->tenant->id)->count(3)->create();

            expect($customer->objects)->toHaveCount(3)
                ->and($customer->objects->first())->toBeInstanceOf(SecPalObject::class);
        });
    });

    describe('hierarchy (closure table)', function (): void {
        it('automatically creates self-referencing closure entry on customer creation', function (): void {
            $customer = Customer::factory()->forTenant($this->tenant->id)->create();

            // The Customer model boot method automatically creates the closure entry
            $closure = CustomerClosure::where('ancestor_id', $customer->id)
                ->where('descendant_id', $customer->id)
                ->first();

            expect($closure)->not->toBeNull()
                ->and($closure->depth)->toBe(0);
        });

        it('closure relationships work correctly', function (): void {
            $parent = Customer::factory()->forTenant($this->tenant->id)->create();
            $child = Customer::factory()->forTenant($this->tenant->id)->create();

            // Add parent-child closure relationship manually (normally done by service)
            CustomerClosure::create([
                'ancestor_id' => $parent->id,
                'descendant_id' => $child->id,
                'depth' => 1,
            ]);

            // Reload to get fresh relationships
            $child->refresh();

            // Child should have 1 ancestor: parent (depth 1). Self-reference (depth 0) is excluded by ancestors() method
            expect($child->ancestors)->toHaveCount(1)
                ->and($child->ancestors->pluck('id'))->toContain($parent->id);
        });
    });

    describe('soft deletes', function (): void {
        it('soft deletes customer', function (): void {
            $customer = Customer::factory()->forTenant($this->tenant->id)->create();
            $customerId = $customer->id;

            $customer->delete();

            expect(Customer::find($customerId))->toBeNull()
                ->and(Customer::withTrashed()->find($customerId))->not->toBeNull();
        });

        it('can restore soft deleted customer', function (): void {
            $customer = Customer::factory()->forTenant($this->tenant->id)->create();
            $customerId = $customer->id;

            $customer->delete();
            $customer->restore();

            expect(Customer::find($customerId))->not->toBeNull()
                ->and($customer->deleted_at)->toBeNull();
        });
    });
});

describe('SecPalObject Model', function (): void {
    describe('factory', function (): void {
        it('creates a valid object using factory', function (): void {
            $customer = Customer::factory()->forTenant($this->tenant->id)->create();
            $object = SecPalObject::factory()->forCustomer($customer)->forTenant($this->tenant->id)->create();

            expect($object)->toBeInstanceOf(SecPalObject::class)
                ->and($object->id)->toBeString()
                ->and($object->name)->toBeString()
                ->and($object->object_number)->toMatch('/^OBJ-\d{6}$/');
        });

        it('creates object with coordinates via factory state', function (): void {
            $customer = Customer::factory()->forTenant($this->tenant->id)->create();
            $object = SecPalObject::factory()
                ->forCustomer($customer)
                ->forTenant($this->tenant->id)
                ->withCoordinates(52.52, 13.405)
                ->create();

            expect($object->gps_coordinates)->not->toBeNull()
                ->and($object->gps_coordinates['lat'])->toBe(52.52)
                ->and($object->gps_coordinates['lon'])->toBe(13.405);
        });

        it('creates object without coordinates via factory state', function (): void {
            $customer = Customer::factory()->forTenant($this->tenant->id)->create();
            $object = SecPalObject::factory()
                ->forCustomer($customer)
                ->forTenant($this->tenant->id)
                ->withoutCoordinates()
                ->create();

            expect($object->gps_coordinates)->toBeNull();
        });
    });

    describe('relationships', function (): void {
        it('belongs to customer', function (): void {
            $customer = Customer::factory()->forTenant($this->tenant->id)->create();
            $object = SecPalObject::factory()->forCustomer($customer)->forTenant($this->tenant->id)->create();

            expect($object->customer)->toBeInstanceOf(Customer::class)
                ->and($object->customer->id)->toBe($customer->id);
        });

        it('has many areas', function (): void {
            $customer = Customer::factory()->forTenant($this->tenant->id)->create();
            $object = SecPalObject::factory()->forCustomer($customer)->forTenant($this->tenant->id)->create();
            ObjectArea::factory()->forObject($object)->forTenant($this->tenant->id)->count(3)->create();

            expect($object->areas)->toHaveCount(3)
                ->and($object->areas->first())->toBeInstanceOf(ObjectArea::class);
        });
    });

    describe('soft deletes', function (): void {
        it('soft deletes object', function (): void {
            $customer = Customer::factory()->forTenant($this->tenant->id)->create();
            $object = SecPalObject::factory()->forCustomer($customer)->forTenant($this->tenant->id)->create();
            $objectId = $object->id;

            $object->delete();

            expect(SecPalObject::find($objectId))->toBeNull()
                ->and(SecPalObject::withTrashed()->find($objectId))->not->toBeNull();
        });
    });
});

describe('ObjectArea Model', function (): void {
    describe('factory', function (): void {
        it('creates a valid object area using factory', function (): void {
            $customer = Customer::factory()->forTenant($this->tenant->id)->create();
            $object = SecPalObject::factory()->forCustomer($customer)->forTenant($this->tenant->id)->create();
            $area = ObjectArea::factory()->forObject($object)->forTenant($this->tenant->id)->create();

            expect($area)->toBeInstanceOf(ObjectArea::class)
                ->and($area->id)->toBeString()
                ->and($area->name)->toBeString();
        });

        it('creates area requiring separate guard book', function (): void {
            $customer = Customer::factory()->forTenant($this->tenant->id)->create();
            $object = SecPalObject::factory()->forCustomer($customer)->forTenant($this->tenant->id)->create();
            $area = ObjectArea::factory()
                ->forObject($object)
                ->forTenant($this->tenant->id)
                ->withSeparateGuardBook()
                ->create();

            expect($area->requires_separate_guard_book)->toBeTrue();
        });

        it('creates area without separate guard book', function (): void {
            $customer = Customer::factory()->forTenant($this->tenant->id)->create();
            $object = SecPalObject::factory()->forCustomer($customer)->forTenant($this->tenant->id)->create();
            $area = ObjectArea::factory()
                ->forObject($object)
                ->forTenant($this->tenant->id)
                ->withoutSeparateGuardBook()
                ->create();

            expect($area->requires_separate_guard_book)->toBeFalse();
        });
    });

    describe('relationships', function (): void {
        it('belongs to object', function (): void {
            $customer = Customer::factory()->forTenant($this->tenant->id)->create();
            $object = SecPalObject::factory()->forCustomer($customer)->forTenant($this->tenant->id)->create();
            $area = ObjectArea::factory()->forObject($object)->forTenant($this->tenant->id)->create();

            expect($area->object)->toBeInstanceOf(SecPalObject::class)
                ->and($area->object->id)->toBe($object->id);
        });

        it('customer accessor returns customer via object relationship', function (): void {
            $customer = Customer::factory()->forTenant($this->tenant->id)->create();
            $object = SecPalObject::factory()->forCustomer($customer)->forTenant($this->tenant->id)->create();
            $area = ObjectArea::factory()->forObject($object)->forTenant($this->tenant->id)->create();

            expect($area->customer)->toBeInstanceOf(Customer::class)
                ->and($area->customer->id)->toBe($customer->id);
        });
    });

    describe('soft deletes', function (): void {
        it('soft deletes area', function (): void {
            $customer = Customer::factory()->forTenant($this->tenant->id)->create();
            $object = SecPalObject::factory()->forCustomer($customer)->forTenant($this->tenant->id)->create();
            $area = ObjectArea::factory()->forObject($object)->forTenant($this->tenant->id)->create();
            $areaId = $area->id;

            $area->delete();

            expect(ObjectArea::find($areaId))->toBeNull()
                ->and(ObjectArea::withTrashed()->find($areaId))->not->toBeNull();
        });
    });
});

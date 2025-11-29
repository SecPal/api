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
 * - Hierarchy methods (setParent, removeParent, ancestors, descendants)
 * - Access control models (CustomerUserAccess, CustomerUserObjectAccess)
 *
 * Note: Tests are aligned with actual database schema from migrations.
 * Customer model has automatic closure table entry creation via booted().
 */

use App\Models\Customer;
use App\Models\CustomerClosure;
use App\Models\CustomerUserAccess;
use App\Models\CustomerUserObjectAccess;
use App\Models\ObjectArea;
use App\Models\SecPalObject;
use App\Models\TenantKey;
use App\Models\User;
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

    describe('helper methods', function (): void {
        it('hasSeparateGuardBook returns correct value', function (): void {
            $customer = Customer::factory()->forTenant($this->tenant->id)->create();
            $object = SecPalObject::factory()->forCustomer($customer)->forTenant($this->tenant->id)->create();

            $areaWithGuardBook = ObjectArea::factory()
                ->forObject($object)
                ->forTenant($this->tenant->id)
                ->withSeparateGuardBook()
                ->create();

            $areaWithoutGuardBook = ObjectArea::factory()
                ->forObject($object)
                ->forTenant($this->tenant->id)
                ->withoutSeparateGuardBook()
                ->create();

            expect($areaWithGuardBook->hasSeparateGuardBook())->toBeTrue()
                ->and($areaWithoutGuardBook->hasSeparateGuardBook())->toBeFalse();
        });
    });
});

describe('Customer Hierarchy Methods', function (): void {
    describe('setParent', function (): void {
        it('sets parent customer correctly', function (): void {
            $parent = Customer::factory()->forTenant($this->tenant->id)->create();
            $child = Customer::factory()->forTenant($this->tenant->id)->create();

            $child->setParent($parent);

            // Verify closure entries exist
            $closure = CustomerClosure::where('ancestor_id', $parent->id)
                ->where('descendant_id', $child->id)
                ->where('depth', 1)
                ->first();

            expect($closure)->not->toBeNull()
                ->and($child->parent)->not->toBeNull()
                ->and($child->parent->id)->toBe($parent->id);
        });

        it('throws exception when setting self as parent', function (): void {
            $customer = Customer::factory()->forTenant($this->tenant->id)->create();

            expect(fn () => $customer->setParent($customer))
                ->toThrow(\InvalidArgumentException::class, 'Cannot set customer as its own parent.');
        });

        it('throws exception when setting parent from different tenant', function (): void {
            // Create second tenant
            $keys2 = TenantKey::generateEnvelopeKeys();
            $tenant2 = TenantKey::create($keys2);

            $customerTenant1 = Customer::factory()->forTenant($this->tenant->id)->create();
            $customerTenant2 = Customer::factory()->forTenant($tenant2->id)->create();

            expect(fn () => $customerTenant1->setParent($customerTenant2))
                ->toThrow(\InvalidArgumentException::class, 'Cannot set parent from a different tenant.');
        });

        it('throws exception when setting descendant as parent (cycle prevention)', function (): void {
            $grandparent = Customer::factory()->forTenant($this->tenant->id)->create();
            $parent = Customer::factory()->forTenant($this->tenant->id)->create();
            $child = Customer::factory()->forTenant($this->tenant->id)->create();

            // Build hierarchy: grandparent -> parent -> child
            $parent->setParent($grandparent);
            $child->setParent($parent);

            // Try to set child as grandparent's parent (would create cycle)
            expect(fn () => $grandparent->setParent($child))
                ->toThrow(\InvalidArgumentException::class, 'Cannot set a descendant as parent (would create a cycle).');
        });
    });

    describe('removeParent', function (): void {
        it('removes parent correctly', function (): void {
            $parent = Customer::factory()->forTenant($this->tenant->id)->create();
            $child = Customer::factory()->forTenant($this->tenant->id)->create();

            $child->setParent($parent);
            expect($child->parent)->not->toBeNull();

            $child->removeParent();

            // Reload to get fresh data
            $child->refresh();

            // After removeParent, the ancestor closure entries should be gone
            $parentClosure = CustomerClosure::where('ancestor_id', $parent->id)
                ->where('descendant_id', $child->id)
                ->first();

            expect($parentClosure)->toBeNull()
                ->and($child->parent)->toBeNull();
        });

        it('setParent with null removes parent', function (): void {
            $parent = Customer::factory()->forTenant($this->tenant->id)->create();
            $child = Customer::factory()->forTenant($this->tenant->id)->create();

            $child->setParent($parent);
            $child->setParent(null);
            $child->refresh();

            expect($child->parent)->toBeNull();
        });
    });

    describe('ancestors and descendants', function (): void {
        it('returns correct ancestors', function (): void {
            $grandparent = Customer::factory()->forTenant($this->tenant->id)->create();
            $parent = Customer::factory()->forTenant($this->tenant->id)->create();
            $child = Customer::factory()->forTenant($this->tenant->id)->create();

            $parent->setParent($grandparent);
            $child->setParent($parent);

            $ancestors = $child->ancestors;

            expect($ancestors)->toHaveCount(2)
                ->and($ancestors->pluck('id')->toArray())->toContain($parent->id, $grandparent->id);
        });

        it('returns correct descendants', function (): void {
            $grandparent = Customer::factory()->forTenant($this->tenant->id)->create();
            $parent = Customer::factory()->forTenant($this->tenant->id)->create();
            $child = Customer::factory()->forTenant($this->tenant->id)->create();

            $parent->setParent($grandparent);
            $child->setParent($parent);

            $descendants = $grandparent->descendants;

            expect($descendants)->toHaveCount(2)
                ->and($descendants->pluck('id')->toArray())->toContain($parent->id, $child->id);
        });

        it('children accessor returns direct children only', function (): void {
            $parent = Customer::factory()->forTenant($this->tenant->id)->create();
            $child1 = Customer::factory()->forTenant($this->tenant->id)->create();
            $child2 = Customer::factory()->forTenant($this->tenant->id)->create();
            $grandchild = Customer::factory()->forTenant($this->tenant->id)->create();

            $child1->setParent($parent);
            $child2->setParent($parent);
            $grandchild->setParent($child1);

            $children = $parent->children;

            expect($children)->toHaveCount(2)
                ->and($children->pluck('id')->toArray())->toContain($child1->id, $child2->id)
                ->and($children->pluck('id')->toArray())->not->toContain($grandchild->id);
        });
    });

    describe('userAccesses relationship', function (): void {
        it('returns customer user access records', function (): void {
            $customer = Customer::factory()->forTenant($this->tenant->id)->create();
            $user = User::factory()->create();

            CustomerUserAccess::factory()
                ->forTenant($this->tenant->id)
                ->forUser($user)
                ->forCustomer($customer)
                ->create();

            expect($customer->userAccesses)->toHaveCount(1)
                ->and($customer->userAccesses->first())->toBeInstanceOf(CustomerUserAccess::class);
        });
    });
});

describe('CustomerUserAccess Model', function (): void {
    describe('factory', function (): void {
        it('creates a valid access record', function (): void {
            $customer = Customer::factory()->forTenant($this->tenant->id)->create();
            $user = User::factory()->create();

            $access = CustomerUserAccess::factory()
                ->forTenant($this->tenant->id)
                ->forUser($user)
                ->forCustomer($customer)
                ->create();

            expect($access)->toBeInstanceOf(CustomerUserAccess::class)
                ->and($access->id)->toBeString()
                ->and($access->tenant_id)->toBe($this->tenant->id);
        });

        it('creates access with include_descendants', function (): void {
            $customer = Customer::factory()->forTenant($this->tenant->id)->create();
            $user = User::factory()->create();

            $access = CustomerUserAccess::factory()
                ->forTenant($this->tenant->id)
                ->forUser($user)
                ->forCustomer($customer)
                ->withDescendants()
                ->create();

            expect($access->include_descendants)->toBeTrue();
        });

        it('creates access without descendants', function (): void {
            $customer = Customer::factory()->forTenant($this->tenant->id)->create();
            $user = User::factory()->create();

            $access = CustomerUserAccess::factory()
                ->forTenant($this->tenant->id)
                ->forUser($user)
                ->forCustomer($customer)
                ->withoutDescendants()
                ->create();

            expect($access->include_descendants)->toBeFalse();
        });
    });

    describe('relationships', function (): void {
        it('belongs to tenant', function (): void {
            $customer = Customer::factory()->forTenant($this->tenant->id)->create();
            $user = User::factory()->create();

            $access = CustomerUserAccess::factory()
                ->forTenant($this->tenant->id)
                ->forUser($user)
                ->forCustomer($customer)
                ->create();

            expect($access->tenant)->toBeInstanceOf(TenantKey::class)
                ->and($access->tenant->id)->toBe($this->tenant->id);
        });

        it('belongs to user', function (): void {
            $customer = Customer::factory()->forTenant($this->tenant->id)->create();
            $user = User::factory()->create();

            $access = CustomerUserAccess::factory()
                ->forTenant($this->tenant->id)
                ->forUser($user)
                ->forCustomer($customer)
                ->create();

            expect($access->user)->toBeInstanceOf(User::class)
                ->and($access->user->id)->toBe($user->id);
        });

        it('belongs to customer', function (): void {
            $customer = Customer::factory()->forTenant($this->tenant->id)->create();
            $user = User::factory()->create();

            $access = CustomerUserAccess::factory()
                ->forTenant($this->tenant->id)
                ->forUser($user)
                ->forCustomer($customer)
                ->create();

            expect($access->customer)->toBeInstanceOf(Customer::class)
                ->and($access->customer->id)->toBe($customer->id);
        });
    });

    describe('getAccessibleCustomers', function (): void {
        it('returns only assigned customer when include_descendants is false', function (): void {
            $parent = Customer::factory()->forTenant($this->tenant->id)->create();
            $child = Customer::factory()->forTenant($this->tenant->id)->create();
            $child->setParent($parent);

            $user = User::factory()->create();

            $access = CustomerUserAccess::factory()
                ->forTenant($this->tenant->id)
                ->forUser($user)
                ->forCustomer($parent)
                ->withoutDescendants()
                ->create();

            $accessible = $access->getAccessibleCustomers();

            expect($accessible)->toHaveCount(1)
                ->and($accessible->first()->id)->toBe($parent->id);
        });

        it('returns customer and descendants when include_descendants is true', function (): void {
            $parent = Customer::factory()->forTenant($this->tenant->id)->create();
            $child = Customer::factory()->forTenant($this->tenant->id)->create();
            $child->setParent($parent);

            $user = User::factory()->create();

            $access = CustomerUserAccess::factory()
                ->forTenant($this->tenant->id)
                ->forUser($user)
                ->forCustomer($parent)
                ->withDescendants()
                ->create();

            $accessible = $access->getAccessibleCustomers();

            expect($accessible)->toHaveCount(2)
                ->and($accessible->pluck('id')->toArray())->toContain($parent->id, $child->id);
        });
    });

    describe('getAccessibleCustomersForUser', function (): void {
        it('returns empty collection when user has no access', function (): void {
            $user = User::factory()->create();

            $accessible = CustomerUserAccess::getAccessibleCustomersForUser($user);

            expect($accessible)->toBeEmpty();
        });

        it('aggregates customers from multiple access records', function (): void {
            $customer1 = Customer::factory()->forTenant($this->tenant->id)->create();
            $customer2 = Customer::factory()->forTenant($this->tenant->id)->create();
            $user = User::factory()->create();

            CustomerUserAccess::factory()
                ->forTenant($this->tenant->id)
                ->forUser($user)
                ->forCustomer($customer1)
                ->withoutDescendants()
                ->create();

            CustomerUserAccess::factory()
                ->forTenant($this->tenant->id)
                ->forUser($user)
                ->forCustomer($customer2)
                ->withoutDescendants()
                ->create();

            $accessible = CustomerUserAccess::getAccessibleCustomersForUser($user);

            expect($accessible)->toHaveCount(2)
                ->and($accessible->pluck('id')->toArray())->toContain($customer1->id, $customer2->id);
        });
    });
});

describe('CustomerUserObjectAccess Model', function (): void {
    describe('factory', function (): void {
        it('creates a valid object access record', function (): void {
            $customer = Customer::factory()->forTenant($this->tenant->id)->create();
            $object = SecPalObject::factory()->forCustomer($customer)->forTenant($this->tenant->id)->create();
            $user = User::factory()->create();

            $access = CustomerUserObjectAccess::factory()
                ->forTenant($this->tenant->id)
                ->forUser($user)
                ->forObject($object)
                ->create();

            expect($access)->toBeInstanceOf(CustomerUserObjectAccess::class)
                ->and($access->id)->toBeString()
                ->and($access->allowed_actions)->toBeArray();
        });

        it('creates access with full read access', function (): void {
            $customer = Customer::factory()->forTenant($this->tenant->id)->create();
            $object = SecPalObject::factory()->forCustomer($customer)->forTenant($this->tenant->id)->create();
            $user = User::factory()->create();

            $access = CustomerUserObjectAccess::factory()
                ->forTenant($this->tenant->id)
                ->forUser($user)
                ->forObject($object)
                ->fullReadAccess()
                ->create();

            expect($access->allowed_actions)->toBe(CustomerUserObjectAccess::AVAILABLE_ACTIONS);
        });
    });

    describe('relationships', function (): void {
        it('belongs to tenant', function (): void {
            $customer = Customer::factory()->forTenant($this->tenant->id)->create();
            $object = SecPalObject::factory()->forCustomer($customer)->forTenant($this->tenant->id)->create();
            $user = User::factory()->create();

            $access = CustomerUserObjectAccess::factory()
                ->forTenant($this->tenant->id)
                ->forUser($user)
                ->forObject($object)
                ->create();

            expect($access->tenant)->toBeInstanceOf(TenantKey::class)
                ->and($access->tenant->id)->toBe($this->tenant->id);
        });

        it('belongs to user', function (): void {
            $customer = Customer::factory()->forTenant($this->tenant->id)->create();
            $object = SecPalObject::factory()->forCustomer($customer)->forTenant($this->tenant->id)->create();
            $user = User::factory()->create();

            $access = CustomerUserObjectAccess::factory()
                ->forTenant($this->tenant->id)
                ->forUser($user)
                ->forObject($object)
                ->create();

            expect($access->user)->toBeInstanceOf(User::class)
                ->and($access->user->id)->toBe($user->id);
        });

        it('belongs to object', function (): void {
            $customer = Customer::factory()->forTenant($this->tenant->id)->create();
            $object = SecPalObject::factory()->forCustomer($customer)->forTenant($this->tenant->id)->create();
            $user = User::factory()->create();

            $access = CustomerUserObjectAccess::factory()
                ->forTenant($this->tenant->id)
                ->forUser($user)
                ->forObject($object)
                ->create();

            expect($access->object)->toBeInstanceOf(SecPalObject::class)
                ->and($access->object->id)->toBe($object->id);
        });
    });

    describe('canPerformAction', function (): void {
        it('returns true for allowed action', function (): void {
            $customer = Customer::factory()->forTenant($this->tenant->id)->create();
            $object = SecPalObject::factory()->forCustomer($customer)->forTenant($this->tenant->id)->create();
            $user = User::factory()->create();

            $access = CustomerUserObjectAccess::factory()
                ->forTenant($this->tenant->id)
                ->forUser($user)
                ->forObject($object)
                ->withActions(['read_guard_book', 'view_incidents'])
                ->create();

            expect($access->canPerformAction('read_guard_book'))->toBeTrue()
                ->and($access->canPerformAction('view_incidents'))->toBeTrue();
        });

        it('returns false for disallowed action', function (): void {
            $customer = Customer::factory()->forTenant($this->tenant->id)->create();
            $object = SecPalObject::factory()->forCustomer($customer)->forTenant($this->tenant->id)->create();
            $user = User::factory()->create();

            $access = CustomerUserObjectAccess::factory()
                ->forTenant($this->tenant->id)
                ->forUser($user)
                ->forObject($object)
                ->withActions(['read_guard_book'])
                ->create();

            expect($access->canPerformAction('export_reports'))->toBeFalse();
        });
    });

    describe('grantAction', function (): void {
        it('adds action to allowed actions', function (): void {
            $customer = Customer::factory()->forTenant($this->tenant->id)->create();
            $object = SecPalObject::factory()->forCustomer($customer)->forTenant($this->tenant->id)->create();
            $user = User::factory()->create();

            $access = CustomerUserObjectAccess::factory()
                ->forTenant($this->tenant->id)
                ->forUser($user)
                ->forObject($object)
                ->withActions(['read_guard_book'])
                ->create();

            $access->grantAction('export_reports');

            expect($access->allowed_actions)->toContain('read_guard_book', 'export_reports');
        });

        it('does not duplicate existing action', function (): void {
            $customer = Customer::factory()->forTenant($this->tenant->id)->create();
            $object = SecPalObject::factory()->forCustomer($customer)->forTenant($this->tenant->id)->create();
            $user = User::factory()->create();

            $access = CustomerUserObjectAccess::factory()
                ->forTenant($this->tenant->id)
                ->forUser($user)
                ->forObject($object)
                ->withActions(['read_guard_book'])
                ->create();

            $access->grantAction('read_guard_book');

            expect($access->allowed_actions)->toHaveCount(1);
        });

        it('throws exception for unknown action', function (): void {
            $customer = Customer::factory()->forTenant($this->tenant->id)->create();
            $object = SecPalObject::factory()->forCustomer($customer)->forTenant($this->tenant->id)->create();
            $user = User::factory()->create();

            $access = CustomerUserObjectAccess::factory()
                ->forTenant($this->tenant->id)
                ->forUser($user)
                ->forObject($object)
                ->create();

            expect(fn () => $access->grantAction('invalid_action'))
                ->toThrow(\InvalidArgumentException::class, 'Unknown action: invalid_action');
        });
    });

    describe('revokeAction', function (): void {
        it('removes action from allowed actions', function (): void {
            $customer = Customer::factory()->forTenant($this->tenant->id)->create();
            $object = SecPalObject::factory()->forCustomer($customer)->forTenant($this->tenant->id)->create();
            $user = User::factory()->create();

            $access = CustomerUserObjectAccess::factory()
                ->forTenant($this->tenant->id)
                ->forUser($user)
                ->forObject($object)
                ->withActions(['read_guard_book', 'export_reports'])
                ->create();

            $access->revokeAction('export_reports');

            expect($access->allowed_actions)->toContain('read_guard_book')
                ->and($access->allowed_actions)->not->toContain('export_reports');
        });

        it('handles revoking non-existent action gracefully', function (): void {
            $customer = Customer::factory()->forTenant($this->tenant->id)->create();
            $object = SecPalObject::factory()->forCustomer($customer)->forTenant($this->tenant->id)->create();
            $user = User::factory()->create();

            $access = CustomerUserObjectAccess::factory()
                ->forTenant($this->tenant->id)
                ->forUser($user)
                ->forObject($object)
                ->withActions(['read_guard_book'])
                ->create();

            $access->revokeAction('export_reports');

            expect($access->allowed_actions)->toContain('read_guard_book');
        });
    });

    describe('userCanAccessObject', function (): void {
        it('returns true when user has access with action', function (): void {
            $customer = Customer::factory()->forTenant($this->tenant->id)->create();
            $object = SecPalObject::factory()->forCustomer($customer)->forTenant($this->tenant->id)->create();
            $user = User::factory()->create();

            CustomerUserObjectAccess::factory()
                ->forTenant($this->tenant->id)
                ->forUser($user)
                ->forObject($object)
                ->withActions(['read_guard_book'])
                ->create();

            expect(CustomerUserObjectAccess::userCanAccessObject($user, $object, 'read_guard_book'))->toBeTrue();
        });

        it('returns false when user has access but not the action', function (): void {
            $customer = Customer::factory()->forTenant($this->tenant->id)->create();
            $object = SecPalObject::factory()->forCustomer($customer)->forTenant($this->tenant->id)->create();
            $user = User::factory()->create();

            CustomerUserObjectAccess::factory()
                ->forTenant($this->tenant->id)
                ->forUser($user)
                ->forObject($object)
                ->withActions(['read_guard_book'])
                ->create();

            expect(CustomerUserObjectAccess::userCanAccessObject($user, $object, 'export_reports'))->toBeFalse();
        });

        it('returns false when user has no access to object', function (): void {
            $customer = Customer::factory()->forTenant($this->tenant->id)->create();
            $object = SecPalObject::factory()->forCustomer($customer)->forTenant($this->tenant->id)->create();
            $user = User::factory()->create();

            expect(CustomerUserObjectAccess::userCanAccessObject($user, $object, 'read_guard_book'))->toBeFalse();
        });
    });
});

<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\OrganizationalUnit;
use App\Models\OrganizationalUnitClosure;
use App\Models\TenantKey;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * @property TenantKey $tenant
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    incrementTestKekCounter();
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

describe('OrganizationalUnit Model', function () {
    describe('Basic CRUD Operations', function () {
        it('can create an organizational unit with required fields', function (): void {
            $unit = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'ProSec Nord GmbH',
                'type' => 'company',
            ]);

            expect($unit)->toBeInstanceOf(OrganizationalUnit::class);
            $unit->refresh();

            expect($unit->id)->toBeString();
            expect($unit->name)->toBe('ProSec Nord GmbH');
            expect($unit->type)->toBe('company');
            expect($unit->tenant_id)->toBe($this->tenant->id);
            expect($unit->is_legal_entity)->toBeFalse();
            expect($unit->is_establishment)->toBeFalse();
        });

        it('uses UUID for primary key', function (): void {
            $unit = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Test Unit',
                'type' => 'department',
            ]);

            // UUID v4 format: 8-4-4-4-12 hex characters
            expect($unit->id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/');
        });

        it('can create unit with all optional fields', function (): void {
            $metadata = [
                'address' => 'Musterstraße 1, 10115 Berlin',
                'phone' => '+49 30 12345678',
                'manager' => 'Max Mustermann',
            ];

            $unit = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Custom Unit',
                'type' => 'custom',
                'custom_type_name' => 'Einsatzgebiet',
                'description' => 'A custom organizational unit type',
                'metadata' => $metadata,
            ]);

            expect($unit->custom_type_name)->toBe('Einsatzgebiet');
            expect($unit->description)->toBe('A custom organizational unit type');
            expect($unit->metadata)->toBe($metadata);
        });

        it('mass assigns and casts independent legal status flags', function (): void {
            $unit = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Status Unit',
                'type' => 'company',
                'is_legal_entity' => 1,
                'is_establishment' => 0,
            ]);

            $unit->refresh();

            expect($unit->is_legal_entity)->toBeTrue()
                ->and($unit->is_establishment)->toBeFalse();

            $unit->update([
                'is_legal_entity' => false,
                'is_establishment' => true,
            ]);

            $unit->refresh();

            expect($unit->is_legal_entity)->toBeFalse()
                ->and($unit->is_establishment)->toBeTrue();
        });

        it('factory uses stable false defaults for independent legal status flags', function (): void {
            $unit = OrganizationalUnit::factory()->forTenant((string) $this->tenant->id)->create();

            expect($unit->is_legal_entity)->toBeFalse()
                ->and($unit->is_establishment)->toBeFalse();
        });

        it('mass assigns and casts independent operational status flags', function (): void {
            $unit = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Operational Status Unit',
                'type' => 'company',
                'is_active' => 0,
                'is_assignable' => 1,
            ]);

            $unit->refresh();

            expect($unit->is_active)->toBeFalse()
                ->and($unit->is_assignable)->toBeTrue();
        });

        it('casts metadata to array', function (): void {
            $metadata = ['key' => 'value', 'nested' => ['a' => 1]];

            $unit = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Test Unit',
                'type' => 'branch',
                'metadata' => $metadata,
            ]);

            // Reload from database
            $unit->refresh();

            expect($unit->metadata)->toBeArray();
            expect($unit->metadata['key'])->toBe('value');
            expect($unit->metadata['nested']['a'])->toBe(1);
        });

        it('supports soft deletes', function (): void {
            $unit = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Deletable Unit',
                'type' => 'department',
            ]);

            $unitId = $unit->id;
            $unit->delete();

            // Not found with default query
            expect(OrganizationalUnit::find($unitId))->toBeNull();

            // Found with trashed
            expect(OrganizationalUnit::withTrashed()->find($unitId))->not->toBeNull();
            expect(OrganizationalUnit::withTrashed()->find($unitId)->deleted_at)->not->toBeNull();
        });

        it('preserves ancestor and self closures for a soft-deleted leaf unit', function (): void {
            $parent = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Parent Unit',
                'type' => 'company',
            ]);

            $unit = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Restorable Unit',
                'type' => 'department',
            ]);
            $unit->setParent($parent);

            $unit->delete();

            expect(OrganizationalUnitClosure::query()
                ->where('ancestor_id', $parent->id)
                ->where('descendant_id', $unit->id)
                ->where('depth', 1)
                ->exists())->toBeTrue()
                ->and(OrganizationalUnitClosure::query()
                    ->where('ancestor_id', $unit->id)
                    ->where('descendant_id', $unit->id)
                    ->where('depth', 0)
                    ->exists())->toBeTrue();
        });

        it('restores a child as a root when its parent remains soft deleted', function (): void {
            $parent = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Deleted Parent Unit',
                'type' => 'company',
            ]);

            $child = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Restored Child Unit',
                'type' => 'department',
            ]);
            $child->setParent($parent);

            $child->delete();
            $parent->delete();
            $child->restore();

            $child->refresh();

            expect($child->parent)->toBeNull()
                ->and(OrganizationalUnit::roots()->pluck('id')->all())->toContain($child->id)
                ->and(OrganizationalUnitClosure::query()
                    ->where('ancestor_id', $parent->id)
                    ->where('descendant_id', $child->id)
                    ->exists())->toBeFalse();
        });
    });

    describe('Type Enum Values', function () {
        it('accepts all valid type enum values', function (): void {
            $validTypes = ['holding', 'company', 'region', 'branch', 'division', 'department', 'custom'];

            foreach ($validTypes as $type) {
                $unit = OrganizationalUnit::create([
                    'tenant_id' => $this->tenant->id,
                    'name' => "Unit Type {$type}",
                    'type' => $type,
                ]);

                expect($unit->type)->toBe($type);
            }
        });
    });

    describe('Tenant Relationship', function () {
        it('belongs to a tenant', function (): void {
            $unit = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Tenant Unit',
                'type' => 'company',
            ]);

            expect($unit->tenant)->toBeInstanceOf(TenantKey::class);
            expect($unit->tenant->id)->toBe($this->tenant->id);
        });

        it('is deleted when tenant is deleted (cascade)', function (): void {
            $unit = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Cascading Unit',
                'type' => 'branch',
            ]);

            $unitId = $unit->id;
            $this->tenant->delete();

            expect(OrganizationalUnit::withTrashed()->find($unitId))->toBeNull();
        });
    });

    describe('Closure Table Integration', function () {
        it('creates self-reference closure entry on creation', function (): void {
            $unit = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Self-Referencing Unit',
                'type' => 'department',
            ]);

            $selfClosure = OrganizationalUnitClosure::where('ancestor_id', $unit->id)
                ->where('descendant_id', $unit->id)
                ->first();

            expect($selfClosure)->not->toBeNull();
            expect($selfClosure->depth)->toBe(0);
        });

        it('creates parent closure entries when setting parent', function (): void {
            // Create parent hierarchy: Holding -> Company -> Branch
            $holding = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'ProSec Holding',
                'type' => 'holding',
            ]);

            $company = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'ProSec Nord GmbH',
                'type' => 'company',
            ]);
            $company->setParent($holding);

            $branch = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Niederlassung Berlin',
                'type' => 'branch',
            ]);
            $branch->setParent($company);

            // Branch should have closures to: itself(0), company(1), holding(2)
            $branchClosures = OrganizationalUnitClosure::where('descendant_id', $branch->id)
                ->orderBy('depth')
                ->get();

            expect($branchClosures)->toHaveCount(3);
            expect($branchClosures[0]->ancestor_id)->toBe($branch->id);
            expect($branchClosures[0]->depth)->toBe(0);
            expect($branchClosures[1]->ancestor_id)->toBe($company->id);
            expect($branchClosures[1]->depth)->toBe(1);
            expect($branchClosures[2]->ancestor_id)->toBe($holding->id);
            expect($branchClosures[2]->depth)->toBe(2);
        });
    });

    describe('Hierarchy Navigation', function () {
        beforeEach(function (): void {
            // Build test hierarchy:
            // ProSec Holding
            // └─ ProSec Nord GmbH
            //    ├─ Region Berlin
            //    │  ├─ Niederlassung Berlin
            //    │  └─ Niederlassung Potsdam
            //    └─ Region Hamburg
            //       └─ Niederlassung Hamburg

            $this->holding = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'ProSec Holding',
                'type' => 'holding',
            ]);

            $this->company = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'ProSec Nord GmbH',
                'type' => 'company',
            ]);
            $this->company->setParent($this->holding);

            $this->regionBerlin = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Region Berlin',
                'type' => 'region',
            ]);
            $this->regionBerlin->setParent($this->company);

            $this->branchBerlin = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Niederlassung Berlin',
                'type' => 'branch',
            ]);
            $this->branchBerlin->setParent($this->regionBerlin);

            $this->branchPotsdam = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Niederlassung Potsdam',
                'type' => 'branch',
            ]);
            $this->branchPotsdam->setParent($this->regionBerlin);

            $this->regionHamburg = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Region Hamburg',
                'type' => 'region',
            ]);
            $this->regionHamburg->setParent($this->company);

            $this->branchHamburg = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Niederlassung Hamburg',
                'type' => 'branch',
            ]);
            $this->branchHamburg->setParent($this->regionHamburg);
        });

        it('can get parent via parent accessor', function (): void {
            expect($this->branchBerlin->parent)->not->toBeNull();
            expect($this->branchBerlin->parent->id)->toBe($this->regionBerlin->id);

            expect($this->regionBerlin->parent->id)->toBe($this->company->id);
            expect($this->company->parent->id)->toBe($this->holding->id);
            expect($this->holding->parent)->toBeNull();
        });

        it('can get direct children via children accessor', function (): void {
            $regionBerlinChildren = $this->regionBerlin->children;

            expect($regionBerlinChildren)->toHaveCount(2);
            expect($regionBerlinChildren->pluck('name')->toArray())
                ->toContain('Niederlassung Berlin')
                ->toContain('Niederlassung Potsdam');
        });

        it('can get all ancestors via ancestors() relationship', function (): void {
            $ancestors = $this->branchBerlin->ancestors;

            expect($ancestors)->toHaveCount(3);
            expect($ancestors->pluck('name')->toArray())
                ->toContain('Region Berlin')
                ->toContain('ProSec Nord GmbH')
                ->toContain('ProSec Holding');
        });

        it('can get all descendants via descendants() relationship', function (): void {
            $descendants = $this->company->descendants;

            expect($descendants)->toHaveCount(5);
            expect($descendants->pluck('name')->toArray())
                ->toContain('Region Berlin')
                ->toContain('Niederlassung Berlin')
                ->toContain('Niederlassung Potsdam')
                ->toContain('Region Hamburg')
                ->toContain('Niederlassung Hamburg');
        });

        it('ancestors are ordered by depth (closest first)', function (): void {
            $ancestors = $this->branchBerlin->ancestors;

            expect($ancestors[0]->name)->toBe('Region Berlin');          // depth 1
            expect($ancestors[1]->name)->toBe('ProSec Nord GmbH');       // depth 2
            expect($ancestors[2]->name)->toBe('ProSec Holding');         // depth 3
        });

        it('descendants include nested children at all levels', function (): void {
            $holdingDescendants = $this->holding->descendants;

            // All 6 other units should be descendants
            expect($holdingDescendants)->toHaveCount(6);
        });
    });

    describe('Hierarchy Query Methods', function () {
        beforeEach(function (): void {
            $this->holding = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'ProSec Holding',
                'type' => 'holding',
            ]);

            $this->company = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'ProSec Nord GmbH',
                'type' => 'company',
            ]);
            $this->company->setParent($this->holding);

            $this->branch = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Niederlassung Berlin',
                'type' => 'branch',
            ]);
            $this->branch->setParent($this->company);
        });

        it('isAncestorOf() returns true for ancestors', function (): void {
            expect($this->holding->isAncestorOf($this->company))->toBeTrue();
            expect($this->holding->isAncestorOf($this->branch))->toBeTrue();
            expect($this->company->isAncestorOf($this->branch))->toBeTrue();
        });

        it('isAncestorOf() returns false for non-ancestors', function (): void {
            expect($this->branch->isAncestorOf($this->holding))->toBeFalse();
            expect($this->branch->isAncestorOf($this->company))->toBeFalse();
            expect($this->company->isAncestorOf($this->holding))->toBeFalse();
        });

        it('isAncestorOf() returns false for self', function (): void {
            expect($this->holding->isAncestorOf($this->holding))->toBeFalse();
        });

        it('isDescendantOf() returns true for descendants', function (): void {
            expect($this->branch->isDescendantOf($this->company))->toBeTrue();
            expect($this->branch->isDescendantOf($this->holding))->toBeTrue();
            expect($this->company->isDescendantOf($this->holding))->toBeTrue();
        });

        it('isDescendantOf() returns false for non-descendants', function (): void {
            expect($this->holding->isDescendantOf($this->company))->toBeFalse();
            expect($this->holding->isDescendantOf($this->branch))->toBeFalse();
            expect($this->company->isDescendantOf($this->branch))->toBeFalse();
        });

        it('isDescendantOf() returns false for self', function (): void {
            expect($this->branch->isDescendantOf($this->branch))->toBeFalse();
        });

        it('getDepth() returns correct depth from root', function (): void {
            expect($this->holding->getDepth())->toBe(0);
            expect($this->company->getDepth())->toBe(1);
            expect($this->branch->getDepth())->toBe(2);
        });
    });

    describe('Root Units Scope', function () {
        it('roots() scope returns only top-level units', function (): void {
            $holding1 = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Holding 1',
                'type' => 'holding',
            ]);

            $holding2 = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Holding 2',
                'type' => 'holding',
            ]);

            $company = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Company under Holding 1',
                'type' => 'company',
            ]);
            $company->setParent($holding1);

            $roots = OrganizationalUnit::roots()->get();

            expect($roots)->toHaveCount(2);
            expect($roots->pluck('name')->toArray())
                ->toContain('Holding 1')
                ->toContain('Holding 2');
        });
    });

    describe('Moving Units in Hierarchy', function () {
        it('can move unit to new parent', function (): void {
            $holding = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Holding',
                'type' => 'holding',
            ]);

            $company1 = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Company 1',
                'type' => 'company',
            ]);
            $company1->setParent($holding);

            $company2 = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Company 2',
                'type' => 'company',
            ]);
            $company2->setParent($holding);

            $branch = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Branch',
                'type' => 'branch',
            ]);
            $branch->setParent($company1);

            // Move branch from company1 to company2
            $branch->setParent($company2);

            // Verify new parent
            $branch->refresh();
            expect($branch->parent->id)->toBe($company2->id);

            // Verify ancestors updated
            $ancestors = $branch->ancestors;
            expect($ancestors)->toHaveCount(2);
            expect($ancestors->pluck('name')->toArray())
                ->toContain('Company 2')
                ->toContain('Holding');

            // Verify old parent no longer has this child
            $company1->refresh();
            expect($company1->children)->toHaveCount(0);
        });

        it('can make unit a root by removing parent', function (): void {
            $holding = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Holding',
                'type' => 'holding',
            ]);

            $company = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Company',
                'type' => 'company',
            ]);
            $company->setParent($holding);

            // Remove parent to make it a root
            $company->removeParent();

            $company->refresh();
            expect($company->parent)->toBeNull();
            expect($company->ancestors)->toHaveCount(0);

            // Should appear in roots()
            $roots = OrganizationalUnit::roots()->get();
            expect($roots->pluck('name')->toArray())->toContain('Company');
        });

        it('moving parent also moves all descendants', function (): void {
            $holding = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Holding',
                'type' => 'holding',
            ]);

            $company = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Company',
                'type' => 'company',
            ]);
            $company->setParent($holding);

            $region = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Region',
                'type' => 'region',
            ]);
            $region->setParent($company);

            $branch = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Branch',
                'type' => 'branch',
            ]);
            $branch->setParent($region);

            // Create new holding and move company there
            $newHolding = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'New Holding',
                'type' => 'holding',
            ]);

            $company->setParent($newHolding);

            // Branch should now have New Holding as ancestor, not old Holding
            $branch->refresh();
            $branchAncestors = $branch->ancestors->pluck('name')->toArray();

            expect($branchAncestors)->toContain('New Holding');
            expect($branchAncestors)->not->toContain('Holding');
        });

        it('prevents setting self as parent', function (): void {
            $unit = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Unit',
                'type' => 'company',
            ]);

            expect(fn () => $unit->setParent($unit))
                ->toThrow(InvalidArgumentException::class, 'Cannot set unit as its own parent.');
        });

        it('prevents setting a descendant as parent (cycle prevention)', function (): void {
            // Create hierarchy: A -> B -> C
            $a = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Unit A',
                'type' => 'holding',
            ]);

            $b = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Unit B',
                'type' => 'company',
            ]);
            $b->setParent($a);

            $c = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Unit C',
                'type' => 'branch',
            ]);
            $c->setParent($b);

            // Trying to set C as parent of A would create a cycle: A -> B -> C -> A
            expect(fn () => $a->setParent($c))
                ->toThrow(InvalidArgumentException::class, 'Cannot set a descendant as parent (would create a cycle).');

            // Similarly, trying to set B as parent of A
            expect(fn () => $a->setParent($b))
                ->toThrow(InvalidArgumentException::class, 'Cannot set a descendant as parent (would create a cycle).');
        });
    });
});

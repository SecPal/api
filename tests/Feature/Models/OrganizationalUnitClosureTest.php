<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use App\Models\OrganizationalUnit;
use App\Models\OrganizationalUnitClosure;
use App\Models\TenantKey;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantKey::setKekPath(getTestKekPath());
    TenantKey::generateKek();
    $keys = TenantKey::generateEnvelopeKeys();
    $this->tenant = TenantKey::create($keys);
});

afterEach(function (): void {
    cleanupTestKekFile();
    TenantKey::setKekPath(null);
});

describe('OrganizationalUnitClosure Model', function () {
    describe('Basic Structure', function () {
        it('can create a closure entry', function (): void {
            $unit = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Test Unit',
                'type' => 'department',
            ]);

            // Self-reference is created automatically, verify it exists
            $closure = OrganizationalUnitClosure::where('ancestor_id', $unit->id)
                ->where('descendant_id', $unit->id)
                ->first();

            expect($closure)->not->toBeNull();
            expect($closure->depth)->toBe(0);
        });

        it('has composite primary key of ancestor_id and descendant_id', function (): void {
            $unit = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Test Unit',
                'type' => 'department',
            ]);

            // Try to create duplicate - should fail
            $this->expectException(\Illuminate\Database\QueryException::class);

            OrganizationalUnitClosure::create([
                'ancestor_id' => $unit->id,
                'descendant_id' => $unit->id,
                'depth' => 0,
            ]);
        });
    });

    describe('Relationships', function () {
        it('belongs to ancestor organizational unit', function (): void {
            $unit = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Test Unit',
                'type' => 'department',
            ]);

            $closure = OrganizationalUnitClosure::where('ancestor_id', $unit->id)
                ->where('descendant_id', $unit->id)
                ->first();

            expect($closure->ancestor)->toBeInstanceOf(OrganizationalUnit::class);
            expect($closure->ancestor->id)->toBe($unit->id);
        });

        it('belongs to descendant organizational unit', function (): void {
            $unit = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Test Unit',
                'type' => 'department',
            ]);

            $closure = OrganizationalUnitClosure::where('ancestor_id', $unit->id)
                ->where('descendant_id', $unit->id)
                ->first();

            expect($closure->descendant)->toBeInstanceOf(OrganizationalUnit::class);
            expect($closure->descendant->id)->toBe($unit->id);
        });
    });

    describe('Cascade Delete', function () {
        it('deletes closure entries when ancestor unit is deleted', function (): void {
            $parent = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Parent',
                'type' => 'company',
            ]);

            $child = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Child',
                'type' => 'branch',
            ]);
            $child->setParent($parent);

            $parentId = $parent->id;

            // Force delete parent (not soft delete, to trigger FK cascade)
            $parent->forceDelete();

            // All closures referencing parent as ancestor should be gone
            expect(OrganizationalUnitClosure::where('ancestor_id', $parentId)->exists())->toBeFalse();
        });

        it('deletes closure entries when descendant unit is deleted', function (): void {
            $parent = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Parent',
                'type' => 'company',
            ]);

            $child = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Child',
                'type' => 'branch',
            ]);
            $child->setParent($parent);

            $childId = $child->id;

            // Force delete child
            $child->forceDelete();

            // All closures referencing child as descendant should be gone
            expect(OrganizationalUnitClosure::where('descendant_id', $childId)->exists())->toBeFalse();
        });
    });

    describe('Depth Constraints', function () {
        it('allows depth 0 for self-reference', function (): void {
            $unit = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Test Unit',
                'type' => 'department',
            ]);

            $selfClosure = OrganizationalUnitClosure::where('ancestor_id', $unit->id)
                ->where('descendant_id', $unit->id)
                ->first();

            expect($selfClosure->depth)->toBe(0);
        });

        it('correctly tracks depth for multi-level hierarchies', function (): void {
            $level0 = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Level 0',
                'type' => 'holding',
            ]);

            $level1 = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Level 1',
                'type' => 'company',
            ]);
            $level1->setParent($level0);

            $level2 = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Level 2',
                'type' => 'region',
            ]);
            $level2->setParent($level1);

            $level3 = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Level 3',
                'type' => 'branch',
            ]);
            $level3->setParent($level2);

            // Verify depths for level3's ancestors
            $closures = OrganizationalUnitClosure::where('descendant_id', $level3->id)
                ->orderBy('depth')
                ->get();

            expect($closures)->toHaveCount(4);
            expect($closures[0]->depth)->toBe(0); // self
            expect($closures[0]->ancestor_id)->toBe($level3->id);
            expect($closures[1]->depth)->toBe(1); // direct parent (level2)
            expect($closures[1]->ancestor_id)->toBe($level2->id);
            expect($closures[2]->depth)->toBe(2); // grandparent (level1)
            expect($closures[2]->ancestor_id)->toBe($level1->id);
            expect($closures[3]->depth)->toBe(3); // great-grandparent (level0)
            expect($closures[3]->ancestor_id)->toBe($level0->id);
        });
    });

    describe('Query Helpers', function () {
        beforeEach(function (): void {
            $this->holding = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Holding',
                'type' => 'holding',
            ]);

            $this->company = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Company',
                'type' => 'company',
            ]);
            $this->company->setParent($this->holding);

            $this->branch = OrganizationalUnit::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Branch',
                'type' => 'branch',
            ]);
            $this->branch->setParent($this->company);
        });

        it('can find all descendants of a unit', function (): void {
            $descendantIds = OrganizationalUnitClosure::where('ancestor_id', $this->holding->id)
                ->where('depth', '>', 0) // exclude self
                ->pluck('descendant_id');

            expect($descendantIds)->toHaveCount(2);
            expect($descendantIds->toArray())
                ->toContain($this->company->id)
                ->toContain($this->branch->id);
        });

        it('can find all ancestors of a unit', function (): void {
            $ancestorIds = OrganizationalUnitClosure::where('descendant_id', $this->branch->id)
                ->where('depth', '>', 0) // exclude self
                ->pluck('ancestor_id');

            expect($ancestorIds)->toHaveCount(2);
            expect($ancestorIds->toArray())
                ->toContain($this->company->id)
                ->toContain($this->holding->id);
        });

        it('can find direct parent (depth = 1)', function (): void {
            $parentClosure = OrganizationalUnitClosure::where('descendant_id', $this->branch->id)
                ->where('depth', 1)
                ->first();

            expect($parentClosure)->not->toBeNull();
            expect($parentClosure->ancestor_id)->toBe($this->company->id);
        });

        it('can find direct children (depth = 1 from ancestor perspective)', function (): void {
            $childIds = OrganizationalUnitClosure::where('ancestor_id', $this->holding->id)
                ->where('depth', 1)
                ->pluck('descendant_id');

            expect($childIds)->toHaveCount(1);
            expect($childIds->first())->toBe($this->company->id);
        });
    });
});

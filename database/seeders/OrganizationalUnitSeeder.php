<?php

// SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

namespace Database\Seeders;

use App\Models\OrganizationalUnit;
use App\Models\OrganizationalUnitClosure;
use App\Models\TenantKey;
use Illuminate\Database\Seeder;

/**
 * Seeder for development organizational structure.
 *
 * Creates a realistic hierarchy:
 * - SecPal Holding (holding)
 *   - SecPal Germany (company)
 *     - North Region (region)
 *       - Hamburg Branch (branch)
 *       - Berlin Branch (branch)
 *     - South Region (region)
 *       - Munich Branch (branch)
 *   - SecPal Austria (company)
 *     - Vienna Branch (branch)
 *
 * This seeder is idempotent - it can be run multiple times safely.
 */
class OrganizationalUnitSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Ensure tenant key exists and get its ID
        $tenant = TenantKey::first();
        if (! $tenant) {
            TenantKey::ensureKekExists();
            $keys = TenantKey::generateEnvelopeKeys();
            $tenant = TenantKey::create($keys);
        }
        $tenantId = $tenant->id;

        // Create root holding
        $holding = OrganizationalUnit::firstOrCreate(
            ['name' => 'SecPal Holding', 'tenant_id' => $tenantId],
            [
                'type' => 'holding',
                'description' => 'Main holding company for SecPal Group',
            ]
        );

        // Ensure self-reference in closure table
        $this->ensureSelfReference($holding);

        // Create SecPal Germany
        $germany = OrganizationalUnit::firstOrCreate(
            ['name' => 'SecPal Germany', 'tenant_id' => $tenantId],
            [
                'type' => 'company',
                'description' => 'German subsidiary',
            ]
        );
        $this->ensureSelfReference($germany);
        $this->attachParent($germany, $holding);

        // Create North Region
        $northRegion = OrganizationalUnit::firstOrCreate(
            ['name' => 'North Region', 'tenant_id' => $tenantId],
            [
                'type' => 'region',
                'description' => 'Northern Germany operations',
            ]
        );
        $this->ensureSelfReference($northRegion);
        $this->attachParent($northRegion, $germany);

        // Create Hamburg Branch
        $hamburgBranch = OrganizationalUnit::firstOrCreate(
            ['name' => 'Hamburg Branch', 'tenant_id' => $tenantId],
            [
                'type' => 'branch',
                'description' => 'Hamburg office and operations',
            ]
        );
        $this->ensureSelfReference($hamburgBranch);
        $this->attachParent($hamburgBranch, $northRegion);

        // Create Berlin Branch
        $berlinBranch = OrganizationalUnit::firstOrCreate(
            ['name' => 'Berlin Branch', 'tenant_id' => $tenantId],
            [
                'type' => 'branch',
                'description' => 'Berlin office and operations',
            ]
        );
        $this->ensureSelfReference($berlinBranch);
        $this->attachParent($berlinBranch, $northRegion);

        // Create South Region
        $southRegion = OrganizationalUnit::firstOrCreate(
            ['name' => 'South Region', 'tenant_id' => $tenantId],
            [
                'type' => 'region',
                'description' => 'Southern Germany operations',
            ]
        );
        $this->ensureSelfReference($southRegion);
        $this->attachParent($southRegion, $germany);

        // Create Munich Branch
        $munichBranch = OrganizationalUnit::firstOrCreate(
            ['name' => 'Munich Branch', 'tenant_id' => $tenantId],
            [
                'type' => 'branch',
                'description' => 'Munich office and operations',
            ]
        );
        $this->ensureSelfReference($munichBranch);
        $this->attachParent($munichBranch, $southRegion);

        // Create SecPal Austria
        $austria = OrganizationalUnit::firstOrCreate(
            ['name' => 'SecPal Austria', 'tenant_id' => $tenantId],
            [
                'type' => 'company',
                'description' => 'Austrian subsidiary',
            ]
        );
        $this->ensureSelfReference($austria);
        $this->attachParent($austria, $holding);

        // Create Vienna Branch
        $viennaBranch = OrganizationalUnit::firstOrCreate(
            ['name' => 'Vienna Branch', 'tenant_id' => $tenantId],
            [
                'type' => 'branch',
                'description' => 'Vienna office and operations',
            ]
        );
        $this->ensureSelfReference($viennaBranch);
        $this->attachParent($viennaBranch, $austria);

        $this->command->info('OrganizationalUnit hierarchy seeded successfully.');
    }

    /**
     * Ensure self-reference exists in closure table (depth 0).
     */
    private function ensureSelfReference(OrganizationalUnit $unit): void
    {
        OrganizationalUnitClosure::firstOrCreate([
            'ancestor_id' => $unit->id,
            'descendant_id' => $unit->id,
            'depth' => 0,
        ]);
    }

    /**
     * Attach a parent to a unit in the closure table.
     * Creates all ancestor relationships with proper depths.
     */
    private function attachParent(OrganizationalUnit $child, OrganizationalUnit $parent): void
    {
        // Check if direct parent relationship already exists
        $exists = OrganizationalUnitClosure::where('ancestor_id', $parent->id)
            ->where('descendant_id', $child->id)
            ->where('depth', 1)
            ->exists();

        if ($exists) {
            return;
        }

        // Get all ancestors of the parent (including parent itself at depth 0)
        $parentAncestors = OrganizationalUnitClosure::where('descendant_id', $parent->id)->get();

        // Create closure entries for child to all parent's ancestors
        foreach ($parentAncestors as $ancestor) {
            OrganizationalUnitClosure::firstOrCreate([
                'ancestor_id' => $ancestor->ancestor_id,
                'descendant_id' => $child->id,
                'depth' => $ancestor->depth + 1,
            ]);
        }
    }
}

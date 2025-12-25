<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\TenantKey;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds default leadership levels for all tenants.
 *
 * Creates a standard hierarchical structure per ADR-009 (Leadership-Based Access Control).
 * Rank 1 = highest authority (CEO), ascending ranks = lower organizational levels.
 *
 * This seeder can be run multiple times - it only creates levels for tenants
 * that don't have any leadership levels yet.
 *
 * @see https://github.com/SecPal/api/issues/399 Epic #399: Leadership Levels System
 * @see https://github.com/SecPal/api/issues/423 Issue #423: Leadership Levels Database Migrations
 * @see docs/GUARD_ARCHITECTURE.md ADR-009: Permission Inheritance Blocking & Leadership-Based Access Control
 */
final class LeadershipLevelSeeder extends Seeder
{
    /**
     * Default leadership hierarchy structure.
     *
     * Following standard organizational hierarchy with rank 1 = CEO (highest).
     * Colors use professional palette for visual distinction in UI.
     *
     * @var array<int, array{rank: int, name: string, description: string, color: string}>
     */
    private const DEFAULT_LEVELS = [
        [
            'rank' => 1,
            'name' => 'C-Level',
            'description' => 'Chief Executive Officers and C-Suite members',
            'color' => '#8B4513',
        ],
        [
            'rank' => 2,
            'name' => 'Senior Management',
            'description' => 'Senior directors and vice presidents',
            'color' => '#4169E1',
        ],
        [
            'rank' => 3,
            'name' => 'Middle Management',
            'description' => 'Department heads and managers',
            'color' => '#32CD32',
        ],
        [
            'rank' => 4,
            'name' => 'Team Leads',
            'description' => 'Team leaders and supervisors',
            'color' => '#FFD700',
        ],
        [
            'rank' => 5,
            'name' => 'Senior Staff',
            'description' => 'Senior employees and specialists',
            'color' => '#FF8C00',
        ],
        [
            'rank' => 6,
            'name' => 'Staff',
            'description' => 'Regular employees and contributors',
            'color' => '#20B2AA',
        ],
    ];

    /**
     * Run the database seeds.
     *
     * Creates default leadership levels for all tenants that don't have any.
     * Uses database transactions to ensure atomicity per tenant.
     */
    public function run(): void
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int, TenantKey> $tenants */
        $tenants = TenantKey::all();

        foreach ($tenants as $tenant) {
            // Skip if tenant already has leadership levels
            $existingCount = DB::table('leadership_levels')
                ->where('tenant_id', $tenant->id)
                ->count();

            if ($existingCount > 0) {
                $this->command->info("Skipping tenant {$tenant->id} - already has leadership levels");

                continue;
            }

            DB::transaction(function () use ($tenant) {
                foreach (self::DEFAULT_LEVELS as $levelData) {
                    DB::table('leadership_levels')->insert([
                        'id' => (string) Str::uuid(),
                        'tenant_id' => $tenant->id,
                        'rank' => $levelData['rank'],
                        'name' => $levelData['name'],
                        'description' => $levelData['description'],
                        'color' => $levelData['color'],
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $this->command->info('Created '.count(self::DEFAULT_LEVELS)." leadership levels for tenant {$tenant->id}");
            });
        }

        $this->command->info('Leadership levels seeding completed');
    }
}

<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Database\Seeders;

use App\Models\OrganizationalUnit;
use App\Models\TenantKey;
use App\Models\User;
use App\Models\UserInternalOrganizationalScope;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Seed roles and permissions first
        $this->call(RolesAndPermissionsSeeder::class);

        // Seed organizational structure (creates tenant key if needed)
        $this->call(OrganizationalUnitSeeder::class);

        // Get tenant ID from created tenant key (OrganizationalUnitSeeder ensures it exists)
        $tenant = TenantKey::firstOrFail();
        $tenantId = $tenant->id;

        // Set the team/tenant context for role assignment
        app()[PermissionRegistrar::class]->setPermissionsTeamId($tenantId);

        // Create test user with Admin role
        $testUser = User::firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'password' => bcrypt('password'),
                'tenant_id' => $tenantId,
            ]
        );

        // Assign Admin role to test user (within tenant context)
        if (! $testUser->hasRole('Admin')) {
            $testUser->assignRole('Admin');
        }

        // Assign organizational scope to test user (admin access to holding = access to everything)
        $holding = OrganizationalUnit::where('name', 'SecPal Holding')->first();
        if ($holding) {
            UserInternalOrganizationalScope::firstOrCreate(
                [
                    'user_id' => $testUser->id,
                    'organizational_unit_id' => $holding->id,
                ],
                [
                    'access_level' => 'admin',
                    'include_descendants' => true,
                ]
            );
        }

        $this->command->info('Test user created with Admin role and full organizational access.');
    }
}

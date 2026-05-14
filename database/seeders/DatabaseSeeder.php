<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\TenantKey;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Seed roles and permissions first
        $this->call(RolesAndPermissionsSeeder::class);

        // Seed organizational structure (creates tenant key if needed)
        $this->call(OrganizationalUnitSeeder::class);

        // Seed standard onboarding form templates (system-wide)
        $this->call(OnboardingFormTemplatesSeeder::class);

        // Pre-contract demo employee for onboarding UI (independent from test@example.com)
        $this->call(OnboardingDemoUserSeeder::class);

        // Get tenant ID from created tenant key (OrganizationalUnitSeeder ensures it exists)
        $tenant = TenantKey::firstOrFail();
        $tenantId = $tenant->id;

        // Set the team/tenant context for role assignment
        app()[PermissionRegistrar::class]->setPermissionsTeamId($tenantId);

        // Create test user with direct permissions and full organizational scopes
        $testUser = User::firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'password' => bcrypt('password'),
                'tenant_id' => $tenantId,
            ]
        );

        if (! $testUser->hasVerifiedEmail()) {
            $testUser->forceFill([
                'email_verified_at' => now(),
            ])->save();
        }

        /** @var list<string> $permissionNames */
        $permissionNames = Permission::query()
            ->where('guard_name', 'sanctum')
            ->pluck('name')
            ->all();

        foreach ($permissionNames as $permissionName) {
            if (! $testUser->hasDirectPermission($permissionName)) {
                $testUser->givePermissionTo($permissionName);
            }
        }

        // The seeded test user gets TWO organizational scopes for full access:
        // 1. Scope 0-0 for Guards (non-leadership employees, rank = NULL)
        // 2. Scope 1-255 for all Leadership levels (FE1 to FE255)
        $orgUnit = \App\Models\OrganizationalUnit::firstOrCreate(
            ['name' => 'Headquarters', 'tenant_id' => $tenantId],
            ['type' => 'holding']
        );

        // Scope for Guards (non-leadership)
        \App\Models\UserInternalOrganizationalScope::updateOrCreate(
            [
                'user_id' => $testUser->id,
                'organizational_unit_id' => $orgUnit->id,
                'min_viewable_rank' => 0,
                'max_viewable_rank' => 0,
            ],
            [
                'access_level' => 'manage',
                'include_descendants' => true,
                'allow_self_access' => true,
                'min_assignable_rank' => 0,
                'max_assignable_rank' => 0,
            ]
        );

        // Scope for all Leadership levels
        \App\Models\UserInternalOrganizationalScope::updateOrCreate(
            [
                'user_id' => $testUser->id,
                'organizational_unit_id' => $orgUnit->id,
                'min_viewable_rank' => 1,
                'max_viewable_rank' => 255,
            ],
            [
                'access_level' => 'manage',
                'include_descendants' => true,
                'allow_self_access' => true,
                'min_assignable_rank' => 1,
                'max_assignable_rank' => 255,
            ]
        );

        $this->command->info('Test user created with direct permissions and organizational scopes (0-0 for Guards, 1-255 for Leadership).');
    }
}

<?php

// SPDX-FileCopyrightText: 2025 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
 // ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Get process-specific KEK path for parallel test execution.
 * Centralized helper to avoid duplication across test files.
 */
function getTestKekPath(): string
{
    return storage_path('app/keys/kek-test-'.getmypid().'.key');
}

/**
 * Clean up the KEK file for the current process.
 */
function cleanupTestKekFile(): void
{
    $kekPath = getTestKekPath();
    if (file_exists($kekPath)) {
        unlink($kekPath);
    }
}

/**
 * Assign permissions to a user with proper tenant context.
 * Sets Spatie Permission team ID, assigns permission, then resets team ID.
 */
function givePermissionWithTenant(\App\Models\User $user, int $tenantId, string $permission): void
{
    $registrar = app(\Spatie\Permission\PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($tenantId);
    $user->givePermissionTo($permission);
    $registrar->setPermissionsTeamId(null);
}

/**
 * Assign role to user with temporal attributes, bypassing relationship constraints.
 * Directly inserts into model_has_roles with tenant_id support.
 */
function assignTemporalRole(
    \App\Models\User $user,
    \Spatie\Permission\Models\Role $role,
    int $tenantId,
    array $attributes = []
): void {
    $now = now();

    \Illuminate\Support\Facades\DB::table('model_has_roles')->insert(array_merge([
        'model_type' => get_class($user),
        'model_id' => $user->id,
        'role_id' => $role->id,
        'tenant_id' => $tenantId,
        'auto_revoke' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ], $attributes));

    // Clear relationship cache
    $user->unsetRelation('roles');
}

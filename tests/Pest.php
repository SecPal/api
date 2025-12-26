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
    ->in('Feature', 'Unit');

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
 * Give user an organizational scope for testing.
 * Creates a scope with manage access (min=0, max=255) by default.
 */
function giveOrganizationalScope(
    \App\Models\User $user,
    \App\Models\OrganizationalUnit $organizationalUnit,
    ?int $minViewableRank = 0,
    ?int $maxViewableRank = 255,
    ?int $minAssignableRank = 0,
    ?int $maxAssignableRank = 255,
    bool $allowSelfAccess = true,
    string $accessLevel = 'manage'
): \App\Models\UserInternalOrganizationalScope {
    return \App\Models\UserInternalOrganizationalScope::create([
        'user_id' => $user->id,
        'organizational_unit_id' => $organizationalUnit->id,
        'access_level' => $accessLevel,
        'include_descendants' => true,
        'min_viewable_rank' => $minViewableRank,
        'max_viewable_rank' => $maxViewableRank,
        'min_assignable_rank' => $minAssignableRank,
        'max_assignable_rank' => $maxAssignableRank,
        'allow_self_access' => $allowSelfAccess,
    ]);
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

/**
 * Create Secret with proper encryption pattern.
 * Sets tenant_id BEFORE title_plain to ensure correct encryption context.
 */
function createTestSecret(array $attributes): \App\Models\Secret
{
    $secret = new \App\Models\Secret;
    $secret->tenant_id = $attributes['tenant_id'];
    $secret->owner_id = $attributes['owner_id'];
    $secret->title_plain = $attributes['title_plain'] ?? 'Test Secret';
    $secret->username_plain = $attributes['username_plain'] ?? null;
    $secret->password_plain = $attributes['password_plain'] ?? null;
    $secret->url_plain = $attributes['url_plain'] ?? null;
    $secret->notes_plain = $attributes['notes_plain'] ?? null;
    $secret->tags = $attributes['tags'] ?? null;
    $secret->expires_at = $attributes['expires_at'] ?? null;
    $secret->version = $attributes['version'] ?? 1;
    $secret->save();

    return $secret;
}

/**
 * Create SecretAttachment with proper encryption pattern.
 * Sets tenant_id BEFORE filename_plain to ensure correct encryption context.
 */
function createTestAttachment(array $attributes): \App\Models\SecretAttachment
{
    $attachment = new \App\Models\SecretAttachment;
    $attachment->secret_id = $attributes['secret_id'];
    $attachment->tenant_id = $attributes['tenant_id'];
    $attachment->filename_plain = $attributes['filename_plain'];
    $attachment->file_size = $attributes['file_size'];
    $attachment->mime_type = $attributes['mime_type'];
    $attachment->storage_path = $attributes['storage_path'];
    $attachment->checksum_sha256 = $attributes['checksum_sha256'];
    $attachment->uploaded_by = $attributes['uploaded_by'];
    $attachment->save();

    return $attachment;
}

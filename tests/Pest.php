<?php

// SPDX-FileCopyrightText: 2026 SecPal Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

use Tests\Support\TestKekCounter;

const TEST_KEK_BASE_PATH = 'app/keys';

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
 * Returns a consistent path within a single test, but unique across different tests.
 * This prevents KEK file conflicts when tests run in parallel via Paratest.
 * Centralized helper to avoid duplication across test files.
 */
function getTestKekPath(): string
{
    return storage_path(TEST_KEK_BASE_PATH.'/kek-test-'.getmypid().'-'.TestKekCounter::get().'.key');
}

/**
 * Increment the KEK counter for the next test.
 * Should be called in beforeEach() hooks to ensure each test gets a unique KEK file.
 */
function incrementTestKekCounter(): void
{
    TestKekCounter::increment();
}

/**
 * Clean up the KEK file for the current process.
 */
function cleanupTestKekFile(): void
{
    $kekPath = getTestKekPath();
    if (file_exists($kekPath)) {
        if (! @unlink($kekPath)) {
            throw new RuntimeException(sprintf('Failed to delete KEK file at path "%s".', $kekPath));
        }
    }
}

function spaOrigin(): string
{
    return 'https://app.secpal.dev';
}

function spaReferer(): string
{
    return spaOrigin().'/';
}

/**
 * @param  array<string, string>  $headers
 * @return array<string, string>
 */
function spaHeaders(array $headers = []): array
{
    return array_merge([
        'Origin' => spaOrigin(),
        'Referer' => spaReferer(),
    ], $headers);
}

const SPA_XSRF_COOKIE_NAME = 'XSRF-TOKEN';

function issueSpaCsrfToken(Tests\TestCase $testCase): string
{
    $response = $testCase->withHeaders(spaHeaders())
        ->get('/sanctum/csrf-cookie');

    $xsrfCookie = collect($response->headers->getCookies())
        ->first(fn ($cookie) => $cookie->getName() === SPA_XSRF_COOKIE_NAME);

    if ($xsrfCookie === null) {
        throw new RuntimeException(
            'Unable to issue SPA CSRF cookie: '.SPA_XSRF_COOKIE_NAME.' cookie not found in response from /sanctum/csrf-cookie. '.
            'This usually indicates incorrect SPA headers (Origin/Referer), missing Sanctum/CSRF middleware, or a misconfigured route.'
        );
    }

    return urldecode($xsrfCookie->getValue());
}

/**
 * @return array<string, string>
 */
function spaCsrfHeaders(Tests\TestCase $testCase): array
{
    return spaHeaders([
        'X-XSRF-TOKEN' => issueSpaCsrfToken($testCase),
    ]);
}

/**
 * Get the rate limiter keys used for login attempts for a given email and IP.
 *
 * @return array<int, string>
 */
function getLoginRateLimiterKeys(string $email, string $ip = '127.0.0.1'): array
{
    $normalizedEmail = strtolower(trim($email));

    if ($normalizedEmail === '') {
        return ['login|ip|'.$ip];
    }

    return [
        'login|account|'.$normalizedEmail,
        'login|credential|'.$ip.'|'.$normalizedEmail,
    ];
}

function clearLoginRateLimiter(string $email, string $ip = '127.0.0.1'): void
{
    foreach (getLoginRateLimiterKeys($email, $ip) as $key) {
        Illuminate\Support\Facades\RateLimiter::clear($key);
    }
}

/**
 * Assign permissions to a user with proper tenant context.
 * Sets Spatie Permission team ID, assigns permission, then resets team ID.
 */
function givePermissionWithTenant(App\Models\User $user, int $tenantId, string $permission): void
{
    $registrar = app(Spatie\Permission\PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($tenantId);
    $user->givePermissionTo($permission);
    $registrar->setPermissionsTeamId(null);

    // Clear permission cache to prevent stale data in random-order tests
    $registrar->forgetCachedPermissions();
}

/**
 * Give user an organizational scope for testing.
 * Creates a scope with manage access (min=0, max=255) by default.
 */
function giveOrganizationalScope(
    App\Models\User $user,
    App\Models\OrganizationalUnit $organizationalUnit,
    ?int $minViewableRank = 0,
    ?int $maxViewableRank = 255,
    ?int $minAssignableRank = 0,
    ?int $maxAssignableRank = 255,
    bool $allowSelfAccess = true,
    string $accessLevel = 'manage'
): App\Models\UserInternalOrganizationalScope {
    return App\Models\UserInternalOrganizationalScope::create([
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
    App\Models\User $user,
    Spatie\Permission\Models\Role $role,
    int $tenantId,
    array $attributes = []
): void {
    $now = now();

    Illuminate\Support\Facades\DB::table('model_has_roles')->insert(array_merge([
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

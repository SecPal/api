<!--
SPDX-FileCopyrightText: 2025 SecPal Contributors
SPDX-License-Identifier: CC0-1.0
-->

# Guard Architecture in SecPal

This document explains Laravel's Guard concept, SecPal's architectural decision to use the `sanctum` guard exclusively, and best practices for developers.

## Table of Contents

- [What is a Guard?](#what-is-a-guard)
- [Common Guard Types](#common-guard-types)
- [SecPal's Architecture Decision](#secpals-architecture-decision)
- [Spatie Permission Integration](#spatie-permission-integration)
- [Configuration](#configuration)
- [Developer Guidelines](#developer-guidelines)
- [Migration Context](#migration-context)
- [Troubleshooting](#troubleshooting)

---

## What is a Guard?

In Laravel, a **Guard** is an authentication mechanism that defines **HOW** users are authenticated and identified in your application. Guards abstract the authentication logic and allow you to use different authentication methods for different parts of your application.

**Key Concepts:**

- **Driver**: The underlying mechanism (e.g., session, token, JWT)
- **Provider**: Where user data is retrieved (e.g., database, Eloquent)
- **Stateful vs Stateless**: Whether the guard maintains server-side state

**Example Use Cases:**

- `web` guard: Traditional web apps with server-rendered views (session-based)
- `sanctum` guard: SPAs and mobile apps (token-based)
- `api` guard: Legacy token authentication (token in database)

---

## Common Guard Types

### `web` Guard (Session-Based)

**Characteristics:**

- Session/cookie-based authentication
- Server stores session state
- Cookie contains session ID
- Stateful (server remembers authentication)

**Typical Use Case:**

- Traditional server-rendered web applications
- Multi-page applications (MPAs)
- Applications with server-side templating (Blade, Twig)

**Example:**

```php
// User logs in → session stored on server, cookie sent to browser
Auth::guard('web')->attempt($credentials);

// Subsequent requests → cookie identifies session
if (Auth::guard('web')->check()) {
    // User is authenticated via session
}
```

### `sanctum` Guard (Token-Based)

**Characteristics:**

- Token-based authentication
- Stateless (no server sessions)
- Bearer token in `Authorization` header
- Token stored client-side (localStorage, sessionStorage)

**Typical Use Case:**

- Single-page applications (SPAs) like React, Vue, Angular
- Progressive Web Apps (PWAs)
- Mobile applications (iOS, Android)
- Microservices and API-first architectures

**Example:**

```php
// User logs in → token returned to client
$token = $user->createToken('device-name')->plainTextToken;

// Client stores token and includes in subsequent requests
// Authorization: Bearer {token}

// Laravel automatically authenticates via Sanctum middleware
Route::middleware('auth:sanctum')->group(function () {
    // User is authenticated via Bearer token
});
```

### `api` Guard (Legacy Token)

**Characteristics:**

- Token-based (similar to Sanctum)
- Tokens stored in database (not hashed)
- Legacy approach (predates Sanctum)

**Note:** SecPal does NOT use this guard. Sanctum is the modern replacement.

---

## SecPal's Architecture Decision

**Decision:** SecPal uses the **`sanctum` guard exclusively** for all authentication and permissions.

### Architecture Overview

```text
┌─────────────────────────────────────────────────────────────────┐
│                         SecPal Architecture                      │
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│  Frontend (React PWA)                                            │
│  ├─ Token stored in localStorage                                │
│  ├─ All API requests include: Authorization: Bearer {token}     │
│  └─ Stateless (no cookies)                                      │
│                                                                   │
│  Backend (Laravel API)                                           │
│  ├─ Pure API (no server-rendered views)                         │
│  ├─ All routes protected with auth:sanctum middleware           │
│  ├─ Stateless authentication via Bearer tokens                  │
│  └─ No session storage                                          │
│                                                                   │
│  Database                                                        │
│  ├─ Permissions: guard_name='sanctum'                           │
│  ├─ Roles: guard_name='sanctum'                                 │
│  └─ User model: $guard_name = 'sanctum'                         │
│                                                                   │
└─────────────────────────────────────────────────────────────────┘
```

### Why Sanctum?

1. **API-Only Architecture**: SecPal is a pure API with a separate React frontend
2. **Stateless**: No server-side sessions needed (scales horizontally)
3. **Mobile-Ready**: Same authentication mechanism works for future mobile apps
4. **Modern Best Practice**: Sanctum is Laravel's recommended approach for SPAs
5. **Security**: Tokens are hashed in database, secure token generation

### Why NOT `web` Guard?

- SecPal has **no server-rendered views** (no Blade templates)
- **No cookies** used for authentication (only Bearer tokens)
- **No sessions** stored on server (stateless architecture)
- `web` guard would be semantically incorrect and misleading

**Exception:** The `web` guard is configured but **only** used for Laravel's password reset email verification flow (which is stateless and token-based).

---

## Spatie Permission Integration

SecPal uses [Spatie Laravel-Permission](https://spatie.be/docs/laravel-permission) for role-based access control (RBAC). This package is **guard-aware**, meaning permissions and roles are tied to specific guards.

### Guard-Aware Permissions

When you create a permission or role without specifying `guard_name`, Spatie uses the **default guard** from your User model or config.

**Problem (Before Migration):**

```php
// User authenticated via sanctum guard
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/api/roles', function () {
        $user = auth()->user(); // Authenticated via 'sanctum'

        // Spatie checks for permission with 'web' guard (default)
        if ($user->can('roles.read')) {  // ❌ FAILS!
            // Permission doesn't exist for 'sanctum' guard
        }
    });
});
```

**Error:**

```text
Spatie\Permission\Exceptions\PermissionDoesNotExist:
There is no permission named `roles.read` for guard `sanctum`.
```

**Solution (After Migration):**

```php
// Create permission explicitly for sanctum guard
Permission::create([
    'name' => 'roles.read',
    'guard_name' => 'sanctum',  // ✅ Explicit guard
]);

// Now permission check succeeds
$user->can('roles.read'); // ✅ Works!
```

### How Guard Mismatch Occurs

```text
┌─────────────────────────────────────────────────────────────────┐
│                    Authentication Flow                           │
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│  1. Frontend Request                                             │
│     Authorization: Bearer abc123...                              │
│                                                                   │
│  2. Laravel Middleware: auth:sanctum                             │
│     ✅ Token validated, user authenticated via 'sanctum' guard   │
│                                                                   │
│  3. Controller: $user->can('roles.read')                         │
│                                                                   │
│  4. Spatie Permission Check                                      │
│     - User authenticated with guard: 'sanctum'                   │
│     - Looking for permission 'roles.read' with guard: 'sanctum'  │
│                                                                   │
│  5. Database Query                                               │
│     SELECT * FROM permissions                                    │
│     WHERE name = 'roles.read' AND guard_name = 'sanctum'         │
│                                                                   │
│  6. Result                                                       │
│     ✅ If guard matches: Permission found → Authorized           │
│     ❌ If guard mismatch: No permission → 403 Forbidden          │
│                                                                   │
└─────────────────────────────────────────────────────────────────┘
```

---

## Configuration

### `config/auth.php`

SecPal's authentication configuration explicitly sets `sanctum` as the default guard (as of PR #135):

```php
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | SecPal is an API-only application (React PWA frontend) using stateless
    | token-based authentication via Laravel Sanctum. The default guard is
    | set to 'sanctum' to reflect this architecture.
    |
    | The 'web' guard is kept for Laravel's password reset flow (stateless
    | token-based verification), but is NOT used for actual authentication.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'sanctum'),  // ✅ API-only default
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | SecPal uses Laravel Sanctum for API token authentication. All API routes
    | are protected with the 'sanctum' guard (stateless Bearer tokens).
    |
    | The 'web' guard remains configured for Laravel's password reset email
    | verification flow only. It is NOT used for actual user authentication.
    |
    | Supported drivers: "session", "sanctum"
    |
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],

        'sanctum' => [
            'driver' => 'sanctum',
            'provider' => 'users',
        ],
    ],

    // ... rest of config
];
```

**Key Points:**

- Default guard is `'sanctum'` (aligns with actual architecture)
- `sanctum` guard explicitly configured
- `web` guard exists but only for password reset flow
- Config is self-documenting (comments explain architecture)

### `app/Models/User.php`

The User model explicitly declares its guard:

```php
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasRoles;

    /**
     * The guard name for Spatie Laravel-Permission.
     *
     * SecPal uses token-based authentication via Sanctum,
     * so all permissions and roles use 'sanctum' guard.
     */
    protected $guard_name = 'sanctum';

    // ... rest of model
}
```

**Why This Matters:**

- Ensures all permission checks use `sanctum` guard by default
- Self-documents that User authentication is token-based
- Prevents accidental guard mismatches

### `routes/api.php`

All protected routes explicitly specify the `sanctum` guard:

```php
<?php

// Public routes (no authentication)
Route::post('/auth/token', [AuthController::class, 'generateToken']);
Route::post('/auth/password/reset-request', [AuthController::class, 'passwordResetRequest']);

// Protected routes (require auth:sanctum)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // RBAC endpoints
    Route::post('/users/{user}/roles', [RoleController::class, 'store'])
        ->middleware('permission:role.assign');

    // ... rest of routes
});
```

**Best Practice Note:**

Even though `sanctum` is the default guard, we **explicitly specify** `auth:sanctum` in routes because:

1. **Clarity**: Immediately shows intent (token-based authentication)
2. **Self-Documenting**: Future developers see the authentication mechanism
3. **No Ambiguity**: Prevents accidental fallback to wrong guard
4. **Laravel Best Practice**: Explicit is better than implicit

---

## Developer Guidelines

### Creating Permissions

**✅ ALWAYS specify `guard_name='sanctum'`:**

```php
use Spatie\Permission\Models\Permission;

// Correct: Explicit sanctum guard
Permission::create([
    'name' => 'employees.read',
    'guard_name' => 'sanctum',
]);
```

**❌ NEVER omit `guard_name` (defaults to 'web'):**

```php
// Wrong: Defaults to 'web' guard → will cause 403 errors
Permission::create([
    'name' => 'employees.read',
]);
```

### Creating Roles

**✅ ALWAYS specify `guard_name='sanctum'`:**

```php
use Spatie\Permission\Models\Role;

// Correct: Explicit sanctum guard
$role = Role::create([
    'name' => 'manager',
    'guard_name' => 'sanctum',
]);

// Assign permissions (also with sanctum guard)
$role->givePermissionTo([
    'employees.read',
    'employees.update',
]);
```

### Assigning Roles to Users

```php
use App\Models\User;

$user = User::find(1);

// User model has $guard_name = 'sanctum', so this uses sanctum guard
$user->assignRole('manager');

// Check permission
if ($user->can('employees.read')) {
    // ✅ Permission check uses sanctum guard
}
```

### Testing

**In factories and seeders:**

```php
// Database Seeder
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

Permission::create(['name' => 'employees.read', 'guard_name' => 'sanctum']);
Permission::create(['name' => 'employees.write', 'guard_name' => 'sanctum']);

$role = Role::create(['name' => 'manager', 'guard_name' => 'sanctum']);
$role->givePermissionTo(['employees.read', 'employees.write']);
```

**In tests:**

```php
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

it('allows manager to read employees', function () {
    // Create permission with sanctum guard
    Permission::create(['name' => 'employees.read', 'guard_name' => 'sanctum']);

    // Create role with sanctum guard
    $role = Role::create(['name' => 'manager', 'guard_name' => 'sanctum']);
    $role->givePermissionTo('employees.read');

    // Assign role to user
    $user = User::factory()->create();
    $user->assignRole('manager');

    // Test API endpoint (auth:sanctum middleware)
    $response = $this->actingAs($user)
        ->getJson('/api/v1/employees');

    $response->assertOk();  // ✅ Permission check succeeds
});
```

### Quick Reference

| Context        | Correct                                                            | Wrong                                   |
| -------------- | ------------------------------------------------------------------ | --------------------------------------- |
| **Permission** | `Permission::create(['name' => '...', 'guard_name' => 'sanctum'])` | `Permission::create(['name' => '...'])` |
| **Role**       | `Role::create(['name' => '...', 'guard_name' => 'sanctum'])`       | `Role::create(['name' => '...'])`       |
| **User Model** | `protected $guard_name = 'sanctum';`                               | (omitted)                               |
| **Routes**     | `Route::middleware('auth:sanctum')`                                | `Route::middleware('auth')`             |
| **Config**     | `'guard' => 'sanctum'`                                             | `'guard' => 'web'`                      |

---

## Migration Context

### Historical Background

SecPal's initial implementation (pre-PR #133) had a guard mismatch:

- **Routes**: All used `auth:sanctum` middleware ✅
- **User Model**: No explicit `$guard_name` (defaulted to `'web'`) ❌
- **Permissions**: Created without `guard_name` (defaulted to `'web'`) ❌
- **Config**: Default guard was `'web'` ❌

**Result:** 36 tests failing with 403 Forbidden errors due to guard mismatch.

### EPIC #125: Systematic Migration

A systematic migration was performed to align guards across the entire codebase:

- **PR #133**: Updated test files to use `guard_name='sanctum'`, added `$guard_name` to User model
- **PR #135**: Changed default guard in `config/auth.php` from `'web'` to `'sanctum'`
- **Issue #130** (this document): Documentation of architecture decision

### Benefits of Migration

1. **Semantic Correctness**: Config and code now reflect reality (token-based auth)
2. **Zero Technical Debt**: No confusion for future developers
3. **Self-Documenting**: Architecture is explicit in code and config
4. **Consistent**: All authentication uses `sanctum` guard
5. **Future-Proof**: Correct foundation for expanding RBAC features

---

## Troubleshooting

### Error: "Permission does not exist for guard 'sanctum'"

**Symptom:**

```text
Spatie\Permission\Exceptions\PermissionDoesNotExist:
There is no permission named `roles.read` for guard `sanctum`.
```

**Cause:** Permission was created with `'web'` guard (or default), but user is authenticated via `'sanctum'`.

**Solution:**

```php
// Check existing permissions
use Spatie\Permission\Models\Permission;

Permission::where('name', 'roles.read')->get();
// Result: [{ name: 'roles.read', guard_name: 'web' }]  ❌ Wrong guard!

// Delete wrong permission
Permission::where('name', 'roles.read')
    ->where('guard_name', 'web')
    ->delete();

// Create with correct guard
Permission::create([
    'name' => 'roles.read',
    'guard_name' => 'sanctum',  // ✅ Correct
]);
```

### Error: "User has no permission 'roles.read'"

**Symptom:** 403 Forbidden response, no exception thrown.

**Cause:** User doesn't have the permission, or permission check is using wrong guard.

**Debug Steps:**

```php
// 1. Check which guard user is authenticated with
$user = auth()->user();
dd(auth()->getDefaultDriver());  // Should be 'sanctum'

// 2. Check user's permissions
dd($user->getAllPermissions());  // Shows permissions with guard_name

// 3. Check if user has role
dd($user->roles);  // Shows roles with guard_name

// 4. Check if role has permission
$role = Role::where('name', 'manager')->where('guard_name', 'sanctum')->first();
dd($role->permissions);  // Shows permissions assigned to role
```

### Error: "Call to a member function can() on null"

**Symptom:** Trying to check permission on unauthenticated user.

**Cause:** Route is missing `auth:sanctum` middleware.

**Solution:**

```php
// Wrong: No authentication middleware
Route::get('/api/roles', [RoleController::class, 'index'])
    ->middleware('permission:role.read');  // ❌ Fails: $user is null

// Correct: Add auth:sanctum middleware
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/api/roles', [RoleController::class, 'index'])
        ->middleware('permission:role.read');  // ✅ Works
});
```

---

## References

- **Laravel Guards Documentation**: <https://laravel.com/docs/authentication#guards>
- **Laravel Sanctum Documentation**: <https://laravel.com/docs/sanctum>
- **Spatie Laravel-Permission Documentation**: <https://spatie.be/docs/laravel-permission>
- **EPIC #125**: Migrate Permission System from 'web' to 'sanctum' Guard
- **PR #133**: Test files and User model guard migration
- **PR #135**: Config default guard migration
- **Issue #134**: Set sanctum as default guard in config/auth.php

---

**Last Updated:** 2025-11-09
**Status:** Current (after EPIC #125 migration)
**Maintainer:** SecPal Contributors

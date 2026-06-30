<!--
SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
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
- [Related Documentation](#related-documentation)

---

## What is a Guard?

In Laravel, a **Guard** is an authentication mechanism that defines **HOW** users are authenticated and identified in your application. Guards abstract the authentication logic and allow you to use different authentication methods for different parts of your application.

**Key Concepts:**

- **Driver**: The underlying mechanism (e.g., session, token, JWT)
- **Provider**: Where user data is retrieved (e.g., database, Eloquent)
- **Stateful vs Stateless**: Whether the guard maintains server-side state

**Example Use Cases:**

- `web` guard: Session-based browser authentication
- `sanctum` guard: SPAs, mobile apps, and authenticated API calls

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

### `sanctum` Guard

**Characteristics:**

- Supports Bearer-token authentication for API clients
- Cooperates with Sanctum's stateful SPA mode for first-party browser requests
- Bearer tokens use the `Authorization` header
- Personal access tokens are hashed in storage

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

## SecPal's Architecture Decision

**Decision:** SecPal uses **Laravel Sanctum plus the `web` guard** for authentication:

- first-party browser SPA requests authenticate through Sanctum's stateful mode on top of the `web` guard
- API clients authenticate through Sanctum Bearer tokens
- authorization and API route protection are standardized on `auth:sanctum`

### Architecture Overview

```text
┌─────────────────────────────────────────────────────────────────┐
│                         SecPal Architecture                      │
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│  Frontend (React PWA)                                            │
│  ├─ Browser SPA requests use Sanctum CSRF + session cookies     │
│  ├─ Native/API clients use Authorization: Bearer {token}        │
│  └─ Auth mode depends on the caller context                     │
│                                                                   │
│  Backend (Laravel API)                                           │
│  ├─ API routes protected with auth:sanctum middleware           │
│  ├─ `web` guard backs first-party browser sessions              │
│  ├─ `sanctum` guard backs Bearer-token clients                  │
│  └─ Both modes share the same authenticated API surface         │
│                                                                   │
│  Database                                                        │
│  ├─ Permissions: guard_name='sanctum'                           │
│  ├─ Roles: guard_name='sanctum'                                 │
│  └─ User model: $guard_name = 'sanctum'                         │
│                                                                   │
└─────────────────────────────────────────────────────────────────┘
```

### Why Sanctum?

1. **Unified API surface**: `auth:sanctum` protects both browser and token clients
2. **First-party SPA support**: Sanctum provides CSRF-protected session auth for the browser app
3. **Mobile-ready**: The same backend also supports native and CLI Bearer-token clients
4. **Modern Laravel path**: Sanctum is the intended auth layer for this mixed SPA/API setup
5. **Security**: Personal access tokens are hashed in storage and session auth remains cookie-scoped

### Why the `web` Guard Still Exists

- SecPal does not use the `web` guard for server-rendered app pages, but it does use it for first-party SPA session authentication.
- Sanctum's stateful browser mode depends on the `web` guard and Laravel sessions.
- Password reset and related browser-account flows also rely on the same foundation.

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
There is no permission named `role.read` for guard `sanctum`.
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
        ->getJson('/v1/employees');

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
There is no permission named `role.read` for guard `sanctum`.
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

## Leadership Levels & Hierarchical Access Control

> **Added:** 2025-12-26 (Issue #425, Epic #399 - ADR-009)

SecPal implements hierarchical access control through **Leadership Levels** (Führungsebenen) to restrict which employees users can view and manage based on their organizational responsibilities.

### Architecture Overview

Leadership Levels extend SecPal's three-layer permission model:

1. **Layer 1 - Base Permission**: Required permission (e.g., `employee.read`)
2. **Layer 2 - Organizational Scope**: Which organizational units accessible
3. **Layer 3 - Leadership Rank Filters**: Which leadership levels visible **(NEW)**

### Key Concepts

#### Leadership Levels

Leadership levels represent hierarchical positions within the organization:

- **Rank**: Numeric hierarchy (1 = highest, e.g., CEO)
- **Examples**:
  - FE1: Geschäftsführer (CEO)
  - FE5: Niederlassungsleiter (Branch Director)
  - FE10: Objektleiter (Site Manager)
  - Guards: Employees with `leadership_level_id = NULL`

#### User's Own Leadership Level Has NO Influence

A user's own `leadership_level_id` (if they are an employee) does **NOT** affect what they can do:

- FE5 user with scope `min=1, max=3` can see FE1-FE3 employees (even though FE5 > FE3)
- FE1 user without permission cannot see anyone
- Guard (NULL FE) with scope `min=1, max=255` can see all leadership levels

#### Rank Filter Columns

User organizational scopes contain four rank filter columns:

- **`min_viewable_rank`**: Minimum leadership rank user can view (NULL = no minimum)
- **`max_viewable_rank`**: Maximum leadership rank user can view (NULL/0 = ONLY non-leadership)
- **`min_assignable_rank`**: Minimum leadership rank user can assign/remove (for future use)
- **`max_assignable_rank`**: Maximum leadership rank user can assign/remove (for future use)

#### Critical Semantics

**NULL/0 in `max_viewable_rank` = ONLY non-leadership employees**

This is **NOT** "all employees". To see both leadership and non-leadership employees, users need **TWO scopes**:

```php
// Scope 1: Non-leadership employees (Guards)
UserInternalOrganizationalScope::create([
    'user_id' => $user->id,
    'organizational_unit_id' => $unit->id,
    'min_viewable_rank' => null,
    'max_viewable_rank' => 0, // NULL or 0 = ONLY non-leadership
]);

// Scope 2: Leadership employees (FE1-FE10)
UserInternalOrganizationalScope::create([
    'user_id' => $user->id,
    'organizational_unit_id' => $unit->id,
    'min_viewable_rank' => 1,
    'max_viewable_rank' => 255, // All leadership levels
]);
```

### Real-World Examples

#### Example 1: HR Manager (All Employees)

HR Manager needs to see ALL employees (both leadership and non-leadership) in a branch:

```php
// Scope 1: Guards
UserInternalOrganizationalScope::create([
    'organizational_unit_id' => $branch->id,
    'min_viewable_rank' => null,
    'max_viewable_rank' => 0, // ONLY non-leadership
]);

// Scope 2: All Leadership
UserInternalOrganizationalScope::create([
    'organizational_unit_id' => $branch->id,
    'min_viewable_rank' => 1,
    'max_viewable_rank' => 255, // All leadership (FE1-FE255)
]);
```

#### Example 2: Branch Director (Leadership Only)

Branch Director (FE5) can only see other FE5+ employees (peers and subordinates), NOT Guards:

```php
UserInternalOrganizationalScope::create([
    'organizational_unit_id' => $branch->id,
    'min_viewable_rank' => 5, // FE5 and higher
    'max_viewable_rank' => 255, // All lower ranks
]);
// Guards are NOT visible (no scope with max=0)
```

#### Example 3: Guard Supervisor (Guards Only)

Guard Supervisor can only see Guards (non-leadership employees):

```php
UserInternalOrganizationalScope::create([
    'organizational_unit_id' => $branch->id,
    'min_viewable_rank' => null,
    'max_viewable_rank' => 0, // ONLY non-leadership
]);
// Leadership employees are NOT visible
```

### Implementation Details

#### Employee Policy Integration

Rank filtering is implemented in `EmployeePolicy`:

```php
// app/Policies/EmployeePolicy.php
public function view(User $user, Employee $employee): bool
{
    // ... permission and tenant checks ...

    // Get user's scopes for employee's org unit
    $scopes = $user->organizationalScopes()
        ->where('organizational_unit_id', $employee->organizational_unit_id)
        ->get();

    if ($scopes->isEmpty()) {
        return false; // No scope for this unit
    }

    // Check if employee visible in ANY scope (OR logic)
    $employeeRank = $employee->leadershipLevel?->rank;

    foreach ($scopes as $scope) {
        if ($this->isWithinViewableRankRange($employeeRank, $scope->min_viewable_rank, $scope->max_viewable_rank)) {
            return true; // Visible in at least one scope
        }
    }

    return false; // Not visible in any scope
}
```

#### Rank Range Check Logic

```php
private function isWithinViewableRankRange(?int $employeeRank, ?int $minViewableRank, ?int $maxViewableRank): bool
{
    // Case 1: max = NULL or 0 → ONLY non-leadership employees
    if ($maxViewableRank === null || $maxViewableRank === 0) {
        return $employeeRank === null;
    }

    // Case 2: Employee has NO leadership level (Guard)
    if ($employeeRank === null) {
        return false; // Not visible in leadership-only scope
    }

    // Case 3: Check if employee's rank is within range
    if (! is_null($minViewableRank) && $employeeRank < $minViewableRank) {
        return false; // Below minimum
    }

    if (! is_null($maxViewableRank) && $employeeRank > $maxViewableRank) { // @phpstan-ignore function.impossibleType
        return false; // Above maximum
    }

    return true; // Within range
}
```

#### Query Scope for Rank Filtering

```php
// app/Models/Employee.php
public function scopeWithinRankRange(Builder $query, ?int $minRank, ?int $maxRank): void
{
    // CRITICAL: NULL or 0 in max = ONLY non-leadership employees!
    if ($maxRank === null || $maxRank === 0) {
        $query->whereNull('leadership_level_id');
        return;
    }

    // Show employees within rank range (LEADERSHIP ONLY)
    $query->whereHas('leadershipLevel', function ($q) use ($minRank, $maxRank) {
        if (! is_null($minRank)) {
            $q->where('rank', '>=', $minRank);
        }

        if (! is_null($maxRank) && $maxRank > 0) { // @phpstan-ignore function.impossibleType
            $q->where('rank', '<=', $maxRank);
        }
    });
}
```

### Self-Access Control

**Default Behavior**: Users CANNOT view/edit their own employee HR data

This prevents:

- Self-manipulation of salary
- Self-assignment of leadership levels
- Unauthorized access to sensitive personal data

**Enabling Self-Access**:

Set `allow_self_access = true` in user's organizational scope:

```php
UserInternalOrganizationalScope::create([
    'user_id' => $user->id,
    'organizational_unit_id' => $unit->id,
    'allow_self_access' => true, // Allow viewing/editing own data
]);
```

**Use Cases for `allow_self_access = true`**:

- Employee self-service portals
- Personal document upload
- Contact information updates (limited fields)

**Implementation**:

```php
// app/Policies/EmployeePolicy.php
public function view(User $user, Employee $employee): bool
{
    // Check if viewing own employee record
    if ($user->id === $employee->user_id) {
        // Requires permission AND allow_self_access = true
        if (! $user->can('employee.read')) {
            return false;
        }

        $scope = $user->organizationalScopes()
            ->where('organizational_unit_id', $employee->organizational_unit_id)
            ->where('allow_self_access', true)
            ->first();

        return $scope !== null; // Allow only if allow_self_access = true
    }

    // ... rest of policy logic ...
}
```

### Security Considerations

#### Permission Escalation Prevention

To **remove** a leadership level from an employee, user must have permission to **assign** that level:

- Prevents lower-ranked users from removing higher-ranked leadership levels
- Future implementation: Use `min_assignable_rank` and `max_assignable_rank`

#### Multiple Scopes (OR Logic)

Users can have multiple scopes per organizational unit. Access is granted if **ANY** scope permits:

```php
// User has TWO scopes:
// Scope 1: min=1, max=5
// Scope 2: min=10, max=15

// Employee with FE3: Visible (matches Scope 1)
// Employee with FE12: Visible (matches Scope 2)
// Employee with FE7: NOT visible (no matching scope)
```

This is **additive** and security-safe - multiple scopes expand access, never restrict.

### Testing

Comprehensive tests in `tests/Feature/Policies/EmployeePolicyLeadershipLevelTest.php`:

- **Self-Access Control**: 4 tests
- **User's Own Level Irrelevance**: 3 tests
- **NULL/0 Semantics**: 3 tests
- **Rank Range Filtering**: 2 tests
- **Total**: 12 tests, all passing ✓

### Related Issues

- **Epic #399**: Leadership Levels System (ADR-009)
- **Issue #423**: Database Foundation
- **Issue #424**: Backend API & Policies
- **Issue #425**: Employee Policy Integration **(CURRENT)**
- **Issue #426**: Frontend UI & E2E Tests (pending)

---

## Related Documentation

### Security & Encryption

- **[Encryption Patterns](guides/encryption-patterns.md)** - Field-level encryption, JSON encryption, blind indexes, and key rotation
  - When to use Field-Level Encryption vs JSON Encryption
  - How to implement searchable encrypted fields with blind indexes
  - Security considerations and threat model
  - Key rotation procedures

### Architecture & Design

- **[RBAC Architecture](rbac-architecture.md)** - Role-Based Access Control with temporal roles
- **[Mail System](MAIL_SYSTEM.md)** - Email notification architecture

### References

- **Laravel Guards Documentation**: <https://laravel.com/docs/authentication#guards>
- **Laravel Sanctum Documentation**: <https://laravel.com/docs/sanctum>
- **Spatie Laravel-Permission Documentation**: <https://spatie.be/docs/laravel-permission>
- **EPIC #125**: Migrate Permission System from 'web' to 'sanctum' Guard
- **PR #133**: Test files and User model guard migration
- **PR #135**: Config default guard migration
- **Issue #134**: Set sanctum as default guard in config/auth.php

---

**Last Updated:** 2025-12-08
**Status:** Current (after EPIC #125 migration)
**Maintainer:** SecPal Contributors

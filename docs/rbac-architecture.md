<!-- SPDX-FileCopyrightText: 2025-2026 SecPal Contributors -->
<!-- SPDX-License-Identifier: CC0-1.0 -->

# RBAC Architecture

## Overview

SecPal implements a comprehensive **Role-Based Access Control (RBAC)** system that manages user permissions across the platform. The system is built on four core concepts: **Roles**, **Permissions**, **Direct Permissions**, and **Temporal Assignments**.

**Key Design Philosophy:**

- **Simplicity First:** All roles are equal with unified rules
- **Flexibility:** Support for exceptional access without creating one-off roles
- **Security:** Principle of least privilege with automatic expiration
- **Auditability:** Complete audit trail for all permission changes

This document serves as the **single source of truth** for understanding SecPal's RBAC architecture and guides implementation decisions across all phases.

## System Architecture

### High-Level Component Diagram

```text
┌─────────────────────────────────────────────────────────────────┐
│                          SecPal RBAC System                     │
│                    (Multi-Tenant Architecture)                  │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌──────────┐                                                  │
│  │  Tenant  │──┐                                               │
│  └──────────┘  │                                               │
│        │       │                                               │
│        ▼       │                                               │
│  ┌──────┐     │   ┌─────────┐       ┌─────────────┐           │
│  │ User │─────┴──▶│  Roles  │──────▶│ Permissions │           │
│  └──────┘         └─────────┘       └─────────────┘           │
│      │                 │                    ▲                  │
│      │                 │                    │                  │
│      │             ┌───┴──────────────┐     │                  │
│      │             │ Temporal         │     │                  │
│      │             │ Constraints      │     │                  │
│      │             │ (optional)       │     │                  │
│      │             └──────────────────┘     │                  │
│      │                                      │                  │
│      └──────────────────────────────────────┘                  │
│                 Direct Permissions                              │
│                 (bypass roles)                                  │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘

Permission Resolution:
User Permissions = (Role Permissions ∪ Direct Permissions)
                   WHERE role.valid_from <= NOW() <= role.valid_until
                   AND role.tenant_id = user.tenant_id (tenant-scoped)

Note: All role assignments and permissions are tenant-scoped via Spatie
      Permission's "team" feature (team_id = tenant_id).
```

### Database Schema Overview

```text
tenant_keys                users                    model_has_roles
┌──────────────┐         ┌──────────────┐         ┌──────────────────┐
│ id (PK)      │◀────────│ id           │────┐    │ model_id (FK)    │
│ dek_wrapped  │         │ email        │    └───▶│ role_id (FK)     │
│ idx_wrapped  │         │ name         │         │ team_id (FK)     │◀──┐
└──────────────┘         │ tenant_id(FK)│         │ valid_from       │   │
                         └──────────────┘         │ valid_until      │   │
                                                  │ auto_revoke      │   │
                                                  │ assigned_by (FK) │   │
                                                  │ reason           │   │
                                                  └──────────────────┘   │
                                                                         │
roles                    role_has_permissions                           │
┌──────────────┐         ┌──────────────────┐                          │
│ id (PK)      │◀───┐    │ permission_id (FK)│                         │
│ name         │    └────│ role_id (FK)     │                         │
│ guard_name   │         │ team_id (FK)     │◀────────────────────────┘
│ team_id (FK) │         └──────────────────┘
└──────────────┘         (tenant-scoped)

permissions              model_has_permissions
┌──────────────┐         ┌──────────────────┐
│ id (PK)      │◀───┐    │ permission_id(FK)│
│ name         │    └────│ model_id (FK)    │
│ guard_name   │         │ model_type       │
└──────────────┘         │ team_id (FK)     │◀─────────────┐
                         └──────────────────┘              │
                         Direct Permissions                │
                         (tenant-scoped)                   │
                                                           │
All Spatie Permission relations use team_id = tenant_id   │
for multi-tenant isolation ──────────────────────────────┘
```

## Core Concepts

### 0. Multi-Tenant Context (Foundation)

**NEW in v0.5.0:** SecPal now supports **production-ready multi-tenant architecture** (Epic #357), enabling multiple customers (tenants) to share the same infrastructure with complete data isolation.

#### Tenant-Scoped RBAC

All role assignments and permissions are **tenant-scoped** using Spatie Permission's "team" feature:

```php
// User belongs to a tenant
$user->tenant_id = 1; // Foreign key to tenant_keys table

// When assigning roles, set team ID (tenant ID)
app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id);
$user->assignRole('Manager');

// Role assignment is now scoped to tenant 1
// User with same role in tenant 2 is separate
```

#### How Tenant Isolation Works

**1. User → Tenant Relationship:**

- Every user belongs to **exactly ONE tenant** (`users.tenant_id` FK)
- Foreign key constraint enforces referential integrity
- Cascade delete: Deleting tenant deletes all its users

**2. Middleware Injection:**

- `InjectTenantId` middleware resolves `tenant_id` from authenticated user
- Sets `$request->tenant_id` and Spatie Permission team ID
- All controllers use this `tenant_id` for data queries

**3. Spatie Permission Team Integration:**

```php
// In middleware (app/Http/Middleware/InjectTenantId.php)
app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id);

// Role assignments are now scoped to user's tenant
// User1 (tenant 1) with role "Manager" ≠ User2 (tenant 2) with role "Manager"
```

**4. Database-Level Isolation:**

- All models have `tenant_id` column with foreign key constraint
- Policies enforce `tenant_id` boundaries
- Cross-tenant access returns 404 (resource not found in user's tenant)

#### Role Assignment Example (Tenant-Scoped)

```php
// Create two tenants
$tenant1 = TenantKey::find(1);
$tenant2 = TenantKey::find(2);

// Create users in different tenants
$user1 = User::create(['email' => 'manager@tenant1.com', 'tenant_id' => 1]);
$user2 = User::create(['email' => 'manager@tenant2.com', 'tenant_id' => 2]);

// Assign same role "Manager" to both users (but in different tenants)
app(PermissionRegistrar::class)->setPermissionsTeamId(1); // Tenant 1
$user1->assignRole('Manager');

app(PermissionRegistrar::class)->setPermissionsTeamId(2); // Tenant 2
$user2->assignRole('Manager');

// Result: User1 is Manager in Tenant1, User2 is Manager in Tenant2
// These are SEPARATE role assignments (different team_id in pivot table)
```

#### Permission Checking (Tenant-Aware)

```php
// In controller or policy
$user = $request->user(); // User from Tenant 1

// Middleware already set team ID to user's tenant
app(PermissionRegistrar::class)->getPermissionsTeamId(); // Returns 1

// Permission checks automatically scoped to user's tenant
$user->can('employees.read'); // Checks permissions in Tenant 1 only

// Accessing data from another tenant returns 404
$employee = Employee::where('tenant_id', 2)->first(); // Tenant 2 employee
$this->authorize('view', $employee); // Throws NotFoundHttpException (404)
```

#### Security Guarantees

✅ **User cannot spoof tenant_id:** Middleware removes client-provided values (PR #356)
✅ **User cannot access other tenant's data:** Policies enforce tenant boundaries
✅ **Role assignments are tenant-isolated:** Spatie Permission team feature
✅ **Database-enforced isolation:** Foreign key constraints prevent invalid tenant_id

For detailed multi-tenant architecture documentation, see:

- [ADR-008: User-Based Tenant Resolution](https://github.com/SecPal/.github/blob/main/docs/adr/20251219-user-based-tenant-resolution.md)
- [Multi-Tenant Deployment Guide](/docs/guides/multi-tenant-deployment.md)
- [Tenant Provisioning Guide](/docs/guides/tenant-provisioning.md)

---

### 1. Roles

#### What Are Roles?

Roles are **named collections of permissions** that define what a user can do within SecPal. Instead of assigning permissions individually to each user, roles group related permissions together for easier management.

#### Predefined Roles

SecPal seeds seven predefined roles that cover common use cases. There is no predefined `Admin` role; broad access is assembled from explicit permissions plus explicit organizational scopes.

| Role                   | Description                   | Typical Permissions                                                                                            | Scope                           |
| ---------------------- | ----------------------------- | -------------------------------------------------------------------------------------------------------------- | ------------------------------- |
| **Employee**           | Self-service employee access  | `employee.read`, `employee.update`, `shifts.read`, `work_instructions.read`                                    | Own data only                   |
| **Employee Read Only** | Read-only self-service access | `employee.read`, `shifts.read`, `work_instructions.read`                                                       | Own data only                   |
| **HR**                 | HR lifecycle operations       | `employees.read`, `employees.create`, `employees.read_sensitive`, `onboarding.approve`                         | Explicit organizational scopes  |
| **Manager**            | Operational management        | `customers.read`, `customers.update`, `sites.read`, `shifts.read`, `work_instructions.publish`                 | Explicit organizational scopes  |
| **Guard**              | Security personnel            | `employee.read`, `shifts.read`, `shifts.update`, `work_instructions.read`                                      | Own data and assigned records   |
| **Client**             | External stakeholder access   | `shifts.read`, `work_instructions.read`, `reports.view`                                                        | Customer/site scoped            |
| **Works Council**      | Employee representation       | `employees.read`, `employees.read_all_branches`, `shifts.approve_as_br`, `works_council.access_employee_files` | Approval workflows within scope |

#### All Roles Are Equal

**Design Decision:** SecPal does **not** distinguish between "system roles" and "custom roles". All roles follow the same rules:

- ✅ **Deletion Rule:** Any role can be deleted **if not assigned to users**
- ✅ **Modification:** Any role can be renamed or have permissions changed
- ✅ **Seeder Idempotency:** Predefined roles are recreated by the seeder if deleted

**Why this approach?**

- **Simplicity:** One unified rule for all roles
- **Flexibility:** No artificial restrictions on role management
- **Recovery:** Deleted predefined roles are automatically recreated on next seeder run
- **No Confusion:** No need to explain "system vs custom" distinction to users

See [ADR-005: RBAC Design Decisions](https://github.com/SecPal/.github/blob/main/docs/adr/20251111-rbac-design-decisions.md#decision-1-no-system-roles---all-roles-equal) for detailed rationale.

#### Role Assignment

Roles can be assigned to users in two ways:

**Permanent Assignment (Default):**

```php
// No expiration - role stays until manually revoked
$user->assignRole('manager');
```

**Temporal Assignment (Optional):**

```php
// Role expires automatically after 2 weeks
$user->assignRole('manager', [
    'valid_from' => now(),
    'valid_until' => now()->addWeeks(2),
    'auto_revoke' => true,
    'reason' => 'Vacation coverage for Manager A',
]);
```

See [Temporal Assignments](#4-temporal-assignments) section for detailed use cases.

### 2. Permissions

#### Permission Naming Convention

All permissions follow the `resource.action` naming pattern:

```text
Format: resource.action

Examples:
- employees.read
- employees.create
- employees.update
- employees.delete
- employees.read_salary    (special: restricted data)
- employees.export         (special: data export)
- shifts.publish           (special: workflow action)
- shifts.approve_as_br     (special: role-specific)
```

**Resources:**

- `employees` - Employee management
- `shifts` - Shift planning
- `work_instructions` - Work instructions (Dienstanweisungen)
- `roles` - Role management (Phase 4)
- `permissions` - Permission management (Phase 4)
- `works_council` - Works council specific features
- `reports` - Report generation and export

**Common Actions:**

- `read` - View resource
- `create` - Create new resource
- `update` - Modify existing resource
- `delete` - Remove resource
- `export` - Export data

**Special Actions:**

- `read_salary` - View sensitive salary data
- `read_all_branches` - Cross-branch access when granted explicitly alongside suitable scopes
- `publish` - Publish/activate resource
- `approve_as_br` - Works council approval action
- `assign_temporary` - Assign temporal roles

#### Permission Grouping

Permissions are grouped by resource in the database and UI:

```json
{
  "employees": [
    "employees.read",
    "employees.create",
    "employees.update",
    "employees.delete",
    "employees.read_salary",
    "employees.export"
  ],
  "shifts": [
    "shifts.read",
    "shifts.create",
    "shifts.update",
    "shifts.delete",
    "shifts.publish",
    "shifts.approve_as_br"
  ]
}
```

#### Permission Assignment to Roles

Permissions are assigned to roles during seeder or via API:

```php
// Seeder: Predefined role permissions
$manager = Role::firstOrCreate(['name' => 'Manager']);
$manager->syncPermissions([
    'employees.read',
    'employees.create',
    'employees.update',
    'shifts.read',
    'shifts.create',
    'shifts.update',
    'work_instructions.read',
]);

// API: Dynamic role permission changes (Phase 4)
POST /v1/roles/{id}/permissions
{
  "permissions": ["employees.export", "reports.generate"]
}
```

#### Permission Checking at Runtime

Use Laravel's built-in authorization:

```php
// In controller
$this->authorize('update', $employee);

// In policy
public function update(User $user, Employee $employee): bool
{
    return $user->can('employees.update')
        && $user->branch_id === $employee->branch_id;
}

// In blade (future)
@can('employees.update', $employee)
    <button>Edit</button>
@endcan

// In code
if ($user->hasPermissionTo('employees.read_salary')) {
    // Show salary field
}
```

### 3. Direct Permissions

#### Definition

**Direct Permissions** are permissions assigned **directly to users**, bypassing the role system. They allow for exceptional access cases without needing to create custom roles or modify existing roles.

#### Permission Inheritance Formula

```text
User Permissions = Role Permissions ∪ Direct Permissions

Example:
User "John" has role "Manager":
  - Role "Manager" grants: [employees.read, employees.update, shifts.read]
  - Direct permissions: [employees.export, reports.generate]
  - Total permissions: [employees.read, employees.update, shifts.read,
                        employees.export, reports.generate]

If "Manager" role is removed:
  - Direct permissions remain: [employees.export, reports.generate]
```

#### When to Use Direct Permissions

Use direct permissions for **exceptional access** that doesn't fit standard role patterns:

| Scenario                            | Solution                                         | Why Direct Permission?                           |
| ----------------------------------- | ------------------------------------------------ | ------------------------------------------------ |
| Guard needs temporary export access | Assign `employees.export` for 1 week             | Don't want to modify Guard role for one-off case |
| Manager should NOT delete employees | Revoke `employees.delete` directly               | Override role permission for specific user       |
| Client needs special report access  | Assign `reports.generate`                        | Exceptional permission for VIP client            |
| Developer debugging production      | Assign `employees.read_all_branches` for 2 hours | Time-limited elevated access                     |
| Auditor needs read access           | Assign multiple read permissions temporarily     | External access without creating "Auditor" role  |

#### When NOT to Use Direct Permissions

**Don't use direct permissions when:**

- ❌ Multiple users need the same access → Create a new role
- ❌ Standard access pattern → Use existing role
- ❌ Long-term access → Assign a role instead
- ❌ Organization-wide permission change → Modify the role

#### Code Examples

**Assign Direct Permission (Permanent):**

```php
POST /v1/users/123/permissions
{
  "permissions": ["employees.export", "reports.generate"]
}

// Response:
{
  "data": {
    "user_id": 123,
    "direct_permissions": [
      {"name": "employees.export", "assigned_at": "2025-11-11T10:00:00Z"},
      {"name": "reports.generate", "assigned_at": "2025-11-11T10:00:00Z"}
    ]
  }
}
```

**Assign Direct Permission (Temporal):**

```php
POST /v1/users/123/permissions
{
  "permissions": ["reports.generate"],
  "valid_from": "2025-11-01T00:00:00Z",
  "valid_until": "2025-11-30T23:59:59Z"
}

// Permission expires automatically after November 2025
```

**View User's Combined Permissions:**

```php
GET /v1/users/123/permissions

// Response shows three categories:
{
  "data": {
    "via_roles": [
      {"name": "employees.read", "role": "Manager"},
      {"name": "shifts.read", "role": "Manager"}
    ],
    "direct": [
      {"name": "employees.export", "valid_until": null},
      {"name": "reports.generate", "valid_until": "2025-11-30T23:59:59Z"}
    ],
    "all": [
      "employees.read",
      "shifts.read",
      "employees.export",
      "reports.generate"
    ]
  }
}
```

**Revoke Direct Permission:**

```php
DELETE /v1/users/123/permissions/employees.export

// ✅ Removes direct permission only
// ℹ️ Role-based permissions remain unchanged
```

See [ADR-005: Direct Permissions](https://github.com/SecPal/.github/blob/main/docs/adr/20251111-rbac-design-decisions.md#decision-2-direct-permissions-independent-of-roles) for design rationale.

### 4. Temporal Assignments

#### Definition

**Temporal Assignments** are time-limited role or permission assignments that **automatically expire** after a specified date/time. They enable the **principle of least privilege** by ensuring elevated access is removed when no longer needed.

#### Optional Nature: Permanent is Default

**IMPORTANT:** Temporal assignments are **optional**. By default, all role and permission assignments are **permanent** until manually revoked.

```text
Default Behavior:
┌─────────────────┐
│ Assign Role     │──▶ Permanent (no expiration)
└─────────────────┘

With Temporal Constraints (opt-in):
┌─────────────────┐
│ Assign Role     │──▶ Expires automatically at valid_until
│ + valid_until   │
└─────────────────┘
```

**When to use temporal:**

- ✅ Vacation coverage (1-2 weeks)
- ✅ Project-based access (weeks to months)
- ✅ Event-based elevation (hours to days)
- ✅ Compliance/debugging (minutes to hours)

**When to use permanent:**

- ✅ Standard employee role assignments
- ✅ Long-term contractors
- ✅ Stable team structure

#### Use Cases

##### Use Case 1: Vacation Coverage

```text
Scenario:
Manager A on vacation (2025-12-01 to 2025-12-14)
Manager B needs temporary Manager permissions

Solution:
POST /v1/users/{manager_b_id}/roles
{
  "role": "manager",
  "valid_from": "2025-12-01T00:00:00Z",
  "valid_until": "2025-12-14T23:59:59Z",
  "auto_revoke": true,
  "reason": "Vacation coverage for Manager A"
}

Result:
✅ Manager B gets permissions on Dec 1
✅ Permissions auto-revoke on Dec 14
✅ Audit trail logs assignment and expiration
✅ Manager A's permissions remain unchanged
```

##### Use Case 2: Project-Based Access

```text
Scenario:
External consultant needs read access for 3-month project

Solution:
POST /v1/users/{consultant_id}/roles
{
  "role": "client",  // Or custom "consultant" role
  "valid_from": "2025-11-01T00:00:00Z",
  "valid_until": "2026-02-01T23:59:59Z",
  "auto_revoke": true,
  "reason": "Project XYZ consultant access"
}

Result:
✅ Consultant gets access on Nov 1
✅ Access expires automatically on Feb 1
✅ No manual cleanup needed
```

##### Use Case 3: Event-Based Elevation

```text
Scenario:
Guard becomes "Team Lead" during large event (18:00-06:00)

Solution:
POST /v1/users/{guard_id}/permissions
{
  "permissions": ["shifts.update", "work_instructions.publish"],
  "valid_from": "2025-11-15T18:00:00Z",
  "valid_until": "2025-11-16T06:00:00Z",
  "reason": "Team Lead for Stadium Event"
}

Result:
✅ Elevated permissions during event only
✅ Auto-revoke after event ends
✅ Guard returns to normal permissions
```

##### Use Case 4: Compliance (Debugging)

```text
Scenario:
Developer needs production access for critical hotfix

Solution:
POST /v1/users/{developer_id}/roles
{
  "role": "incident_responder",
  "valid_from": "2025-11-11T14:00:00Z",
  "valid_until": "2025-11-11T16:00:00Z",  // 2 hours
  "auto_revoke": true,
  "reason": "Hotfix: Issue #234 - Payment Gateway Down"
}

Result:
✅ Developer gets the temporary incident-response permission bundle immediately
✅ Access expires after 2 hours
✅ Audit trail proves least-privilege compliance
✅ No manual revocation needed
```

#### Automatic Expiration Handling

**Scheduled Command:**

```bash
# Runs every minute (configured in routes/console.php)
php artisan roles:expire

# What it does:
# 1. Finds all role assignments where valid_until < NOW()
# 2. Deletes assignments with auto_revoke = true
# 3. Logs expiration to audit trail
# 4. Processes in batches for performance
```

**Expiration Logic:**

```php
// Eloquent scope: active()
public function scopeActive($query)
{
    return $query->where(function ($q) {
        $q->whereNull('valid_from')
          ->orWhere('valid_from', '<=', now());
    })->where(function ($q) {
        $q->whereNull('valid_until')
          ->orWhere('valid_until', '>', now());
    });
}

// Usage: Check if user has role (only active roles count)
$user->hasRole('manager');  // Returns true only if role is active
```

#### Decision Matrix: Temporal vs Permanent

| Access Type         | Duration              | Recommended   | Example                        |
| ------------------- | --------------------- | ------------- | ------------------------------ |
| Permanent employee  | Indefinite            | **Permanent** | Manager assigned to branch     |
| Vacation coverage   | 1-2 weeks             | **Temporal**  | Acting manager during absence  |
| Project access      | Weeks to months       | **Temporal**  | External consultant on project |
| Event coverage      | Hours to days         | **Temporal**  | Team lead during event         |
| Emergency debugging | Minutes to hours      | **Temporal**  | Developer production access    |
| Standard role       | Until employment ends | **Permanent** | Guard, Client, Manager         |

See [ADR-005: Temporal Assignments](https://github.com/SecPal/.github/blob/main/docs/adr/20251111-rbac-design-decisions.md#decision-3-temporal-assignments-are-optional) for design rationale.

## Design Principles

SecPal's RBAC system is built on three core design decisions. Each decision prioritizes simplicity, flexibility, and security over complex abstraction layers.

### 1. No System Roles - All Roles Equal

**Principle:** Do not implement an `is_system_role` flag or similar protection mechanism. All roles follow identical rules for modification and deletion.

**Unified Deletion Rule:**

```php
use Illuminate\Validation\ValidationException;

// Single rule applies to ALL roles (Employee, HR, Manager, Guard, custom, etc.)
public function destroy(Role $role)
{
    if ($role->users()->count() > 0) {
        throw ValidationException::withMessages([
            'role' => 'Cannot delete role while assigned to users'
        ]);
    }

    $role->delete();  // ✅ Works for ANY role
}
```

**How Predefined Roles Are Protected:**

```php
// Seeder is idempotent - recreates deleted roles
class RolesAndPermissionsSeeder extends Seeder
{
    public function run()
    {
        // firstOrCreate = creates if not exists, skips if exists
        $manager = Role::firstOrCreate(
            ['name' => 'Manager', 'guard_name' => 'sanctum'],
            ['description' => 'Operational management within assigned scopes']
        );

        // Sync permissions only if role has none
        if ($manager->permissions()->count() === 0) {
            $manager->syncPermissions([
                'customers.read',
                'customers.create',
                'sites.read',
                'employees.read',
                'shifts.read',
                'shifts.publish',
            ]);
        }
    }
}
```

**Benefits:**

- ✅ **Simplicity:** One deletion rule for all roles
- ✅ **Flexibility:** Everything manageable via UI/API
- ✅ **Recovery:** Deleted predefined roles recreated automatically
- ✅ **No Confusion:** No "system vs custom" distinction to explain

**See:** [ADR-005 Decision 1](https://github.com/SecPal/.github/blob/main/docs/adr/20251111-rbac-design-decisions.md#decision-1-no-system-roles---all-roles-equal)

### 2. Direct Permissions Independent of Roles

**Principle:** Users can have permissions assigned directly, independent of their roles. This enables exceptional access without creating one-off roles or modifying existing roles.

**Permission Resolution Formula:**

```text
User Permissions = Role Permissions ∪ Direct Permissions

WHERE:
- Role Permissions = All permissions from all assigned active roles
- Direct Permissions = Permissions assigned directly to the user
- ∪ = Union (combined, deduplicated)
```

**Example Scenario:**

```php
// User "John" has role "Manager"
$john->roles()->pluck('name');  // ["Manager"]

// Manager role grants these permissions:
$managerRole->permissions()->pluck('name');
// ["employees.read", "employees.update", "shifts.read"]

// John gets ADDITIONAL direct permission:
$john->givePermissionTo('employees.export');

// John's TOTAL permissions:
$john->getAllPermissions()->pluck('name');
// ["employees.read", "employees.update", "shifts.read", "employees.export"]

// If Manager role is removed:
$john->removeRole('Manager');
$john->getAllPermissions()->pluck('name');
// ["employees.export"]  ← Direct permission remains!
```

**Benefits:**

- ✅ **Flexibility:** Handle edge cases without role proliferation
- ✅ **Simplicity:** No need for "temporary roles" or role modifications
- ✅ **Auditability:** Clear distinction between role-based and direct permissions
- ✅ **Independence:** Direct permissions survive role changes

**See:** [ADR-005 Decision 2](https://github.com/SecPal/.github/blob/main/docs/adr/20251111-rbac-design-decisions.md#decision-2-direct-permissions-independent-of-roles)

### 3. Temporal Assignments Are Optional

**Principle:** All role and permission assignments are **permanent by default**. Temporal constraints (`valid_from`, `valid_until`) are **optional** and used only when time-limited access is explicitly needed.

**Default Behavior:**

```php
// Permanent assignment (no expiration)
$user->assignRole('manager');

// Temporal assignment (explicit opt-in)
$user->assignRole('manager', [
    'valid_from' => now(),
    'valid_until' => now()->addWeeks(2),
]);
```

**Design Rationale:**

- 🎯 **80%+ of assignments are permanent** (standard employee roles)
- 🎯 **Temporal is for exceptions** (vacation, projects, events, debugging)
- 🎯 **Simplicity:** Don't force users to set expiration dates unnecessarily
- 🎯 **Flexibility:** Temporal available when needed without overhead

**When Temporal is Used:**

| Use Case          | Duration          | Expiration Needed?   |
| ----------------- | ----------------- | -------------------- |
| Standard employee | Until termination | ❌ No - permanent    |
| Vacation coverage | 1-2 weeks         | ✅ Yes - auto-revoke |
| Project access    | Weeks to months   | ✅ Yes - auto-revoke |
| Event elevation   | Hours to days     | ✅ Yes - auto-revoke |
| Debugging access  | Minutes to hours  | ✅ Yes - auto-revoke |

**See:** [ADR-005 Decision 3](https://github.com/SecPal/.github/blob/main/docs/adr/20251111-rbac-design-decisions.md#decision-3-temporal-assignments-are-optional)

## Permission Hierarchy

### Visual Representation

```text
┌───────────────────────────────────────────────────────────┐
│                      User Permissions                     │
│                                                           │
│  ┌─────────────────────────┐  ┌──────────────────────┐  │
│  │   Role Permissions      │  │ Direct Permissions   │  │
│  │                         │  │                      │  │
│  │  ┌────────────┐         │  │  ┌──────────────┐  │  │
│  │  │ Role:      │         │  │  │ Assigned     │  │  │
│  │  │ Manager    │         │  │  │ Directly     │  │  │
│  │  └────────────┘         │  │  └──────────────┘  │  │
│  │         │               │  │         │          │  │
│  │         ▼               │  │         ▼          │  │
│  │  employees.read         │  │  employees.export  │  │
│  │  employees.update       │  │  reports.generate  │  │
│  │  shifts.read            │  │                    │  │
│  └─────────────────────────┘  └──────────────────────┘  │
│               │                          │               │
│               └──────────┬───────────────┘               │
│                          ▼                               │
│              ┌────────────────────┐                      │
│              │   Union (∪)        │                      │
│              │   Deduplicated     │                      │
│              └────────────────────┘                      │
│                          │                               │
│                          ▼                               │
│        ┌─────────────────────────────────┐              │
│        │ Total Permissions:              │              │
│        │ - employees.read                │              │
│        │ - employees.update              │              │
│        │ - shifts.read                   │              │
│        │ - employees.export              │              │
│        │ - reports.generate              │              │
│        └─────────────────────────────────┘              │
└───────────────────────────────────────────────────────────┘
```

### Permission Inheritance Examples

#### Example 1: User with Only Role Permissions

```php
User: John
Role: Manager
Direct Permissions: None

Result:
✅ employees.read    (from Manager role)
✅ employees.update  (from Manager role)
✅ shifts.read       (from Manager role)
```

#### Example 2: User with Only Direct Permissions

```php
User: Sarah
Role: None
Direct Permissions: employees.export, reports.generate

Result:
✅ employees.export   (direct)
✅ reports.generate   (direct)
```

#### Example 3: User with Both (Union)

```php
User: Alex
Role: Manager
Direct Permissions: employees.export, reports.generate

Result:
✅ employees.read     (from Manager)
✅ employees.update   (from Manager)
✅ shifts.read        (from Manager)
✅ employees.export   (direct)
✅ reports.generate   (direct)
```

#### Example 4: User Loses Role but Keeps Direct Permissions

```php
Initial State:
User: Mike
Role: Manager
Direct Permissions: employees.export

Permissions: [employees.read, employees.update, shifts.read, employees.export]

After Role Removal:
$mike->removeRole('Manager');

Permissions: [employees.export]  ← Direct permission remains!
```

## Implementation Patterns

### Assigning Permanent Role

**API Endpoint:**

```http
POST /v1/users/{id}/roles
Content-Type: application/json

{
  "role": "manager"
}
```

**Code:**

```php
// In controller
public function store(User $user, AssignRoleRequest $request): JsonResponse
{
    $validated = $request->validated();

    $user->assignRole($validated['role']);

    return response()->json([
        'data' => [
            'user_id' => $user->id,
            'role' => $validated['role'],
            'assigned_at' => now()->toIso8601String(),
        ],
    ], 201);
}
```

**Result:**

```json
{
  "data": {
    "user_id": 123,
    "role": "manager",
    "assigned_at": "2025-11-11T10:00:00Z"
  }
}
```

### Assigning Temporal Role

**API Endpoint:**

```http
POST /v1/users/{id}/roles
Content-Type: application/json

{
  "role": "manager",
  "valid_from": "2025-12-01T00:00:00Z",
  "valid_until": "2025-12-14T23:59:59Z",
  "reason": "Vacation coverage for Manager A"
}
```

**Code:**

```php
// In controller
public function store(User $user, AssignRoleRequest $request): JsonResponse
{
    $validated = $request->validated();

    $user->assignRole($validated['role'], [
        'valid_from' => $validated['valid_from'],
        'valid_until' => $validated['valid_until'],
        'auto_revoke' => true,
        'assigned_by' => auth()->id(),
        'reason' => $validated['reason'],
    ]);

    return response()->json([
        'data' => [
            'user_id' => $user->id,
            'role' => $validated['role'],
            'valid_from' => $validated['valid_from'],
            'valid_until' => $validated['valid_until'],
            'expires_in_days' => now()->diffInDays($validated['valid_until']),
        ],
    ], 201);
}
```

**Result:**

```json
{
  "data": {
    "user_id": 123,
    "role": "manager",
    "valid_from": "2025-12-01T00:00:00Z",
    "valid_until": "2025-12-14T23:59:59Z",
    "expires_in_days": 13
  }
}
```

### Assigning Direct Permission

**API Endpoint:**

```http
POST /v1/users/{id}/permissions
Content-Type: application/json

{
  "permissions": ["employees.export", "reports.generate"]
}
```

**Code:**

```php
// In controller
public function store(User $user, AssignPermissionRequest $request): JsonResponse
{
    $validated = $request->validated();

    foreach ($validated['permissions'] as $permission) {
        $user->givePermissionTo($permission);
    }

    return response()->json([
        'data' => [
            'user_id' => $user->id,
            'direct_permissions' => $user->getDirectPermissions()->pluck('name'),
            'assigned_at' => now()->toIso8601String(),
        ],
    ], 201);
}
```

**Result:**

```json
{
  "data": {
    "user_id": 123,
    "direct_permissions": ["employees.export", "reports.generate"],
    "assigned_at": "2025-11-11T10:00:00Z"
  }
}
```

### Checking Permissions

**In Controllers:**

```php
public function update(UpdateEmployeeRequest $request, Employee $employee)
{
    // Option 1: Using authorize() - throws 403 if fails
    $this->authorize('update', $employee);

    // Option 2: Using Gate - manual check
    if (! Gate::allows('update', $employee)) {
        return response()->json(['error' => 'Forbidden'], 403);
    }

    // Option 3: Direct permission check
    if (! auth()->user()->can('employees.update')) {
        abort(403);
    }

    // Update logic...
}
```

**In Policies:**

```php
// EmployeePolicy.php
public function update(User $user, Employee $employee): bool
{
    // Check permission exists
    if (! $user->hasPermissionTo('employees.update')) {
        return false;
    }

    // Check scope (branch-level access)
    if ($user->branch_id !== $employee->branch_id) {
        // Users with the explicit cross-branch permission can access all branches
        return $user->hasPermissionTo('employees.read_all_branches');
    }

    return true;
}
```

**In Middleware:**

```php
// In routes/api.php
Route::middleware(['auth:sanctum', 'permission:employees.read'])
    ->get('/employees', [EmployeeController::class, 'index']);

// Multiple permissions (any)
Route::middleware(['auth:sanctum', 'permission:employees.update|employees.delete'])
    ->patch('/employees/{employee}', [EmployeeController::class, 'update']);
```

**Direct Permission Hierarchy Check:**

```php
// Get all permissions (role + direct)
$allPermissions = auth()->user()->getAllPermissions();

// Check if user has permission (checks both role and direct)
if (auth()->user()->can('employees.export')) {
    // Show export button
}

// Get permissions via roles only
$rolePermissions = auth()->user()->getPermissionsViaRoles();

// Get direct permissions only
$directPermissions = auth()->user()->getDirectPermissions();
```

### Handling Expiration

**Scheduled Command:**

```php
// Console/Commands/ExpireRoles.php
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\RoleAssignmentLog;

class ExpireRoles extends Command
{
    protected $signature = 'roles:expire';
    protected $description = 'Revoke expired role assignments';

    public function handle(): int
    {
        $expired = DB::table('model_has_roles')
            ->where('valid_until', '<', now())
            ->where('auto_revoke', true)
            ->get();

        foreach ($expired as $assignment) {
            DB::transaction(function () use ($assignment) {
                // 1. Delete assignment
                DB::table('model_has_roles')
                    ->where('model_id', $assignment->model_id)
                    ->where('role_id', $assignment->role_id)
                    ->delete();

                // 2. Log to audit trail
                RoleAssignmentLog::create([
                    'user_id' => $assignment->model_id,
                    'role_id' => $assignment->role_id,
                    'action' => 'expired',
                    'valid_from' => $assignment->valid_from,
                    'valid_until' => $assignment->valid_until,
                ]);
            });
        }

        $this->info("Expired {$expired->count()} role assignments");
        return 0;
    }
}
```

**Scheduled in `routes/console.php`:**

```php
Schedule::command('roles:expire')->everyMinute();
```

**Active Role Filtering:**

```php
// Eloquent scope (automatically applied)
public function scopeActive($query)
{
    return $query->where(function ($q) {
        $q->whereNull('valid_from')
          ->orWhere('valid_from', '<=', now());
    })->where(function ($q) {
        $q->whereNull('valid_until')
          ->orWhere('valid_until', '>', now());
    });
}

// Usage: Automatically filters expired roles
$user->roles;  // Only returns active roles
$user->hasRole('manager');  // Only checks active roles
```

## API Overview

SecPal's RBAC API is split across four functional areas:

| API Area                  | Endpoints | Status              | Documentation           |
| ------------------------- | --------- | ------------------- | ----------------------- |
| **Role Assignment**       | 4         | ✅ Phase 3 complete | Issue #5                |
| **Role Management**       | 5         | ✅ Phase 4 shipped  | Issue #137 / Issue #140 |
| **Permission Management** | 5         | ✅ Phase 4 shipped  | Issue #138 / Issue #140 |
| **Direct Permissions**    | 4         | ✅ Phase 4 shipped  | Issue #139 / Issue #140 |

### Role Assignment API (Phase 3)

| Method   | Endpoint                             | Description                            |
| -------- | ------------------------------------ | -------------------------------------- |
| `POST`   | `/v1/users/{id}/roles`               | Assign role (permanent or temporal)    |
| `GET`    | `/v1/users/{id}/roles`               | List user's roles with expiration info |
| `DELETE` | `/v1/users/{id}/roles/{role}`        | Revoke role from user                  |
| `PATCH`  | `/v1/users/{id}/roles/{role}/extend` | Extend role expiration date            |

**Authorization:** Requires the corresponding role-assignment permission (`role.assign`, `role.read`, `role.revoke`); extending an existing assignment currently reuses `role.assign`

**Documentation:** See Issue #5 and ADR-004 for implementation details

### Role Management API (Phase 4)

| Method   | Endpoint         | Description                                   |
| -------- | ---------------- | --------------------------------------------- |
| `GET`    | `/v1/roles`      | List all roles (system + custom)              |
| `POST`   | `/v1/roles`      | Create custom role (optional permission list) |
| `GET`    | `/v1/roles/{id}` | Get role details with permissions             |
| `PATCH`  | `/v1/roles/{id}` | Update role name and/or sync permissions      |
| `DELETE` | `/v1/roles/{id}` | Delete role (if not assigned)                 |

Role permission membership is managed through create/update payloads (`permissions` array) and `PATCH /v1/roles/{id}` — there are no separate `/v1/roles/{id}/permissions` routes in `routes/api.php`.

**Authorization:** Requires the corresponding role-management permission (`roles.read`, `roles.create`, `roles.update`, `roles.delete`)

**Documentation:** See Issue #137 and Issue #140

### Direct Permission API (Phase 4)

| Method   | Endpoint                                  | Description                                     |
| -------- | ----------------------------------------- | ----------------------------------------------- |
| `GET`    | `/v1/users/{id}/permissions`              | List all permissions (role + direct + combined) |
| `POST`   | `/v1/users/{id}/permissions`              | Assign direct permission                        |
| `DELETE` | `/v1/users/{id}/permissions/{permission}` | Revoke direct permission                        |
| `GET`    | `/v1/users/{id}/permissions/direct`       | List only direct permissions                    |

**Authorization:** Users may view their own direct permissions; assignment and revocation require `permissions.assign_direct` / `permissions.revoke_direct`, and viewing another user's permissions requires tenant-scoped `permissions.read`

**Documentation:** See Issue #139 and Issue #140

## Developer Guidelines

### Decision Tree: Create Role vs Direct Permission

```text
Need to grant access?
│
├─ Multiple users need same access?
│  ├─ YES → Create new role
│  └─ NO → Continue ↓
│
├─ Standard access pattern for this position?
│  ├─ YES → Use existing role (Manager, Guard, etc.)
│  └─ NO → Continue ↓
│
├─ Long-term access (>1 month)?
│  ├─ YES → Create custom role
│  └─ NO → Continue ↓
│
└─ Organization-wide permission change?
   ├─ YES → Modify existing role
   └─ NO → ✅ Use Direct Permission
```

### Decision Tree: Permanent vs Temporal Assignment

```text
Assigning role/permission?
│
├─ Access needed until employment ends?
│  ├─ YES → ✅ Permanent Assignment
│  └─ NO → Continue ↓
│
├─ Temporary coverage (vacation, leave)?
│  └─ YES → ✅ Temporal (1-2 weeks)
│
├─ Project-based access?
│  └─ YES → ✅ Temporal (weeks to months)
│
├─ Event-based elevation?
│  └─ YES → ✅ Temporal (hours to days)
│
└─ Emergency/debugging access?
   └─ YES → ✅ Temporal (minutes to hours)
```

### Best Practices: Permission Naming

**DO:**

```text
✅ employees.read
✅ employees.create
✅ employees.update
✅ employees.delete
✅ employees.read_salary      (specific action)
✅ shifts.publish              (workflow action)
✅ work_instructions.acknowledge
```

**DON'T:**

```text
❌ read_employee              (wrong order)
❌ employee-read              (use dot, not dash)
❌ readEmployee               (use snake_case, not camelCase)
❌ employees                  (missing action)
❌ can_read_employees         (redundant "can_")
```

**Special Permissions:**

```text
resource.read              → View resource
resource.create            → Create new resource
resource.update            → Modify existing resource
resource.delete            → Remove resource
resource.export            → Export data
resource.publish           → Activate/publish resource
resource.approve_as_br     → Role-specific action
resource.read_all_branches → Scope escalation
```

### Best Practices: Role Design

**DO:**

- ✅ Create roles based on job functions (Manager, Guard, Client)
- ✅ Use descriptive names that reflect responsibilities
- ✅ Group related permissions together
- ✅ Document role purpose and typical use cases
- ✅ Keep predefined roles for common cases

**DON'T:**

- ❌ Create roles for individual users
- ❌ Create temporary roles for short-term access (use temporal assignments)
- ❌ Create roles with single permission (use direct permission)
- ❌ Create redundant roles with overlapping permissions

**Example Good Roles:**

```text
✅ Regional Manager
   - employees.read (all branches)
   - employees.update (all branches)
   - shifts.read (all branches)
   - reports.generate

✅ Shift Coordinator
   - shifts.read
   - shifts.create
   - shifts.update
   - employees.read (own branch)
```

**Example Bad Roles:**

```text
❌ John's Special Access       (user-specific)
❌ Vacation Coverage Dec 2025  (temporal case)
❌ Export Only                 (single permission)
❌ Manager but no delete       (negative permission - use direct revoke instead)
```

### Testing Authorization

**Policy Tests:**

```php
test('manager can update employees in own branch', function () {
    $manager = User::factory()->create(['branch_id' => 1]);
    $manager->assignRole('manager');

    $employee = Employee::factory()->create(['branch_id' => 1]);

    expect($manager->can('update', $employee))->toBeTrue();
});

test('manager cannot update employees in other branch', function () {
    $manager = User::factory()->create(['branch_id' => 1]);
    $manager->assignRole('manager');

    $employee = Employee::factory()->create(['branch_id' => 2]);

    expect($manager->can('update', $employee))->toBeFalse();
});

test('user with explicit cross-branch permission can update employees in all branches', function () {
    $operator = User::factory()->create(['branch_id' => 1]);
    $operator->givePermissionTo('employees.update');
    $operator->givePermissionTo('employees.read_all_branches');

    $employee = Employee::factory()->create(['branch_id' => 2]);

    expect($operator->can('update', $employee))->toBeTrue();
});
```

**Direct Permission Tests:**

```php
test('direct permission grants access independent of role', function () {
    $user = User::factory()->create();
    $user->assignRole('guard');  // Guard normally can't export

    // Give direct permission
    $user->givePermissionTo('employees.export');

    expect($user->can('employees.export'))->toBeTrue();
});

test('direct permission survives role removal', function () {
    $user = User::factory()->create();
    $user->assignRole('manager');
    $user->givePermissionTo('employees.export');

    // Remove role
    $user->removeRole('manager');

    // Direct permission remains
    expect($user->can('employees.export'))->toBeTrue();
});
```

**Temporal Role Tests:**

```php
test('temporal role expires automatically', function () {
    $user = User::factory()->create();

    // Assign role expiring in 1 hour
    $user->assignRole('manager', [
        'valid_from' => now(),
        'valid_until' => now()->addHour(),
        'auto_revoke' => true,
    ]);

    expect($user->hasRole('manager'))->toBeTrue();

    // Travel to after expiration (using Pest's time travel helpers)
    $this->travel(2)->hours();

    // Run expiration command
    Artisan::call('roles:expire');

    // Role should be revoked
    expect($user->fresh()->hasRole('manager'))->toBeFalse();
});
```

## References

### Architecture Decision Records

- [ADR-005: RBAC Design Decisions](https://github.com/SecPal/.github/blob/main/docs/adr/20251111-rbac-design-decisions.md)
  - Decision 1: No System Roles
  - Decision 2: Direct Permissions Independent of Roles
  - Decision 3: Temporal Assignments Are Optional

### Related Issues

- [Issue #5: RBAC System](https://github.com/SecPal/api/issues/5) - Parent issue with original requirements
- [Issue #108: RBAC Phase 4](https://github.com/SecPal/api/issues/108) - Role/Permission Management API
- [Issue #137: Permission Management CRUD](https://github.com/SecPal/api/issues/137) - Phase 4 sub-issue
- [Issue #138: User Direct Permission API](https://github.com/SecPal/api/issues/138) - Phase 4 sub-issue
- [Issue #139: Predefined Roles Seeder](https://github.com/SecPal/api/issues/139) - Phase 4 sub-issue
- [Issue #140: RBAC API Documentation](https://github.com/SecPal/api/issues/140) - Phase 4 sub-issue
- [Issue #141: Complete RBAC Documentation Epic](https://github.com/SecPal/api/issues/141) - Parent epic

### Developer Guides (Planned)

- `docs/guides/direct-permissions.md` - When and how to use direct permissions (Issue #144)
- `docs/guides/temporal-roles.md` - Temporal assignment patterns and use cases (Issue #144)

### Implementation Details

- [ADR-004: RBAC Architecture Decision](https://github.com/SecPal/.github/blob/main/docs/adr/20251108-rbac-architecture-decision.md) - Spatie vs Custom evaluation
- Spatie Laravel-Permission: [Documentation](https://spatie.be/docs/laravel-permission)
- Laravel Authorization: [Official Docs](https://laravel.com/docs/13.x/authorization)

---

**Document Version:** 1.0.0
**Last Updated:** 2025-11-11
**Status:** Published
**Maintainer:** @kevalyq

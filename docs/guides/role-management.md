<!-- SPDX-FileCopyrightText: 2025-2026 SecPal Contributors -->
<!-- SPDX-License-Identifier: CC0-1.0 -->

# Role Management Guide

Complete guide to creating, managing, and assigning roles in SecPal's RBAC system.

## Table of Contents

- [Introduction](#introduction)
- [Predefined Roles](#predefined-roles)
- [Creating Custom Roles](#creating-custom-roles)
- [Assigning Roles to Users](#assigning-roles-to-users)
- [Managing Role Permissions](#managing-role-permissions)
- [Temporal Role Assignments](#temporal-role-assignments)
- [Revoking Roles](#revoking-roles)
- [Deleting Roles](#deleting-roles)
- [Best Practices](#best-practices)

---

## Introduction

Roles are **named collections of permissions** that define what users can do in SecPal. Instead of assigning permissions individually, you group related permissions into roles and assign those roles to users.

**Key Concepts:**

- **Role** = Collection of permissions (e.g., "Manager" has `employees.*`, `shifts.*`)
- **Assignment** = Giving a user a role (can be permanent or temporal)
- **Permission** = Individual capability (e.g., `employees.read`)

**Design Philosophy:**

- ✅ All roles are equal (no "system" vs "custom" distinction)
- ✅ Roles can be deleted only if not assigned to users
- ✅ Predefined roles are recreated by seeder if deleted
- ✅ Temporal assignments enable time-limited access

See [RBAC Architecture](../rbac-architecture.md) for full system design.

---

## Predefined Roles

SecPal seeds seven predefined roles that cover common use cases. There is no predefined `Admin` role. Broad access is modeled by combining explicit permissions with explicit organizational scopes.

### Employee

**Description:** Standard employee self-service access

**Typical Permissions:**

- `employee.read`, `employee.update`
- `employee_qualification.read`, `employee_document.read`
- `qualification.read`
- `shifts.read`, `shifts.update`
- `work_instructions.read`, `work_instructions.acknowledge`

**Use Cases:**

- Internal employees managing their own profile data
- Staff reviewing their own qualifications and documents
- Guards acknowledging work instructions

**Scope:** Own data and policy-limited operational records

---

### Employee Read Only

**Description:** Read-only employee self-service access

**Typical Permissions:**

- `employee.read`
- `employee_qualification.read`, `employee_document.read`
- `qualification.read`
- `shifts.read`
- `work_instructions.read`

**Use Cases:**

- Employees who may inspect but not edit their records
- Temporary viewer-style self-service access

**Scope:** Own data only, without write operations

---

### HR

**Description:** HR and onboarding operations

**Typical Permissions:**

- `employees.read`, `employees.create`, `employees.update`, `employees.delete`
- `employees.read_sensitive`, `employees.read_salary`, `employees.export`
- `employee.read`, `employee.write`, `employee.activate`, `employee.terminate`
- `employee_qualification.write`, `employee_document.write`
- `onboarding.read`, `onboarding.write`, `onboarding.approve`, `onboarding.confirm`
- `reports.view`, `reports.generate`

**Use Cases:**

- HR teams
- Recruiting and onboarding operators
- Compliance staff handling employee master data

**Scope:** Organizational-scope limited HR workflows

---

### Manager

**Description:** Operational management across assigned organizational scopes

**Typical Permissions:**

- `customers.read`, `customers.create`, `customers.update`
- `sites.read`, `sites.create`, `sites.update`
- `assignments.create`, `assignments.update`
- `cost-centers.read`, `cost-centers.create`, `cost-centers.update`
- `employees.read`, `employees.create`, `employees.update`, `employees.read_salary`
- `shifts.read`, `shifts.create`, `shifts.update`, `shifts.delete`, `shifts.publish`
- `work_instructions.read`, `work_instructions.create`, `work_instructions.update`, `work_instructions.publish`
- `activity_log.read`, `activity_log.read_system`, `onboarding.read`, `onboarding.write`

**Use Cases:**

- Branch managers
- Team leads
- Operations managers

**Scope:** Limited by explicit organizational scopes, not by any implicit global shortcut role

---

### Guard

**Description:** Security personnel (own data + assignments)

**Typical Permissions:**

- `employees.read` (own data only)
- `shifts.read`, `shifts.update` (policy-limited)
- `work_instructions.read`, `work_instructions.acknowledge`

**Use Cases:**

- Security guards
- On-site personnel
- Field workers

**Scope:** Own data plus assigned operational records

---

### Client

**Description:** External stakeholder access

**Typical Permissions:**

- `shifts.read`
- `work_instructions.read`
- `reports.view`

**Use Cases:**

- Customers/clients
- Property managers
- External stakeholders

**Scope:** Customer, site, and organizational assignment scoped

---

### Works Council

**Description:** Employee representation with approval rights

**Typical Permissions:**

- `employees.read`, `employees.read_all_branches`
- `shifts.read`, `shifts.approve_as_br`
- `works_council.access_employee_files`, `works_council.approve_shift_plans`
- `reports.view`

**Use Cases:**

- Works council members (Betriebsrat)
- Employee representatives
- Union representatives

**Scope:** Organization-wide approval workflows within assigned scope boundaries

---

## Creating Custom Roles

Custom roles allow you to tailor permissions to specific organizational needs.

### When to Create a Custom Role

**Create a custom role when:**

- ✅ Multiple users need the same set of permissions
- ✅ Standard roles don't fit the job function
- ✅ Access pattern is recurring (not one-time)

**Don't create a custom role when:**

- ❌ Only one user needs this access → Use [direct permissions](direct-permissions.md)
- ❌ Access is temporary (< 1 month) → Use [temporal role assignment](temporal-roles.md)
- ❌ Permissions exist in a predefined role → Assign that role instead

### Step-by-Step: Create Role via API

**1. Identify the permissions needed:**

```text
Example: Regional Manager needs:
- View/edit employees in multiple branches
- View/create shifts in multiple branches
- Generate reports
```

**2. Send API request:**

```bash
curl -X POST https://api.secpal.dev/v1/roles \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "regional_manager",
    "description": "Manages multiple branches in a region",
    "permissions": [
      "employees.read",
      "employees.update",
      "shifts.read",
      "shifts.create",
      "reports.generate"
    ]
  }'
```

**3. Verify creation:**

```bash
curl -X GET https://api.secpal.dev/v1/roles/6 \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Response:**

```json
{
  "data": {
    "id": 6,
    "name": "regional_manager",
    "description": "Manages multiple branches in a region",
    "permissions_count": 5,
    "users_count": 0
  }
}
```

### Role Naming Conventions

**Good names:**

- ✅ `regional_manager` - Descriptive, job-function based
- ✅ `shift_coordinator` - Clear responsibility
- ✅ `auditor` - Role-based, not user-based

**Bad names:**

- ❌ `johns_role` - User-specific
- ❌ `temp_access_nov` - Temporal (use temporal assignment instead)
- ❌ `manager2` - Ambiguous, numbered

**Rules:**

- Use lowercase
- Use underscores for spaces
- Be descriptive (3-20 characters)
- Focus on job function, not user

---

## Assigning Roles to Users

### Permanent Assignment (Default)

Most role assignments are **permanent** - they last until manually revoked or the user leaves.

#### Example: Assign Manager role

```bash
curl -X POST https://api.secpal.dev/v1/users/123/roles \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "role": "manager"
  }'
```

**Response:**

```json
{
  "data": {
    "user_id": 123,
    "role": "manager",
    "assigned_at": "2025-11-15T10:00:00Z",
    "is_temporal": false
  }
}
```

**When to use permanent:**

- ✅ Employee promoted to manager
- ✅ New hire assigned to guard role
- ✅ Long-term contractor
- ✅ Standard team structure

---

### Temporal Assignment (Optional)

Temporal assignments **expire automatically** after a specified date. Perfect for temporary coverage or time-limited access.

#### Example: 2-week vacation coverage

```bash
curl -X POST https://api.secpal.dev/v1/users/456/roles \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "role": "manager",
    "valid_from": "2025-12-01T00:00:00Z",
    "valid_until": "2025-12-14T23:59:59Z",
    "reason": "Vacation coverage for Manager A"
  }'
```

**Response:**

```json
{
  "data": {
    "user_id": 456,
    "role": "manager",
    "valid_from": "2025-12-01T00:00:00Z",
    "valid_until": "2025-12-14T23:59:59Z",
    "is_temporal": true,
    "expires_in_days": 29
  }
}
```

**Automatic Expiration:**

- ✅ Role expires on Dec 14, 2025 at 23:59:59 UTC
- ✅ No manual cleanup needed
- ✅ Audit trail records expiration
- ✅ User returns to previous permissions

**When to use temporal:**

- ✅ Vacation coverage (1-2 weeks)
- ✅ Project-based access (weeks to months)
- ✅ Event-based elevation (hours to days)
- ✅ Emergency access (minutes to hours)

See [Temporal Roles Guide](temporal-roles.md) for detailed use cases.

---

### Viewing User's Roles

**Get all roles for a user:**

```bash
curl -X GET https://api.secpal.dev/v1/users/123/roles \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Response:**

```json
{
  "data": [
    {
      "id": 2,
      "name": "manager",
      "description": "Branch management",
      "assigned_at": "2025-11-01T10:00:00Z",
      "is_temporal": false,
      "is_active": true
    },
    {
      "id": 6,
      "name": "regional_manager",
      "assigned_at": "2025-12-01T00:00:00Z",
      "valid_until": "2025-12-14T23:59:59Z",
      "is_temporal": true,
      "is_active": true,
      "expires_in_days": 29
    }
  ]
}
```

**Fields explained:**

- `is_temporal` - Whether role has expiration date
- `is_active` - Whether role is currently active (respects `valid_from` and `valid_until`)
- `expires_in_days` - Days remaining until expiration (temporal roles only)

---

## Managing Role Permissions

### Adding Permissions to a Role

#### Method 1: Replace all permissions (recommended)

```bash
curl -X PATCH https://api.secpal.dev/v1/roles/6 \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "permissions": [
      "employees.read",
      "employees.update",
      "shifts.*",
      "reports.generate"
    ]
  }'
```

This **replaces** all permissions with the new list.

#### Method 2: Add individual permission (future)

```bash
# Not yet implemented - planned for future release
POST /v1/roles/{id}/permissions
{
  "permission": "reports.export"
}
```

---

### Removing Permissions from a Role

#### Method 1: Update with reduced list (current)

```bash
curl -X PATCH https://api.secpal.dev/v1/roles/6 \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "permissions": [
      "employees.read",
      "shifts.read"
    ]
  }'
```

#### Method 2: Delete individual permission (future)

```bash
# Not yet implemented - planned for future release
DELETE /v1/roles/{id}/permissions/{permission}
```

---

### Viewing Role Permissions

**Get detailed role information including permissions:**

```bash
curl -X GET https://api.secpal.dev/v1/roles/2 \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Response:**

```json
{
  "data": {
    "id": 2,
    "name": "manager",
    "permissions": [
      {
        "id": 5,
        "name": "employees.read"
      },
      {
        "id": 6,
        "name": "employees.create"
      },
      {
        "id": 15,
        "name": "shifts.read"
      }
    ],
    "permissions_count": 15,
    "users_count": 8
  }
}
```

---

## Temporal Role Assignments

See [Temporal Roles Guide](temporal-roles.md) for comprehensive coverage. Quick reference:

### Use Cases

| Scenario          | Duration         | Example                        |
| ----------------- | ---------------- | ------------------------------ |
| Vacation coverage | 1-2 weeks        | Acting manager during absence  |
| Project access    | Weeks to months  | External consultant on project |
| Event coverage    | Hours to days    | Team lead during large event   |
| Emergency access  | Minutes to hours | Developer production access    |

### Extending Expiration

**Extend a temporal role assignment:**

```bash
curl -X PATCH https://api.secpal.dev/v1/users/456/roles/manager/extend \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "valid_until": "2025-12-31T23:59:59Z"
  }'
```

**Use cases:**

- ✅ Vacation extended
- ✅ Project deadline moved
- ✅ Consultant contract extended

---

## Revoking Roles

Remove a role assignment from a user (works for both permanent and temporal assignments).

**Example:**

```bash
curl -X DELETE https://api.secpal.dev/v1/users/123/roles/manager \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Response:**

```json
{
  "message": "Role revoked successfully",
  "data": {
    "user_id": 123,
    "role": "manager",
    "revoked_at": "2025-11-15T10:00:00Z"
  }
}
```

**When to revoke:**

- ✅ User changes position
- ✅ User no longer needs elevated access
- ✅ Early termination of temporal assignment
- ✅ User leaves organization (revoke all roles)

**Audit Trail:**

All revocations are logged in `role_assignments_log` table for compliance.

---

## Deleting Roles

**⚠️ Important:** You can only delete roles that are **not assigned to any users**.

### Step 1: Check if Role is Assigned

```bash
curl -X GET https://api.secpal.dev/v1/roles/6 \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Look at `users_count` field:**

```json
{
  "data": {
    "id": 6,
    "name": "regional_manager",
    "users_count": 5
  }
}
```

If `users_count > 0`, you must first revoke all role assignments.

---

### Step 2: Revoke All Assignments (if needed)

#### Option A: Revoke manually for each user

```bash
# For each user with this role:
curl -X DELETE https://api.secpal.dev/v1/users/{user_id}/roles/regional_manager \
  -H "Authorization: Bearer YOUR_TOKEN"
```

#### Option B: Bulk revoke (via application logic)

```php
// In Laravel Tinker or custom command:
$role = Role::findByName('regional_manager');
foreach ($role->users as $user) {
    $user->removeRole('regional_manager');
}
```

---

### Step 3: Delete the Role

```bash
curl -X DELETE https://api.secpal.dev/v1/roles/6 \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Success Response:**

```json
{
  "message": "Role deleted successfully"
}
```

**Error Response (if still assigned):**

```json
{
  "message": "Cannot delete role while assigned to users",
  "errors": {
    "role": ["This role is currently assigned to 5 users."]
  }
}
```

---

### Predefined Roles Recovery

**If you delete a predefined role** (Employee, Employee Read Only, HR, Manager, Guard, Client, or Works Council):

1. ✅ Deletion succeeds (if not assigned to users)
2. ✅ Next time `RolesAndPermissionsSeeder` runs, role is recreated
3. ✅ Role returns with default permissions

**To manually recreate:**

```bash
php artisan db:seed --class=RolesAndPermissionsSeeder
```

This is **idempotent** - safe to run multiple times.

---

## Best Practices

### Role Design

**✅ DO:**

- Create roles based on job functions (Manager, Guard, Coordinator)
- Use descriptive names that reflect responsibilities
- Group related permissions together
- Document the purpose of each custom role
- Review role permissions quarterly

**❌ DON'T:**

- Create roles for individual users
- Create temporary roles (use temporal assignments instead)
- Create roles with a single permission (use direct permissions)
- Create redundant roles with overlapping permissions
- Use ambiguous names like "role1", "temp_role"

---

### Assignment Strategy

**Decision Tree:**

```text
Need to grant access?
│
├─ Standard job function? (Manager, Guard, etc.)
│  └─ ✅ Assign predefined role (permanent)
│
├─ Multiple users need same permissions?
│  └─ ✅ Create custom role → Assign to all
│
├─ Temporary access? (< 1 month)
│  └─ ✅ Assign role with temporal constraints
│
├─ One-off exceptional access?
│  └─ ✅ Use direct permissions (not roles)
│
└─ Long-term unique access?
   └─ ✅ Create custom role → Assign permanently
```

---

### Security

**✅ DO:**

- Use temporal assignments for temporary access (vacation, projects)
- Revoke roles immediately when user changes position
- Audit role assignments quarterly
- Use least privilege principle (minimum permissions needed)
- Document the reason for temporal assignments

**❌ DON'T:**

- Simulate full access with ad-hoc role sprawl; use explicit permissions plus `manage` scopes or a documented custom role instead
- Forget to revoke roles when users leave
- Use permanent assignments for temporary needs
- Skip the `reason` field on temporal assignments (needed for audits)
- Assign multiple overlapping roles (consolidate instead)

---

### Performance

**Considerations:**

- ✅ Each user should have 1-3 roles typically
- ✅ Roles with 5-20 permissions are optimal
- ⚠️ Avoid users with 5+ roles (consolidate into fewer roles)
- ⚠️ Avoid roles with 50+ permissions (consider splitting)

**Caching:**

SecPal caches role/permission checks. Clear cache if unexpected permission issues:

```bash
php artisan cache:clear
php artisan permission:cache-reset
```

---

## Examples

### Example 1: Promote Employee to Manager

```bash
# 1. Assign manager role
curl -X POST https://api.secpal.dev/v1/users/123/roles \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"role": "manager"}'

# 2. Remove old guard role
curl -X DELETE https://api.secpal.dev/v1/users/123/roles/guard \
  -H "Authorization: Bearer YOUR_TOKEN"
```

---

### Example 2: Vacation Coverage (2 weeks)

```bash
# Assign temporary manager role
curl -X POST https://api.secpal.dev/v1/users/456/roles \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "role": "manager",
    "valid_from": "2025-12-01T00:00:00Z",
    "valid_until": "2025-12-14T23:59:59Z",
    "reason": "Vacation coverage for Manager A (Employee ID: 123)"
  }'
```

**Result:**

- ✅ User 456 becomes manager on Dec 1
- ✅ Role expires automatically on Dec 14
- ✅ User 456 returns to previous permissions
- ✅ No manual cleanup needed

---

### Example 3: Create Custom "Shift Coordinator" Role

```bash
# 1. Create role
curl -X POST https://api.secpal.dev/v1/roles \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "shift_coordinator",
    "description": "Coordinates shift planning across multiple branches",
    "permissions": [
      "shifts.read",
      "shifts.create",
      "shifts.update",
      "employees.read",
      "work_instructions.read"
    ]
  }'

# 2. Assign to users
curl -X POST https://api.secpal.dev/v1/users/789/roles \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"role": "shift_coordinator"}'
```

---

## Troubleshooting

### "Role not found"

**Cause:** Role name typo or role doesn't exist

**Solution:**

```bash
# List all roles
curl -X GET https://api.secpal.dev/v1/roles \
  -H "Authorization: Bearer YOUR_TOKEN"
```

---

### "Cannot delete role while assigned to users"

**Cause:** Role is still assigned to one or more users

**Solution:**

1. Get users with this role:

```bash
curl -X GET https://api.secpal.dev/v1/roles/{id} \
  -H "Authorization: Bearer YOUR_TOKEN"
# Check "users_count" field
```

1. Revoke role from all users first
2. Then delete the role

---

### "This action is unauthorized"

**Cause:** User lacks required permission

**Solution:** Role-management endpoints require explicit management permissions. Check:

```bash
curl -X GET https://api.secpal.dev/v1/me \
  -H "Authorization: Bearer YOUR_TOKEN"
```

Ensure the response includes the relevant `roles.create`, `roles.update`, and `roles.delete` permissions.

---

### Temporal role not expiring

**Cause:** Scheduled command not running

**Solution:**

```bash
# Manually trigger expiration
php artisan roles:expire

# Check scheduler is running
php artisan schedule:list
```

Ensure `php artisan schedule:run` runs every minute (via cron or supervisor).

---

## Related Documentation

- [RBAC API Endpoints](../api/rbac-endpoints.md) - Complete API reference
- [Permission System Guide](permission-system.md) - Permission naming and organization
- [Direct Permissions Guide](direct-permissions.md) - When to use direct permissions
- [Temporal Roles Guide](temporal-roles.md) - Detailed temporal role patterns
- [RBAC Architecture](../rbac-architecture.md) - System design and philosophy

---

**Document Version:** 1.0.0
**Last Updated:** 2025-11-15
**Status:** Complete
**Maintainer:** @kevalyq

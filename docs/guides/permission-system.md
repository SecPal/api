<!-- SPDX-FileCopyrightText: 2025 SecPal -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Permission System Guide

Understanding SecPal's permission naming, organization, and management.

## Table of Contents

- [Permission Naming Convention](#permission-naming-convention)
- [Resource Organization](#resource-organization)
- [Permission Matrix](#permission-matrix)
- [Creating Custom Permissions](#creating-custom-permissions)
- [Permission Lifecycle](#permission-lifecycle)
- [Best Practices](#best-practices)

---

## Permission Naming Convention

All permissions follow the **`resource.action`** pattern.

### Format

```text
resource.action

Examples:
✅ employees.read
✅ employees.create
✅ shifts.publish
✅ work_instructions.acknowledge
```

### Rules

1. **Lowercase only** - no uppercase letters
2. **Dot separator** - use `.` not `-` or `_`
3. **Resource first** - then action
4. **Snake_case** - for multi-word resources (`work_instructions`)
5. **Descriptive** - action describes what it does

### Valid Examples

```text
✅ employees.read              - View employee data
✅ employees.create            - Create new employees
✅ employees.update            - Modify employee data
✅ employees.delete            - Remove employees
✅ employees.read_salary       - View salary data (special action)
✅ employees.export            - Export employee data
✅ shifts.publish              - Publish shift plans
✅ shifts.approve_as_br        - Works council approval
✅ work_instructions.read      - View work instructions
✅ reports.generate            - Generate reports
```

### Invalid Examples

```text
❌ Read_Employees              - Wrong order, uppercase
❌ employee-read               - Wrong separator
❌ readEmployees               - CamelCase not allowed
❌ can_read_employees          - Redundant prefix
❌ employees                   - Missing action
❌ EMPLOYEES.READ              - All caps not allowed
```

---

## Resource Organization

Permissions are grouped by **resource** (domain entity).

### Core Resources

| Resource            | Description            | Example Permissions                            |
| ------------------- | ---------------------- | ---------------------------------------------- |
| `employees`         | Employee management    | `read`, `create`, `update`, `delete`           |
| `shifts`            | Shift planning         | `read`, `create`, `publish`, `approve_as_br`   |
| `work_instructions` | Work instructions      | `read`, `create`, `publish`, `acknowledge`     |
| `roles`             | Role management        | `read`, `create`, `update`, `delete`           |
| `permissions`       | Permission management  | `read`, `create`, `update`, `delete`           |
| `works_council`     | Works council features | `access_employee_files`, `approve_shift_plans` |
| `reports`           | Report generation      | `view`, `generate`, `export`                   |

---

### Common Actions

Standard CRUD actions used across resources:

| Action   | Description              | Example            |
| -------- | ------------------------ | ------------------ |
| `read`   | View/list resources      | `employees.read`   |
| `create` | Create new resources     | `shifts.create`    |
| `update` | Modify existing resource | `employees.update` |
| `delete` | Remove resources         | `role.delete`      |
| `export` | Export data (CSV/Excel)  | `employees.export` |

---

### Special Actions

Domain-specific actions for workflows:

| Action              | Description                   | Example                         |
| ------------------- | ----------------------------- | ------------------------------- |
| `publish`           | Make resource active/visible  | `shifts.publish`                |
| `acknowledge`       | Confirm receipt/understanding | `work_instructions.acknowledge` |
| `approve_as_br`     | Works council approval        | `shifts.approve_as_br`          |
| `read_salary`       | View sensitive salary data    | `employees.read_salary`         |
| `read_all_branches` | Cross-branch access           | `employees.read_all_branches`   |
| `generate`          | Create dynamic content        | `reports.generate`              |
| `assign_temporary`  | Assign temporal roles         | `roles.assign_temporary`        |
| `extend_expiration` | Extend role expiration        | `roles.extend_expiration`       |

---

## Permission Matrix

Permissions assigned to predefined roles:

### Admin Role

**Philosophy:** Full system access

```text
Permissions: * (all permissions)

Or explicitly:
- employees.*
- shifts.*
- work_instructions.*
- roles.*
- permissions.*
- works_council.*
- reports.*
```

---

### Manager Role

**Philosophy:** Branch-level management

```text
Employees:
- employees.read
- employees.create
- employees.update
- employees.delete (optional - some orgs restrict)

Shifts:
- shifts.read
- shifts.create
- shifts.update
- shifts.publish

Work Instructions:
- work_instructions.read
- work_instructions.create
- work_instructions.update
- work_instructions.publish

Reports:
- reports.view
- reports.generate

Roles:
- role.read (view team roles)
- role.assign (assign roles to team)
```

---

### Guard Role

**Philosophy:** Own data + assignments

```text
Employees:
- employees.read (own data only - policy enforced)

Shifts:
- shifts.read (own assignments only - policy enforced)

Work Instructions:
- work_instructions.read
- work_instructions.acknowledge
```

---

### Client Role

**Philosophy:** Read-only customer access

```text
Shifts:
- shifts.read (location-specific - policy enforced)

Work Instructions:
- work_instructions.read (location-specific)

Reports:
- reports.view (location-specific)
```

---

### Works Council Role

**Philosophy:** Employee representation + approval

```text
Employees:
- employees.read (limited fields - policy enforced)

Shifts:
- shifts.read
- shifts.approve_as_br

Works Council:
- works_council.access_employee_files
- works_council.approve_shift_plans
```

---

## Creating Custom Permissions

### When to Create Custom Permissions

**Create custom permission when:**

- ✅ New feature requires new capability
- ✅ Existing permissions too broad (need granularity)
- ✅ Regulatory requirement (e.g., GDPR audit access)
- ✅ Business process unique to organization

**Don't create custom permission when:**

- ❌ Existing permission covers the use case
- ❌ One-time need (use direct permission assignment instead)
- ❌ Permission duplicates existing functionality

---

### Step-by-Step: Create Permission

#### 1. Choose Resource and Action

```text
Example: Need permission to export employee data

Resource: employees
Action: export
Permission: employees.export
```

#### 2. Create via API

```bash
curl -X POST https://api.secpal.app/v1/permissions \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "employees.export",
    "description": "Export employee data to CSV/Excel"
  }'
```

#### 3. Verify Creation

```bash
curl -X GET https://api.secpal.app/v1/permissions/43 \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Response:**

```json
{
  "data": {
    "id": 43,
    "name": "employees.export",
    "description": "Export employee data to CSV/Excel",
    "roles_count": 0,
    "created_at": "2025-11-15T10:00:00Z"
  }
}
```

#### 4. Assign to Roles

```bash
# Add to Manager role
curl -X PATCH https://api.secpal.app/v1/roles/2 \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "permissions": [
      "employees.read",
      "employees.create",
      "employees.update",
      "employees.export"
    ]
  }'
```

---

## Permission Lifecycle

### 1. Creation

```text
Permission Created
│
├─ Assigned to Roles
│  └─ Users inherit via roles
│
└─ Assigned Directly to Users
   └─ Users get via direct assignment
```

---

### 2. Assignment

**To Role:**

```bash
PATCH /api/v1/roles/{id}
{
  "permissions": ["employees.read", "employees.export"]
}
```

**To User (Direct):**

```bash
POST /api/v1/users/{id}/permissions
{
  "permissions": ["employees.export"]
}
```

---

### 3. Revocation

**From Role:**

```bash
# Update role with reduced permissions
PATCH /api/v1/roles/{id}
{
  "permissions": ["employees.read"]  # removed employees.export
}
```

**From User (Direct):**

```bash
DELETE /api/v1/users/{id}/permissions/employees.export
```

---

### 4. Deletion

**Requirements:**

- ✅ Permission not assigned to any role
- ✅ Permission not assigned to any user

**Delete:**

```bash
curl -X DELETE https://api.secpal.app/v1/permissions/43 \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Error if in use:**

```json
{
  "message": "Cannot delete permission while assigned to roles or users",
  "errors": {
    "permission": ["This permission is assigned to 3 roles and 2 users."]
  }
}
```

---

## Best Practices

### Naming

**✅ DO:**

- Use `resource.action` format consistently
- Be specific (`employees.read_salary` not `employees.read_sensitive`)
- Use verbs for actions (`create`, `update`, `publish`)
- Use present tense (`read` not `reading`)
- Group by resource first

**❌ DON'T:**

- Mix naming conventions
- Use abbreviations (`emp.rd` ❌)
- Use negative permissions (`employees.cant_delete` ❌)
- Create overly generic permissions (`admin` ❌)
- Use business-specific jargon (`employees.foo_bar` ❌)

---

### Granularity

**✅ Right Level:**

```text
✅ employees.read              - View employees
✅ employees.read_salary       - View salary data (more specific)
✅ employees.export            - Export functionality
```

**❌ Too Broad:**

```text
❌ employees                   - What action?
❌ employees.manage            - Too vague (create? update? delete?)
```

**❌ Too Granular:**

```text
❌ employees.read_firstname    - Too specific
❌ employees.read_lastname     - Creates permission explosion
❌ employees.update_email      - Use employees.update + policy logic
```

**Rule of Thumb:**

- **Action-level granularity** (read, create, update)
- **Special cases as separate permissions** (read_salary, export)
- **Scope enforcement in policies** (own data, branch-level, etc.)

---

### Organization

**Group permissions by resource in code/docs:**

```php
// Good: Grouped by resource
$employeePermissions = [
    'employees.read',
    'employees.create',
    'employees.update',
    'employees.delete',
    'employees.read_salary',
    'employees.export',
];

$shiftPermissions = [
    'shifts.read',
    'shifts.create',
    'shifts.update',
    'shifts.publish',
];
```

---

### Documentation

**✅ DO:**

- Document what each permission allows
- Provide use case examples
- Explain special permissions (why needed)
- Note policy-level restrictions (branch-scoped, etc.)

**Example:**

```php
/**
 * Permission: employees.read_salary
 *
 * Allows viewing salary data for employees.
 *
 * Scope: Branch-scoped for Manager, Global for Admin
 * Policy: EmployeePolicy::viewSalary()
 * Use Case: Payroll review, compensation analysis
 * GDPR Note: Subject to data protection logs
 */
```

---

## Examples

### Example 1: Add Export Functionality

**Scenario:** Managers need to export employee data to Excel.

**Steps:**

1. Create permission:

```bash
POST /api/v1/permissions
{
  "name": "employees.export",
  "description": "Export employee data to CSV/Excel"
}
```

1. Add to Manager role:

```bash
PATCH /api/v1/roles/2
{
  "permissions": [
    "employees.read",
    "employees.create",
    "employees.update",
    "employees.export"
  ]
}
```

1. Implement in code:

```php
// Controller
public function export(Request $request)
{
    $this->authorize('export', Employee::class);
    // Export logic...
}

// Policy
public function export(User $user): bool
{
    return $user->hasPermissionTo('employees.export');
}
```

---

### Example 2: Granular Report Access

**Scenario:** Some managers can generate reports, some can only view.

**Solution:**

```bash
# Create two permissions
POST /api/v1/permissions
{
  "name": "reports.view",
  "description": "View existing reports"
}

POST /api/v1/permissions
{
  "name": "reports.generate",
  "description": "Generate new reports"
}

# Assign to roles
# Junior Manager: view only
PATCH /api/v1/roles/junior_manager
{
  "permissions": ["reports.view"]
}

# Senior Manager: view + generate
PATCH /api/v1/roles/senior_manager
{
  "permissions": ["reports.view", "reports.generate"]
}
```

---

### Example 3: Works Council Special Access

**Scenario:** Works council needs limited employee access + approval rights.

**Solution:**

```bash
# Create specific permissions
POST /api/v1/permissions
{
  "name": "works_council.access_employee_files",
  "description": "Access employee files for works council purposes"
}

POST /api/v1/permissions
{
  "name": "shifts.approve_as_br",
  "description": "Approve shift plans as works council representative"
}

# Assign to Works Council role
PATCH /api/v1/roles/works_council
{
  "permissions": [
    "employees.read",
    "works_council.access_employee_files",
    "shifts.approve_as_br"
  ]
}
```

**Policy enforcement:**

```php
// Limit what employee data works council can see
public function view(User $user, Employee $employee): bool
{
    if ($user->hasRole('works_council')) {
        // Only basic info, no salary
        return true;
    }

    return $user->hasPermissionTo('employees.read')
        && $user->branch_id === $employee->branch_id;
}
```

---

## Troubleshooting

### "Permission name format is invalid"

**Cause:** Not following `resource.action` format

**Solution:** Use lowercase, dot separator, resource first:

```text
❌ Read-Employee → ✅ employees.read
❌ employee.Read → ✅ employees.read
❌ read_employee → ✅ employees.read
```

---

### "Permission already exists"

**Cause:** Duplicate permission name

**Solution:** Check existing permissions:

```bash
curl -X GET https://api.secpal.app/v1/permissions \
  -H "Authorization: Bearer YOUR_TOKEN"
```

Use different action or resource name.

---

### "Cannot delete permission while assigned"

**Cause:** Permission still in use

**Solution:**

1. Find where it's used:

```bash
GET /api/v1/permissions/43
# Check "roles_count" and "direct_users_count"
```

1. Remove from all roles:

```bash
PATCH /api/v1/roles/{id}
{
  "permissions": [...]  # without the permission
}
```

1. Revoke from all users:

```bash
DELETE /api/v1/users/{id}/permissions/{permission}
```

1. Then delete permission.

---

## Related Documentation

- [RBAC API Endpoints](../api/rbac-endpoints.md) - Complete API reference
- [Role Management Guide](role-management.md) - Creating and managing roles
- [Direct Permissions Guide](direct-permissions.md) - Assigning permissions directly
- [RBAC Architecture](../rbac-architecture.md) - System design

---

**Document Version:** 1.0.0
**Last Updated:** 2025-11-15
**Status:** Complete
**Maintainer:** @kevalyq

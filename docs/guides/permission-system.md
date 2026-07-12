<!-- SPDX-FileCopyrightText: 2025-2026 SecPal Contributors -->
<!-- SPDX-License-Identifier: CC0-1.0 -->

# Permission System Guide

Understanding SecPal's permission naming, organization, and management.

## Table of Contents

- [Permission Naming Convention](#permission-naming-convention)
- [Resource Organization](#resource-organization)
- [Customer & Site Access Model](#customer--site-access-model)
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
4. **Snake_case** - for multi-word resources (`work_instructions`); URL-aligned resources whose route path is hyphenated keep kebab-case (`cost-centers`)
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
| `assign_temporary`  | Assign temporal roles         | `role.assign`                   |
| `extend_expiration` | Extend role expiration        | `role.assign`                   |

---

## Customer & Site Access Model

Customers and Sites intentionally use a two-layer model:

1. Global collection permissions via `customers.read` and `sites.read`
2. Scoped access via active customer assignments, site assignments, and organizational scopes

### Global Access

- No predefined role receives global Customer or Site access through an implicit `Admin` shortcut.
- `Manager` receives `customers.read`, `customers.create`, `customers.update`, `sites.read`, `sites.create`, and `sites.update`.
- `HR`, `Employee`, `Employee Read Only`, `Guard`, `Client`, and `Works Council` do not receive Customer or Site module access by default.
- Custom roles can opt in explicitly by assigning the matching `customers.*` or `sites.*` permissions.

### Scoped Access

- Active customer assignments open the customer collection for the assigned customer and the site collection for all sites of that customer
- Active site assignments open the site collection for the assigned sites and the customer collection for the owning customers of those sites
- Organizational scopes open the site collection for sites in accessible organizational units and the customer collection for customers owning those sites
- Scoped collection access may legitimately return `200 OK` with an empty filtered collection when the entitlement exists but no currently matching records do

### Explicit Default Role Position

- `Guard`, `Client`, and `Works Council` do not receive Customer or Site module access by default through their predefined RBAC permissions
- If one of those users must work with Customers or Sites, grant that access explicitly through direct permissions and/or active customer, site, or organizational-scope assignments
- Do not rely on hidden UI fallbacks or mutation permissions to make the module discoverable; the frontend must mirror the backend's `hasCustomerAccess` and `hasSiteAccess` flags

### Detail And Mutation Rules

- Opening `/v1/customers` or `/v1/sites` does not imply access to every individual record; detail endpoints still require the concrete object to be in scope
- `customers.create` and `sites.create` are explicit manager or custom-role permissions
- `customers.update` and `sites.update` may also be granted through direct assignment to the concrete customer or site
- `customers.delete` and `sites.delete` remain explicit destructive permissions and are never granted through scoped visibility alone

### Expected Outcomes Matrix

| Situation                                                  | `/v1/customers`                                                          | `/v1/sites`                                                 | Detail endpoint                                  | Frontend module visibility  |
| ---------------------------------------------------------- | ------------------------------------------------------------------------ | ----------------------------------------------------------- | ------------------------------------------------ | --------------------------- |
| No global read permission and no scoped access             | `403 Forbidden`                                                          | `403 Forbidden`                                             | `403 Forbidden`                                  | Hidden / access denied      |
| Global `customers.read` only                               | `200 OK` full customer collection                                        | `403 Forbidden`                                             | Customer details allowed by global read          | Customers visible           |
| Global `sites.read` only                                   | `403 Forbidden`                                                          | `200 OK` full site collection                               | Site details allowed by global read              | Sites visible               |
| Active customer assignment only                            | `200 OK` filtered customer collection                                    | `200 OK` filtered site collection for that customer's sites | Only assigned customer and its in-scope sites    | Customers and Sites visible |
| Active site assignment only                                | `200 OK` filtered customer collection for owning customers               | `200 OK` filtered site collection                           | Only assigned site and owning customer in scope  | Customers and Sites visible |
| Organizational scope only                                  | `200 OK` filtered customer collection for customers owning visible sites | `200 OK` filtered site collection for visible units         | Only records covered by the organizational scope | Customers and Sites visible |
| Scoped entitlement exists but currently matches no records | `200 OK` empty collection                                                | `200 OK` empty collection                                   | `403 Forbidden` for unrelated concrete objects   | Visible, empty-state UX     |

---

## Permission Matrix

Representative permissions assigned to predefined roles. There is no predefined `Admin` role; broad access is assembled from explicit permissions plus explicit `manage` scopes.

### Employee Role

**Philosophy:** Self-service employee access

```text
- employee.read
- employee.update
- employee_qualification.read
- employee_document.read
- qualification.read
- shifts.read
- shifts.update
- work_instructions.read
- work_instructions.acknowledge
```

---

### Employee Read Only Role

**Philosophy:** Read-only employee self-service

```text
- employee.read
- employee_qualification.read
- employee_document.read
- qualification.read
- shifts.read
- work_instructions.read
```

---

### HR Role

**Philosophy:** HR lifecycle, compliance, and onboarding

```text
- employees.read / create / update / delete
- employees.read_sensitive / read_salary / export
- employee.read / write / activate / terminate
- employee_qualification.read / write
- employee_document.read / write
- qualification.read / write
- onboarding.read / write / approve / confirm
- reports.view / generate
```

---

### Manager Role

**Philosophy:** Operational management within explicit organizational scopes

```text
Customers:
- customers.read
- customers.create
- customers.update

Sites:
- sites.read
- sites.create
- sites.update

Assignments:
- assignments.create
- assignments.update

Cost Centers:
- cost-centers.read
- cost-centers.create
- cost-centers.update

Employees:
- employees.read
- employees.create
- employees.update
- employees.read_salary

Shifts:
- shifts.read
- shifts.create
- shifts.update
- shifts.delete
- shifts.publish

Work Instructions:
- work_instructions.read
- work_instructions.create
- work_instructions.update
- work_instructions.publish

Onboarding / Audit:
- onboarding.read
- onboarding.write
- activity_log.read
- activity_log.read_system

Reports:
- reports.view
- reports.generate
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

**Philosophy:** External stakeholder access without Customer/Site module visibility by default

```text
Customers / Sites:
- No default module permissions
- Access only when explicit direct permissions or scoped assignments are granted intentionally

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

**Add a new permission definition in code when:**

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

#### 2. Add to the code-owned catalog

```php
'employees' => [
    'read',
    'create',
    'update',
    'delete',
    'export',
],
```

#### 3. Seed or migrate the new permission

Run the relevant seeder or migration path so the permission exists in the global catalog for all tenants.

#### 4. Assign to Roles

```bash
# Add to Manager role
curl -X PATCH https://api.secpal.dev/v1/roles/2 \
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

### 1. Definition

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
PATCH /v1/roles/{id}
{
  "permissions": ["employees.read", "employees.export"]
}
```

**To User (Direct):**

```bash
POST /v1/users/{id}/permissions
{
  "permissions": ["employees.export"]
}
```

---

### 3. Revocation

**From Role:**

```bash
# Update role with reduced permissions
PATCH /v1/roles/{id}
{
  "permissions": ["employees.read"]  # removed employees.export
}
```

**From User (Direct):**

```bash
DELETE /v1/users/{id}/permissions/employees.export
```

---

### 4. Removal

Permission definitions are removed in code, then rolled out through the normal migration/seeding/deployment path. Runtime deletion through the API is intentionally unsupported.

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
 * Scope: Available only when the user has `employees.read_salary` plus explicit scope coverage for the target employees
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

1. Add the permission definition in code (for example in `RolesAndPermissionsSeeder`):

```php
'employees' => [
    'read',
    'create',
    'update',
    'delete',
    'export',
],
```

1. Seed or migrate the new permission into the global catalog, then add it to the Manager role:

```bash
PATCH /v1/roles/2
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
# Assign to roles
# Junior Manager: view only
PATCH /v1/roles/junior_manager
{
  "permissions": ["reports.view"]
}

# Senior Manager: view + generate
PATCH /v1/roles/senior_manager
{
  "permissions": ["reports.view", "reports.generate"]
}
```

---

### Example 3: Works Council Special Access

**Scenario:** Works council needs limited employee access + approval rights.

**Solution:**

```bash
# Assign to Works Council role
PATCH /v1/roles/works_council
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

**Cause:** Duplicate permission name in the code-owned permission catalog.

**Solution:** Reuse the existing permission or introduce a new code-level permission name with distinct semantics.

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

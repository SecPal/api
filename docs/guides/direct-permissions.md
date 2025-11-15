<!-- SPDX-FileCopyrightText: 2025 SecPal Authors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Developer Guide: Direct Permissions

This guide helps developers understand when and how to use **Direct Permissions** in SecPal's RBAC system.

> **TL;DR:** Direct permissions are assigned directly to users, bypassing the role system. Use sparingly for exceptional cases—roles should be your primary tool for access control.

**Related Documentation:**

- [Central RBAC Architecture](../rbac-architecture.md) - System overview and design decisions
- [Temporal Roles Guide](./temporal-roles.md) - Time-limited access patterns
- API Documentation (Issue #140) - Full API reference

## What Are Direct Permissions?

**Direct permissions** are permissions assigned directly to individual users, independent of their roles.

**Key Characteristics:**

- **Bypass Role System:** Permissions granted without role membership
- **User-Specific:** Apply only to the user they're assigned to
- **Additive:** Combine with role permissions (union, not override)
- **Independent Lifecycle:** Removing roles doesn't affect direct permissions

**Permission Inheritance Formula:**

```plaintext
User's Total Permissions = Role Permissions ∪ Direct Permissions
```

**Example:**

```plaintext
User: John (Manager role)

Role Permissions (Manager):
├── employees.view
├── employees.create
├── employees.update
└── reports.view

Direct Permissions (John):
├── employees.export  ← Direct permission
└── reports.generate  ← Direct permission

John's Total Permissions:
├── employees.view     (from Manager)
├── employees.create   (from Manager)
├── employees.update   (from Manager)
├── employees.export   (direct)
├── reports.view       (from Manager)
└── reports.generate   (direct)
```

**What Direct Permissions Are NOT:**

- ❌ **Not permission overrides** - Cannot revoke role permissions
- ❌ **Not role replacements** - Roles should be primary access mechanism
- ❌ **Not default solution** - Use roles for standard patterns

See [ADR-005 Section on Permission Model](../rbac-architecture.md#2-additive-direct-permissions-no-deny-rules) for design rationale.

## When to Use Direct Permissions?

Direct permissions solve **exceptional access scenarios** that don't fit standard role patterns.

### Use Case Matrix

| Scenario                              | Problem                              | Solution                                | Why Direct Permission?                             |
| ------------------------------------- | ------------------------------------ | --------------------------------------- | -------------------------------------------------- |
| **Temporary Exception Access**        | Guard needs export for 1 week audit  | Assign `employees.export` (temporal)    | Avoid modifying Guard role or creating custom role |
| **Project-Specific Access**           | Manager needs data import for Q4     | Assign `data.import` (temporal)         | Only this manager, only this project               |
| **Special Client Privilege**          | VIP client needs report generation   | Assign `reports.generate` (permanent)   | Exception for one client, not role-worthy          |
| **Auditor Access**                    | External auditor needs read access   | Assign multiple read permissions        | Time-limited, full audit scope                     |
| **Developer Production Debugging**    | Dev needs production logs access     | Assign `logs.view` (temporal, 2 hours)  | Emergency access without permanent role change     |
| **Compliance Officer Special Access** | CO needs employee salary view        | Assign `employees.salary.view`          | Highly sensitive, exceptional access               |
| **Manager Covering Vacation**         | Acting manager during absence        | Assign temporal Manager role            | Use role assignment, not direct permissions        |
| **Cross-Department Collaboration**    | HR manager needs IT system access    | Assign `it.systems.view` (temporal)     | Temporary cross-functional need                    |
| **Event-Based Elevation**             | Guard becomes security lead for gala | Assign `security.lead` permissions      | Time-limited elevation for specific event          |
| **Beta Feature Testing**              | User tests new reporting feature     | Assign `reports.beta.access` (temporal) | Limited testing scope before general availability  |
| **Training Access**                   | Trainer needs live system access     | Assign training permissions (temporal)  | Real data access for training purposes             |
| **Vendor/Contractor Temporary Needs** | External consultant needs task queue | Assign `tasks.view` (project duration)  | Scoped vendor access without role creation         |

### Decision Tree: Role vs. Direct Permission

```plaintext
┌─────────────────────────────────┐
│ User needs new permission       │
└────────────┬────────────────────┘
             │
             ▼
     ┌───────────────────┐
     │ Will multiple     │───YES───┐
     │ users need this?  │         │
     └───────┬───────────┘         │
             │                     │
            NO                     ▼
             │            ┌────────────────────┐
             ▼            │ Is this access     │
     ┌───────────────┐    │ pattern permanent  │
     │ Is this a     │    │ for organization?  │
     │ time-limited  │    └────────┬───────────┘
     │ exception?    │             │
     └───────┬───────┘            YES
             │                     │
          YES│NO                  ▼
             │             ┌──────────────┐
             ▼             │ CREATE ROLE  │
     ┌───────────────┐     └──────────────┘
     │ Does existing │
     │ role almost   │           NO
     │ match need?   │            │
     └───────┬───────┘            ▼
             │             ┌──────────────────┐
          NO│YES           │ Should existing  │
             │             │ role be expanded?│
             ▼             └────────┬─────────┘
     ┌────────────────────┐         │
     │ ASSIGN DIRECT      │        YES
     │ PERMISSION         │         │
     │ (Temporal)         │         ▼
     └────────────────────┘  ┌──────────────┐
             ▲               │ MODIFY ROLE  │
             │               └──────────────┘
             │
     ┌───────┴────────────┐
     │ ASSIGN DIRECT      │
     │ PERMISSION         │
     │ (Permanent/Temp)   │
     └────────────────────┘
```

### When NOT to Use Direct Permissions

❌ **Don't use direct permissions when:**

| Scenario                            | Why Not?                                 | Better Solution        |
| ----------------------------------- | ---------------------------------------- | ---------------------- |
| All guards need export access       | Standard role pattern                    | Modify Guard role      |
| Access needed long-term (6+ months) | Becomes maintenance burden               | Create or assign role  |
| 3+ users need same exception        | Pattern emerging, should be standardized | Create new role        |
| Organization-wide capability        | Not an exception                         | Create role or modify  |
| Access is part of job description   | Standard responsibility                  | Add permission to role |
| User is permanently changing role   | Job change, not exception                | Assign different role  |

## How to Use: API Examples

### Assign Direct Permission (Permanent)

**Request:**

```http
POST /v1/users/123/permissions
Content-Type: application/json
Authorization: Bearer {token}

{
  "permissions": ["employees.export", "reports.generate"]
}
```

**Response:**

```json
{
  "data": {
    "user_id": 123,
    "direct_permissions": ["employees.export", "reports.generate"],
    "assigned_at": "2025-11-11T10:00:00Z",
    "expires_at": null
  },
  "message": "Direct permissions assigned successfully"
}
```

**Controller Implementation:**

```php
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
            'expires_at' => null,
        ],
        'message' => 'Direct permissions assigned successfully',
    ], 201);
}
```

### Assign Direct Permission (Temporal)

**Request:**

```http
POST /v1/users/123/permissions
Content-Type: application/json
Authorization: Bearer {token}

{
  "permissions": ["reports.generate"],
  "valid_from": "2025-11-15T00:00:00Z",
  "valid_until": "2025-11-30T23:59:59Z",
  "reason": "Q4 reporting project access"
}
```

**Response:**

```json
{
  "data": {
    "user_id": 123,
    "direct_permissions": ["reports.generate"],
    "assigned_at": "2025-11-11T10:00:00Z",
    "valid_from": "2025-11-15T00:00:00Z",
    "valid_until": "2025-11-30T23:59:59Z",
    "reason": "Q4 reporting project access"
  },
  "message": "Temporal direct permission assigned successfully"
}
```

**Notes:**

- Timestamps must be ISO 8601 format (UTC)
- `valid_from` is optional (defaults to now)
- `valid_until` triggers automatic expiration
- `reason` is optional but recommended for audit trail

### View All Permissions (Role + Direct)

**Request:**

```http
GET /v1/users/123/permissions
Authorization: Bearer {token}
```

**Response:**

```json
{
  "data": {
    "user_id": 123,
    "roles": ["manager"],
    "permissions": {
      "via_roles": ["employees.view", "employees.create", "employees.update", "reports.view"],
      "direct": ["employees.export", "reports.generate"],
      "all": [
        "employees.view",
        "employees.create",
        "employees.update",
        "employees.export",
        "reports.view",
        "reports.generate"
      ]
    }
  }
}
```

**Breakdown:**

- `via_roles`: Permissions inherited from assigned roles
- `direct`: Permissions assigned directly to user
- `all`: Union of role and direct permissions (what user actually has)

### Revoke Direct Permission

**Request:**

```http
DELETE /v1/users/123/permissions/employees.export
Authorization: Bearer {token}
```

**Response:**

```json
{
  "data": {
    "user_id": 123,
    "revoked_permission": "employees.export",
    "remaining_direct_permissions": ["reports.generate"],
    "revoked_at": "2025-11-11T11:00:00Z"
  },
  "message": "Direct permission revoked successfully"
}
```

**Important:**

- Only revokes **direct** permission
- User retains the permission if granted via role
- Cannot revoke role-inherited permissions this way

**Example:**

```plaintext
User John (Manager role) has:
- employees.export (from Manager role)
- employees.export (direct permission)

After revoking direct permission:
→ John still has employees.export (via Manager role)
→ Only the direct assignment is removed
```

## Permission Hierarchy Explained

Understanding how direct permissions interact with role permissions is critical.

### Scenario 1: Role Permissions Only

```plaintext
User: Sarah
Roles: [Guard]

Guard Role Permissions:
├── employees.view
├── shifts.view
└── incidents.create

Sarah's Total Permissions:
├── employees.view      (via Guard)
├── shifts.view         (via Guard)
└── incidents.create    (via Guard)

Direct Permissions: []
```

Sarah has access through role membership only.

### Scenario 2: Direct Permissions Only

```plaintext
User: External Auditor
Roles: []

Direct Permissions:
├── employees.view
├── reports.view
├── audit_logs.view
└── financial_records.view

Total Permissions:
├── employees.view           (direct)
├── reports.view             (direct)
├── audit_logs.view          (direct)
└── financial_records.view   (direct)
```

Auditor has no role but can access system via direct permissions.

### Scenario 3: Both Role and Direct Permissions (Union)

```plaintext
User: Manager Lisa
Roles: [Manager]

Manager Role Permissions:
├── employees.view
├── employees.create
├── employees.update
├── reports.view
└── shifts.manage

Direct Permissions:
├── employees.export        ← Exception permission
├── reports.generate        ← Exception permission
└── financial_records.view  ← Exception permission

Lisa's Total Permissions (Union):
├── employees.view          (via Manager)
├── employees.create        (via Manager)
├── employees.update        (via Manager)
├── employees.export        (direct) ← Added
├── reports.view            (via Manager)
├── reports.generate        (direct) ← Added
├── shifts.manage           (via Manager)
└── financial_records.view  (direct) ← Added
```

**Key Insight:** Direct permissions **add** to role permissions. Lisa gets union of both sets.

### Scenario 4: Role Removed, Direct Permissions Remain

```plaintext
User: Former Manager Tom
Initially:
  Roles: [Manager]
  Direct: [employees.export]
  Total: [Manager permissions + employees.export]

After removing Manager role:
  Roles: []
  Direct: [employees.export]  ← Still has this
  Total: [employees.export]    ← Only direct permission remains
```

**Important:** Direct permissions are **independent** of roles. Removing role doesn't remove direct permissions.

**Audit Trail Example:**

```plaintext
2025-11-10 10:00 - Tom assigned Manager role
2025-11-10 10:15 - Tom given direct permission: employees.export
2025-11-11 09:00 - Tom's Manager role revoked (job change)
2025-11-11 09:00 - Tom still has employees.export (direct)
                   → Must explicitly revoke direct permission if needed
```

## Best Practices

### ✅ Do This

1. **Document Rationale**

   ```json
   {
     "permissions": ["employees.export"],
     "reason": "Audit preparation Q4 2025 - external compliance requirement"
   }
   ```

   Always include `reason` field when assigning direct permissions.

2. **Use Temporal for Temporary Access**

   ```json
   {
     "permissions": ["logs.view"],
     "valid_until": "2025-11-11T18:00:00Z",
     "reason": "Production debugging - Incident #1234"
   }
   ```

   If access is time-limited, set expiration explicitly.

3. **Review Direct Permissions Regularly**

   ```bash
   GET /v1/users?has_direct_permissions=true
   # Returns all users with direct permissions for review
   ```

   Schedule monthly/quarterly review of all direct permission assignments.

4. **Prefer Roles for Standard Patterns**

   If 2+ users need same exception → Create role instead.

5. **Track in Audit Trail**
   Every direct permission assignment should be logged with:
   - Who assigned it
   - To whom
   - Why (reason field)
   - When it expires (if temporal)

### ❌ Don't Do This

1. **Don't Assign Many Direct Permissions to One User**

   ```plaintext
   ❌ BAD:
   User has 15+ direct permissions
   → This user needs a custom role

   ✅ GOOD:
   User has 1-3 direct permissions for specific exceptions
   → Keeps direct permissions truly exceptional
   ```

2. **Don't Use Direct Permissions for Standard Access**

   ```plaintext
   ❌ BAD:
   All guards getting employees.export via direct permission
   → Should modify Guard role instead

   ✅ GOOD:
   One guard needs export for specific audit
   → Direct permission makes sense
   ```

3. **Don't Forget to Revoke When Done**

   ```plaintext
   ❌ BAD:
   Assign direct permission for project
   Project ends
   Permission never revoked
   → User has unnecessary access

   ✅ GOOD:
   Use temporal assignment with valid_until
   → Automatic expiration
   ```

4. **Don't Use Direct Permissions Instead of Role Changes**

   ```plaintext
   ❌ BAD:
   Employee promoted to manager
   Keep old role + add manager permissions via direct
   → Incorrect model of user's job

   ✅ GOOD:
   Remove old role, assign Manager role
   → Role accurately reflects job
   ```

5. **Don't Mix Permanent and Temporal Without Clear Reason**

   ```plaintext
   ❌ UNCLEAR:
   User has 3 permanent + 5 temporal direct permissions
   → Why are some permanent? Review needed.
   ✅ CLEAR:
   User has 1 permanent (documented exception)
   User has 2 temporal (active projects)
   → Each has clear justification
   ```

## Common Mistakes

### Mistake 1: Using Direct Permissions as Primary Access Mechanism

**Symptom:** Users accumulate 10+ direct permissions over time instead of being assigned appropriate roles.

**Why It's Wrong:**

- Direct permissions meant for **exceptions**
- Difficult to audit and maintain
- No clear access pattern

**Fix:**

```plaintext
Analyze user's 10 direct permissions
→ Create "Senior Manager" role with these permissions
→ Assign role to user
→ Revoke direct permissions
→ Assign role to other users needing same access
```

### Mistake 2: Not Documenting Rationale

**Symptom:** Direct permission assignments without `reason` field.

**Why It's Wrong:**

- 3 months later, nobody knows why it was assigned
- Can't determine if still needed during review
- Audit trail incomplete

**Fix:**

```json
// ❌ BAD
{
  "permissions": ["employees.salary.view"]
}

// ✅ GOOD
{
  "permissions": ["employees.salary.view"],
  "reason": "Compensation audit 2025-Q4 per compliance requirement CO-2025-089",
  "valid_until": "2025-12-31T23:59:59Z"
}
```

### Mistake 3: Forgetting to Use Temporal for Temporary Access

**Symptom:** Assigning permanent direct permissions for what's clearly temporary need.

**Why It's Wrong:**

- User keeps access indefinitely
- Manual revocation required (often forgotten)
- Security risk (unnecessary access)

**Fix:**

```json
// ❌ BAD - Permanent for debugging
{
  "permissions": ["logs.view"],
  "reason": "Debug production issue #1234"
}

// ✅ GOOD - Temporal with expiration
{
  "permissions": ["logs.view"],
  "valid_until": "2025-11-11T20:00:00Z",  // 2 hours
  "reason": "Debug production issue #1234"
}
```

### Mistake 4: Trying to Use Direct Permissions to Revoke Role Permissions

**Symptom:** User has permission via role but wants to remove it.

**Why It's Wrong:**

- Direct permissions are **additive only**
- Cannot override/deny role permissions
- This is by design (see [ADR-005](../rbac-architecture.md#2-additive-direct-permissions-no-deny-rules))

**Fix:**

```plaintext
❌ WRONG APPROACH:
User has Manager role (includes employees.delete)
Want to remove employees.delete
→ Cannot use direct permission to deny

✅ CORRECT APPROACHES:
Option 1: Create custom role without employees.delete
Option 2: Modify Manager role to remove permission
Option 3: Handle via authorization logic if truly exceptional
```

### Mistake 5: Assigning Same Direct Permission to Multiple Users

**Symptom:** 5+ users all have same direct permission assigned individually.

**Why It's Wrong:**

- Pattern emerging → should be role-based
- Maintenance burden (update 5 places)
- Defeats purpose of direct permissions

**Fix:**

```plaintext
Scenario: 5 managers all have employees.export direct permission

❌ BAD:
User 1: [Manager role] + [employees.export direct]
User 2: [Manager role] + [employees.export direct]
User 3: [Manager role] + [employees.export direct]
...

✅ GOOD:
Create "Senior Manager" role with employees.export
Assign Senior Manager to these 5 users
→ Centralized, maintainable, clear pattern
```

## Testing Direct Permissions

### Example Test Cases

```php
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

test('user has direct permission without role', function () {
    $user = User::factory()->create();
    $permission = Permission::create(['name' => 'employees.export', 'guard_name' => 'sanctum']);

    $user->givePermissionTo('employees.export');

    expect($user->hasPermissionTo('employees.export'))->toBeTrue();
    expect($user->getDirectPermissions())->toHaveCount(1);
    expect($user->roles)->toHaveCount(0);  // No role assigned
});

test('user permissions are union of role and direct', function () {
    $user = User::factory()->create();

    $role = Role::create(['name' => 'manager', 'guard_name' => 'sanctum']);
    $role->givePermissionTo(['employees.view', 'employees.create']);

    $user->assignRole('manager');
    $user->givePermissionTo('employees.export');  // Direct permission

    $allPermissions = $user->getAllPermissions()->pluck('name');

    expect($allPermissions)->toContain('employees.view');    // From role
    expect($allPermissions)->toContain('employees.create');  // From role
    expect($allPermissions)->toContain('employees.export');  // Direct
    expect($allPermissions)->toHaveCount(3);
});

test('removing role keeps direct permissions', function () {
    $user = User::factory()->create();

    $role = Role::create(['name' => 'manager', 'guard_name' => 'sanctum']);
    $role->givePermissionTo('employees.view');

    $user->assignRole('manager');
    $user->givePermissionTo('employees.export');  // Direct

    // Initially has both
    expect($user->hasPermissionTo('employees.view'))->toBeTrue();
    expect($user->hasPermissionTo('employees.export'))->toBeTrue();

    // Remove role
    $user->removeRole('manager');

    // Direct permission remains
    expect($user->hasPermissionTo('employees.view'))->toBeFalse();   // Lost with role
    expect($user->hasPermissionTo('employees.export'))->toBeTrue();  // Still has direct
});

test('revoking direct permission does not affect role permission', function () {
    $user = User::factory()->create();

    $role = Role::create(['name' => 'manager', 'guard_name' => 'sanctum']);
    $role->givePermissionTo('employees.export');

    $user->assignRole('manager');
    $user->givePermissionTo('employees.export');  // Also assigned directly

    // Has permission from both sources
    expect($user->hasPermissionTo('employees.export'))->toBeTrue();

    // Revoke direct permission
    $user->revokePermissionTo('employees.export');

    // Still has via role
    expect($user->hasPermissionTo('employees.export'))->toBeTrue();
    expect($user->getDirectPermissions())->toHaveCount(0);
});
```

## Summary

**Direct Permissions Quick Reference:**

| Aspect            | Guidance                                           |
| ----------------- | -------------------------------------------------- |
| **Purpose**       | Exceptional access not fitting role patterns       |
| **When to Use**   | 1 user, temporary, or rare exception scenarios     |
| **When Not**      | Standard patterns, multiple users, long-term       |
| **Behavior**      | Additive (union with role permissions)             |
| **Independence**  | Removing roles doesn't affect direct permissions   |
| **Best Practice** | Always document reason, prefer temporal for temp   |
| **Review**        | Monthly audit of all direct permission assignments |

**Remember:** Roles are primary, direct permissions are exceptional. When in doubt, use roles.

---

**Next Steps:**

- Read [Temporal Roles Guide](./temporal-roles.md) for time-limited access patterns
- Review [Central RBAC Architecture](../rbac-architecture.md) for system design
- See API Documentation (Issue #140) for complete endpoint reference

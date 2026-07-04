<!-- SPDX-FileCopyrightText: 2025 SecPal Contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution -->

# Developer Guide: Temporal Roles

This guide explains when and how to use **temporal (time-limited) role and permission assignments** in SecPal's RBAC system.

> **TL;DR:** Temporal assignments are **optional** and expire automatically. Use them for time-limited access like vacation coverage, projects, or emergencies. **Default is permanent**—don't make everything temporal.

**Related Documentation:**

- [Central RBAC Architecture](../rbac-architecture.md) - System overview and design decisions
- [Direct Permissions Guide](./direct-permissions.md) - User-specific permission assignments
- API Documentation (Issue #140) - Full API reference

## What Are Temporal Assignments?

**Temporal assignments** are role or permission assignments with a defined **time window** (start date and/or end date).

**Key Characteristics:**

- **Time-Limited:** Assignments have `valid_from` and/or `valid_until` timestamps
- **Optional:** Permanent assignments are the default behavior
- **Automatic Expiration:** System revokes expired assignments automatically
- **Apply to Both:** Works for both role assignments and direct permissions

**Critical Understanding:**

```plaintext
┌─────────────────────────────────────────────────────┐
│ PERMANENT IS DEFAULT, TEMPORAL IS OPTIONAL          │
│                                                     │
│ Do NOT make everything temporal by default.        │
│ Only use temporal when access has a known end date.│
└─────────────────────────────────────────────────────┘
```

**Database Schema:**

```php
// model_has_roles table (for role assignments)
Schema::table('model_has_roles', function (Blueprint $table) {
    $table->timestamp('valid_from')->nullable();    // Optional start
    $table->timestamp('valid_until')->nullable();   // Optional end
    $table->boolean('auto_revoke')->default(true);  // Auto-expire when valid_until passes
    $table->text('reason')->nullable();             // Why assigned
});

// model_has_permissions table (for direct permissions)
Schema::table('model_has_permissions', function (Blueprint $table) {
    $table->timestamp('valid_from')->nullable();
    $table->timestamp('valid_until')->nullable();
    $table->boolean('auto_revoke')->default(true);
    $table->text('reason')->nullable();
});
```

**Assignment Types:**

| Type          | `valid_from` | `valid_until` | Use Case                          |
| ------------- | ------------ | ------------- | --------------------------------- |
| **Permanent** | `null`       | `null`        | Standard employee role (default)  |
| **Scheduled** | `2025-12-01` | `2025-12-14`  | Planned vacation coverage         |
| **Active**    | `null`       | `2025-12-31`  | Project access ending at year-end |
| **Future**    | `2025-12-01` | `null`        | Role starts December (rare)       |

See [ADR-005: Temporal Assignments](../rbac-architecture.md#decision-3-temporal-assignments-are-optional) for design rationale.

## Permanent vs Temporal: The Default

**Understand the Default Behavior:**

```plaintext
When you assign a role WITHOUT temporal fields:
→ Assignment is PERMANENT
→ No expiration date
→ Requires manual revocation

This is CORRECT for:
✅ Regular employees
✅ Standard job role assignments
✅ Long-term access needs
✅ Most use cases (80%+ of assignments)
```

### Example: Permanent Assignment (Most Common)

```http
POST /v1/users/123/roles
{
  "role": "manager"
}
```

Result:

```json
{
  "data": {
    "user_id": 123,
    "role": "manager",
    "assigned_at": "2025-11-11T10:00:00Z",
    "valid_from": null,     ← Permanent
    "valid_until": null,    ← Permanent
    "is_permanent": true
  }
}
```

**User 123 is now a Manager until manually revoked. This is expected and correct.**

**When to Add Temporal Constraints:**

Only use `valid_from`/`valid_until` when:

1. Access has a **known, specific end date**
2. Access is **temporary by nature** (vacation, project, event)
3. Access is **time-bound for security** (debugging, testing)
4. Access is **trial/evaluation period**

**Wrong Usage Example:**

```http
❌ BAD - Making every assignment temporal without reason
POST /v1/users/123/roles
{
  "role": "manager",
  "valid_until": "2099-12-31T23:59:59Z"  ← 74 years in future
}
```

This is permanent access masquerading as temporal. Just use permanent assignment.

## When to Use Temporal Assignments?

Temporal assignments solve **time-bound access scenarios** where access has a clear expiration.

### Decision Matrix

| Access Type                        | Duration         | Permanent or Temporal? | Example Scenario                         |
| ---------------------------------- | ---------------- | ---------------------- | ---------------------------------------- |
| **Standard Employee Role**         | Indefinite       | **Permanent**          | Hire manager → Assign Manager role       |
| **Vacation Coverage**              | 1-2 weeks        | **Temporal**           | Acting manager while primary on vacation |
| **Project-Based Access**           | Weeks to months  | **Temporal**           | External consultant on 3-month project   |
| **Event Coverage**                 | Hours to days    | **Temporal**           | Security lead during weekend gala        |
| **Compliance/Audit Access**        | Days to weeks    | **Temporal**           | External auditor needs 2-week access     |
| **Production Debugging**           | Minutes to hours | **Temporal**           | Developer debugging production issue     |
| **Training Environment**           | Hours to days    | **Temporal**           | Trainer needs live system for workshop   |
| **Seasonal Worker**                | 3-6 months       | **Temporal**           | Holiday season temporary staff           |
| **Contractor Assignment**          | Project duration | **Temporal**           | 6-month contractor on specific project   |
| **Beta Feature Testing**           | Days to weeks    | **Temporal**           | User testing new feature before launch   |
| **Cross-Department Collaboration** | Weeks to months  | **Temporal**           | HR manager helping IT project            |
| **Acting Role During Leave**       | Weeks to months  | **Temporal**           | Acting director while director on leave  |
| **Emergency Elevation**            | Hours            | **Temporal**           | Guard elevated to lead during incident   |
| **Trial Period**                   | Days to weeks    | **Temporal**           | New feature trial for select users       |

**Rule of Thumb:**

```plaintext
If you can answer "When will this access end?" with a specific date:
→ Use temporal assignment

If the answer is "When they leave the job" or "Indefinite":
→ Use permanent assignment
```

## Use Cases Deep Dive

### Use Case 1: Vacation Coverage

**Scenario:**

- Manager Sarah going on vacation December 1-14, 2025
- Manager Tom will cover Sarah's responsibilities
- Tom needs Manager role temporarily
- Access should auto-expire when Sarah returns

**Solution:**

```http
POST /v1/users/{tom_id}/roles
Content-Type: application/json
Authorization: Bearer {token}

{
  "role": "manager",
  "valid_from": "2025-12-01T00:00:00Z",
  "valid_until": "2025-12-14T23:59:59Z",
  "auto_revoke": true,
  "reason": "Vacation coverage for Sarah (Manager) - Dec 1-14"
}
```

**Response:**

```json
{
  "data": {
    "user_id": 456,
    "role": "manager",
    "assigned_at": "2025-11-25T10:00:00Z",
    "valid_from": "2025-12-01T00:00:00Z",
    "valid_until": "2025-12-14T23:59:59Z",
    "auto_revoke": true,
    "reason": "Vacation coverage for Sarah (Manager) - Dec 1-14",
    "is_permanent": false,
    "status": "scheduled"
  },
  "message": "Temporal role assigned successfully"
}
```

**Timeline:**

```plaintext
Nov 25: Assignment created (scheduled status)
Dec 01 00:00 UTC: Tom gains Manager permissions
Dec 01-14: Tom has full Manager access
Dec 14 23:59 UTC: Tom's Manager role expires
Dec 15 00:01 UTC: System auto-revokes Tom's Manager role
```

**Key Benefits:**

- No manual revocation needed
- Sarah returns to full responsibilities automatically
- Audit trail shows temporary assignment with reason
- Can extend if Sarah's vacation extends

### Use Case 2: Project-Based Access

**Scenario:**

- External consultant hired for Q4 reporting project
- Needs report generation and export permissions
- Project runs November 1 - December 31
- Access should expire when project ends

**Solution:**

```http
POST /v1/users/{consultant_id}/permissions
Content-Type: application/json
Authorization: Bearer {token}

{
  "permissions": [
    "reports.view",
    "reports.generate",
    "reports.export",
    "data.financial.read"
  ],
  "valid_from": "2025-11-01T00:00:00Z",
  "valid_until": "2025-12-31T23:59:59Z",
  "auto_revoke": true,
  "reason": "Q4 reporting project - Contract #2025-CON-089"
}
```

**Why Direct Permissions (not role)?**

- Consultant needs specific permission set
- Not creating new role for one person
- Permissions span multiple existing roles
- Time-limited by nature

**Audit Trail:**

```plaintext
2025-11-01 00:00 - Consultant gains project permissions
2025-12-15 10:00 - Notification: "Permissions expire in 16 days"
2025-12-31 23:59 - Consultant's permissions expire
2026-01-01 00:01 - System auto-revokes all project permissions
2026-01-01 00:01 - Audit log: "Auto-revoked due to expiration - Contract #2025-CON-089"
```

### Use Case 3: Event-Based Elevation

**Scenario:**

- Company hosting weekend charity gala (Saturday Nov 16, 8pm-midnight)
- Guard John elevated to Security Lead for event
- Needs incident management and emergency response permissions
- Access only during event hours

**Solution:**

```http
POST /v1/users/{john_id}/roles
Content-Type: application/json
Authorization: Bearer {token}

{
  "role": "security_lead",
  "valid_from": "2025-11-16T20:00:00Z",  # 8pm event start
  "valid_until": "2025-11-17T02:00:00Z", # 2am (buffer after midnight end)
  "auto_revoke": true,
  "reason": "Charity Gala 2025 - Security Lead elevation for event duration"
}
```

**Response:**

```json
{
  "data": {
    "user_id": 789,
    "role": "security_lead",
    "assigned_at": "2025-11-15T10:00:00Z",
    "valid_from": "2025-11-16T20:00:00Z",
    "valid_until": "2025-11-17T02:00:00Z",
    "auto_revoke": true,
    "reason": "Charity Gala 2025 - Security Lead elevation for event duration",
    "is_permanent": false,
    "status": "scheduled",
    "duration_hours": 6
  }
}
```

**Timeline:**

```plaintext
Nov 15 10:00 - Assignment created (1.5 days before event)
Nov 16 20:00 - John gains Security Lead role
Nov 16 20:00-Nov 17 02:00 - John has elevated permissions
Nov 17 02:00 - Role expires
Nov 17 02:01 - System auto-revokes Security Lead role
              - John returns to standard Guard permissions
```

**Key Points:**

- Very short duration (6 hours)
- Precise start/end times aligned with event
- Buffer time after event end (cleanup period)
- Auto-revocation ensures no lingering elevated access

### Use Case 4: Production Debugging Emergency

**Scenario:**

- Production issue reported at 2pm
- Backend developer needs production logs access to debug
- Access should expire after 2 hours (or when issue resolved)
- Highly sensitive access, must be time-limited

**Solution:**

```http
POST /v1/users/{developer_id}/permissions
Content-Type: application/json
Authorization: Bearer {token}

{
  "permissions": [
    "logs.production.view",
    "logs.production.download",
    "metrics.production.view"
  ],
  "valid_until": "2025-11-11T16:00:00Z",  # 2 hours from now
  "auto_revoke": true,
  "reason": "Production debugging - Incident #INC-2025-1145 - API gateway 500 errors"
}
```

**Response:**

```json
{
  "data": {
    "user_id": 234,
    "direct_permissions": [
      "logs.production.view",
      "logs.production.download",
      "metrics.production.view"
    ],
    "assigned_at": "2025-11-11T14:00:00Z",
    "valid_from": null,
    "valid_until": "2025-11-11T16:00:00Z",
    "auto_revoke": true,
    "reason": "Production debugging - Incident #INC-2025-1145 - API gateway 500 errors",
    "is_permanent": false,
    "expires_in_hours": 2
  }
}
```

**Workflow:**

```plaintext
14:00 - Developer requests production access
14:00 - Access granted (valid until 16:00)
14:00-16:00 - Developer debugs issue, downloads logs
15:30 - Issue resolved and fixed
15:30 - Developer manually revokes access (good practice)
      OR
16:00 - Access auto-expires (if not manually revoked)
```

**Security Benefits:**

- Time-limited access to sensitive production data
- Audit trail with incident reference
- Auto-expiration prevents forgotten access
- 2-hour window sufficient for most debugging

### Use Case 5: Compliance Audit Access

**Scenario:**

- External compliance auditor hired for annual audit
- Needs read access to employee records, financial data, audit logs
- Audit period: 2 weeks (Dec 1-14)
- Access must expire automatically when audit ends

**Solution:**

```http
POST /v1/users/{auditor_id}/permissions
Content-Type: application/json
Authorization: Bearer {token}

{
  "permissions": [
    "employees.view",
    "employees.salary.view",
    "financial_records.view",
    "audit_logs.view",
    "compliance_reports.view"
  ],
  "valid_from": "2025-12-01T00:00:00Z",
  "valid_until": "2025-12-14T23:59:59Z",
  "auto_revoke": true,
  "reason": "Annual Compliance Audit 2025 - Auditor Jane Smith - Contract #AUD-2025-012"
}
```

**Response:**

```json
{
  "data": {
    "user_id": 567,
    "direct_permissions": [
      "employees.view",
      "employees.salary.view",
      "financial_records.view",
      "audit_logs.view",
      "compliance_reports.view"
    ],
    "assigned_at": "2025-11-25T10:00:00Z",
    "valid_from": "2025-12-01T00:00:00Z",
    "valid_until": "2025-12-14T23:59:59Z",
    "auto_revoke": true,
    "reason": "Annual Compliance Audit 2025 - Auditor Jane Smith - Contract #AUD-2025-012",
    "is_permanent": false,
    "status": "scheduled"
  }
}
```

**Why Not Create "Auditor" Role?**

- One-time external auditor (not permanent staff)
- Specific permission set for this audit scope
- Temporal by nature (2-week window)
- Direct permissions more appropriate

**Notifications:**

```plaintext
Nov 25 - Assignment created (scheduled)
Dec 01 00:00 - Auditor gains access
Dec 07 10:00 - Notification: "Audit access expires in 7 days"
Dec 13 10:00 - Notification: "Audit access expires in 24 hours"
Dec 14 23:59 - Access expires
Dec 15 00:01 - System auto-revokes all permissions
Dec 15 00:01 - Audit trail log created
```

### Use Case 6: Seasonal Worker

**Scenario:**

- Retail business hires seasonal staff for holiday rush
- Worker employed November 15 - January 15
- Needs standard employee access during employment
- Access should expire when employment ends

**Solution:**

```http
POST /v1/users/{seasonal_worker_id}/roles
Content-Type: application/json
Authorization: Bearer {token}

{
  "role": "employee",
  "valid_from": "2025-11-15T00:00:00Z",
  "valid_until": "2026-01-15T23:59:59Z",
  "auto_revoke": true,
  "reason": "Seasonal employment - Holiday season 2025/2026 - Contract #SEAS-2025-034"
}
```

**Response:**

```json
{
  "data": {
    "user_id": 890,
    "role": "employee",
    "assigned_at": "2025-11-10T10:00:00Z",
    "valid_from": "2025-11-15T00:00:00Z",
    "valid_until": "2026-01-15T23:59:59Z",
    "auto_revoke": true,
    "reason": "Seasonal employment - Holiday season 2025/2026 - Contract #SEAS-2025-034",
    "is_permanent": false,
    "status": "scheduled",
    "duration_days": 62
  }
}
```

**Key Benefits:**

- No HR action needed to revoke access on Jan 16
- Worker automatically loses access when contract ends
- Can extend if season extended
- Audit trail matches employment contract dates

## How to Implement: API Examples

### Assign Temporal Role

**Request:**

```http
POST /v1/users/123/roles
Content-Type: application/json
Authorization: Bearer {token}

{
  "role": "manager",
  "valid_from": "2025-12-01T00:00:00Z",
  "valid_until": "2025-12-14T23:59:59Z",
  "auto_revoke": true,
  "reason": "Vacation coverage for Manager Sarah"
}
```

**Response:**

```json
{
  "data": {
    "user_id": 123,
    "role": "manager",
    "assigned_at": "2025-11-25T10:00:00Z",
    "valid_from": "2025-12-01T00:00:00Z",
    "valid_until": "2025-12-14T23:59:59Z",
    "auto_revoke": true,
    "reason": "Vacation coverage for Manager Sarah",
    "is_permanent": false,
    "status": "scheduled"
  },
  "message": "Temporal role assigned successfully"
}
```

**Status Values:**

- `scheduled`: Assignment created, not yet active (valid_from is future)
- `active`: Currently valid (between valid_from and valid_until)
- `expired`: Past valid_until date, pending auto-revocation
- `revoked`: Manually revoked before expiration

### Extend Temporal Assignment

**Scenario:** Vacation extended, need to extend role assignment.

**Request:**

```http
PATCH /v1/users/123/roles/manager/extend
Content-Type: application/json
Authorization: Bearer {token}

{
  "valid_until": "2025-12-21T23:59:59Z",
  "reason": "Vacation extended by 1 week"
}
```

**Response:**

```json
{
  "data": {
    "user_id": 123,
    "role": "manager",
    "original_valid_until": "2025-12-14T23:59:59Z",
    "new_valid_until": "2025-12-21T23:59:59Z",
    "extended_by_days": 7,
    "reason": "Vacation extended by 1 week",
    "extended_at": "2025-12-10T14:00:00Z"
  },
  "message": "Temporal role extended successfully"
}
```

**Important:**

- Can only extend existing temporal assignments
- Cannot extend permanent assignments (they have no expiration)
- Extension is logged in audit trail
- New expiration date must be in future

### Revoke Before Expiration

**Scenario:** User returns early, revoke temporal role manually.

**Request:**

```http
DELETE /v1/users/123/roles/manager
Authorization: Bearer {token}

{
  "reason": "Early return from vacation"
}
```

**Response:**

```json
{
  "data": {
    "user_id": 123,
    "role": "manager",
    "assigned_at": "2025-11-25T10:00:00Z",
    "valid_until": "2025-12-14T23:59:59Z",
    "revoked_at": "2025-12-05T10:00:00Z",
    "revoked_before_expiration": true,
    "reason": "Early return from vacation"
  },
  "message": "Role revoked successfully"
}
```

**Audit Trail:**

```plaintext
2025-11-25 10:00 - Role assigned (valid Dec 1-14)
2025-12-01 00:00 - Role became active
2025-12-05 10:00 - Role manually revoked (reason: Early return)
2025-12-05 10:00 - Status: Revoked before scheduled expiration
```

### Check Active Temporal Assignments

**Request:**

```http
GET /v1/users/123/roles?include_temporal=true
Authorization: Bearer {token}
```

**Response:**

```json
{
  "data": {
    "user_id": 123,
    "roles": [
      {
        "name": "guard",
        "is_permanent": true,
        "assigned_at": "2025-01-15T10:00:00Z",
        "valid_from": null,
        "valid_until": null
      },
      {
        "name": "security_lead",
        "is_permanent": false,
        "assigned_at": "2025-11-10T10:00:00Z",
        "valid_from": "2025-11-16T20:00:00Z",
        "valid_until": "2025-11-17T02:00:00Z",
        "status": "scheduled",
        "reason": "Charity Gala 2025 - Security Lead elevation",
        "expires_in_hours": 6
      }
    ]
  }
}
```

User has 1 permanent role (guard) and 1 scheduled temporal role (security_lead).

## Expiration Handling

### Automatic Expiration Process

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
                    'reason' => $assignment->reason,
                    'expired_at' => now(),
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
<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('roles:expire')->everyMinute();
```

**Process:**

```plaintext
Every minute:
1. Query all assignments where valid_until < now() AND auto_revoke = true
2. For each expired assignment:
   a. Delete assignment from model_has_roles/model_has_permissions
   b. Create audit trail log (action = 'expired')
   c. Trigger notification (optional)
3. Log count of expired assignments
```

### What Happens During Active Session When Role Expires?

**Scenario:**

```plaintext
14:00 - User logs in with temporal Manager role (expires 15:00)
14:30 - User performing manager tasks (has permission)
15:00 - Role expires (valid_until passed)
15:01 - Scheduled command runs, revokes role
15:02 - User still has active session, tries manager action
```

**Behavior:**

```php
// Authorization check in controller/middleware
if (!$user->hasPermissionTo('managers.dashboard')) {
    abort(403, 'Unauthorized - role expired');
}
```

User's next request after expiration will fail authorization check.

**Important:** Session token remains valid, but permission checks fail.

**Best Practice:**

```php
// Frontend should periodically check permissions
setInterval(async () => {
  const response = await fetch('/v1/me');
  const user = await response.json();

  if (!user.permissions.includes('managers.dashboard')) {
    // Redirect to home or show notification
    alert('Your temporary manager access has expired');
    window.location.href = '/dashboard';
  }
}, 60000); // Check every minute
```

### Notification Schedule

**Recommended notification timing:**

| Timing                    | Type   | Purpose                                   |
| ------------------------- | ------ | ----------------------------------------- |
| **7 days before**         | Email  | "Your temporary access expires in 7 days" |
| **24 hours before**       | Email  | "Your temporary access expires tomorrow"  |
| **1 hour before**         | In-app | "Your access expires in 1 hour"           |
| **At expiration**         | Email  | "Your temporary access has expired"       |
| **When manually revoked** | Email  | "Your access was revoked early"           |

**Implementation:**

```php
// In scheduled command or event listener
if ($assignment->valid_until->diffInDays(now()) === 7) {
    Notification::send($user, new RoleExpiringIn7Days($assignment));
}

if ($assignment->valid_until->diffInHours(now()) === 24) {
    Notification::send($user, new RoleExpiringIn24Hours($assignment));
}

if ($assignment->valid_until->diffInHours(now()) === 1) {
    Notification::send($user, new RoleExpiringIn1Hour($assignment));
}

// When expired
Notification::send($user, new RoleExpired($assignment));
```

## Best Practices

### ✅ Do This

1. **Use Temporal Only When Access Has Known End Date**

   ```plaintext
   ✅ GOOD:
   "We need this access for the Q4 project (ends Dec 31)"
   → Use temporal with valid_until = 2025-12-31

   ❌ BAD:
   "We might need this for a few months, not sure"
   → Don't use temporal, use permanent + manual review
   ```

2. **Set Realistic Expiration Dates**

   ```json
   // ✅ GOOD - Specific project end date
   {
     "valid_until": "2025-12-31T23:59:59Z",
     "reason": "Q4 reporting project per contract #CON-2025-089"
   }

   // ❌ BAD - Arbitrarily far future date
   {
     "valid_until": "2099-12-31T23:59:59Z",
     "reason": "Just in case"
   }
   ```

   If you're setting expiration 10+ years in future, use permanent instead.

3. **Always Document Reason**

   ```json
   // ✅ GOOD - Clear, traceable reason
   {
     "role": "manager",
     "valid_until": "2025-12-14T23:59:59Z",
     "reason": "Vacation coverage for Sarah (Manager) - Ref: HR-VAC-2025-034"
   }

   // ❌ BAD - Vague or missing reason
   {
     "role": "manager",
     "valid_until": "2025-12-14T23:59:59Z",
     "reason": "temp access"
   }
   ```

4. **Enable Auto-Revoke for Security**

   ```json
   // ✅ GOOD - Auto-revoke enabled (default)
   {
     "role": "security_lead",
     "valid_until": "2025-11-17T02:00:00Z",
     "auto_revoke": true
   }

   // ⚠️ USE CAREFULLY - Manual revocation required
   {
     "role": "security_lead",
     "valid_until": "2025-11-17T02:00:00Z",
     "auto_revoke": false,  // Must manually revoke after expiration
     "reason": "Requires manual review before revocation - Incident #INC-1234"
   }
   ```

   Only disable `auto_revoke` if you need manual review before revocation.

5. **Plan Notification Timing**

   ```plaintext
   For assignments > 7 days:
   ✅ Notify at: 7 days, 24 hours, 1 hour, expiration

   For assignments < 7 days:
   ✅ Notify at: 24 hours, 1 hour, expiration

   For assignments < 24 hours:
   ✅ Notify at: 1 hour, expiration
   ```

### ❌ Don't Do This

1. **Don't Make Everything Temporal by Default**

   ```plaintext
   ❌ WRONG PATTERN:
   Every role assignment gets 1-year temporal constraint
   "We'll review access annually"
   → This is NOT temporal, this is poor access review process

   ✅ CORRECT APPROACH:
   Permanent assignments + scheduled access reviews
   "Permanent roles, quarterly review process"
   ```

2. **Don't Use Temporal for Permanent Staff**

   ```plaintext
   ❌ BAD:
   Hire new manager on Jan 1 (permanent position)
   Assign Manager role with valid_until = Dec 31
   "We'll renew annually"

   ✅ GOOD:
   Hire new manager on Jan 1
   Assign Manager role (permanent)
   "Will remain manager until role change or termination"
   ```

3. **Don't Set Excessively Long Durations**

   ```plaintext
   ❌ BAD:
   valid_until = 5 years from now

   ✅ GOOD:
   If access needed > 6 months → Use permanent
   If access truly ends in 5 years → Use temporal
   (But question if 5-year "temporary" is really permanent)
   ```

4. **Don't Forget Timezone Handling**

   ```plaintext
   ❌ BAD:
   valid_until = "2025-12-31 23:59:59"  // No timezone
   → Ambiguous, can cause off-by-one errors

   ✅ GOOD:
   valid_until = "2025-12-31T23:59:59Z"  // UTC
   → Explicit, no ambiguity
   ```

5. **Don't Ignore Expiration Edge Cases**

   Test scenarios:

   ```plaintext
   - Role expires during active user session
   - Role expires during long-running background job
   - Role expires on leap day (Feb 29)
   - Role expires during DST transition
   - Role extended while user has active session
   - Multiple temporal roles with different expiration dates
   ```

## Timezone Considerations

**All temporal data stored in UTC:**

```php
// Database storage
'valid_from' => '2025-12-01 00:00:00',  // UTC
'valid_until' => '2025-12-14 23:59:59', // UTC
```

**API accepts/returns ISO 8601 (with Z suffix for UTC):**

```json
{
  "valid_from": "2025-12-01T00:00:00Z",
  "valid_until": "2025-12-14T23:59:59Z"
}
```

**Expiration checking always in UTC:**

```php
// Scheduled command
$expired = DB::table('model_has_roles')
    ->where('valid_until', '<', now())  // now() returns UTC
    ->get();
```

**Frontend display should convert to user's local timezone:**

```javascript
// JavaScript frontend
const expiresAt = new Date("2025-12-14T23:59:59Z");
const localTime = expiresAt.toLocaleString(); // "12/14/2025, 6:59:59 PM EST"
```

### Edge Case: Role Expires During Active Session

```plaintext
Scenario:
- User in PST timezone (UTC-8)
- Role expires 2025-12-14T23:59:59Z (3:59:59 PM PST on Dec 14)
- User logged in at 3:00 PM PST
- At 3:59:59 PM PST, role expires
- User still has active session token

Result:
- Session remains valid (token not invalidated)
- Next permission check fails (role gone)
- User sees "Unauthorized" on next request after 4:00 PM PST
```

**Recommendation:**

```javascript
// Frontend should check expiration client-side
const rolesWithExpiry = await fetchUserRoles();
const soonToExpire = rolesWithExpiry.filter(
  (role) => role.valid_until && new Date(role.valid_until) - new Date() < 3600000 // < 1 hour
);

if (soonToExpire.length > 0) {
  showNotification(`Your ${soonToExpire[0].name} role expires soon!`);
}
```

## Testing Temporal Logic

> **Note:** The following test examples use SecPal's extended API methods (`assignRole()` with array parameters, `extendRole()`) that add temporal functionality to Spatie Permission. These are custom methods implemented in SecPal's `User` model using traits or direct database manipulation of the `model_has_roles` pivot table. Standard Spatie Permission does not support temporal assignments out of the box—this is SecPal-specific functionality.

### Test Cases

```php
use App\Models\User;
use App\Models\RoleAssignmentLog;
use Spatie\Permission\Models\Role;

test('temporal role becomes active when valid_from is reached', function () {
    $user = User::factory()->create();
    $role = Role::create(['name' => 'manager', 'guard_name' => 'sanctum']);

    // Assign role with future valid_from
    $user->assignRole([
        'role' => 'manager',
        'valid_from' => now()->addDay(),
        'valid_until' => now()->addDays(7),
    ]);

    // Currently not active
    expect($user->hasRole('manager'))->toBeFalse();

    // Travel to valid_from
    $this->travel(1)->days();

    // Now active
    expect($user->hasRole('manager'))->toBeTrue();
});

test('temporal role expires when valid_until is reached', function () {
    $user = User::factory()->create();
    $role = Role::create(['name' => 'manager', 'guard_name' => 'sanctum']);

    // Assign temporal role (valid for 1 hour)
    $user->assignRole([
        'role' => 'manager',
        'valid_until' => now()->addHour(),
    ]);

    // Currently has role
    expect($user->hasRole('manager'))->toBeTrue();

    // Travel to expiration
    $this->travel(2)->hours();

    // Run expiration command
    $this->artisan('roles:expire')->assertSuccessful();

    // Role expired and revoked
    $user->refresh();
    expect($user->hasRole('manager'))->toBeFalse();
});

test('extending temporal assignment updates valid_until', function () {
    $user = User::factory()->create();
    $role = Role::create(['name' => 'manager', 'guard_name' => 'sanctum']);

    $user->assignRole([
        'role' => 'manager',
        'valid_until' => now()->addWeek(),
    ]);

    $originalExpiry = $user->roles()->first()->pivot->valid_until;

    // Extend by 1 week
    $user->extendRole('manager', now()->addWeeks(2));

    $newExpiry = $user->roles()->first()->pivot->valid_until;

    expect($newExpiry)->toBeGreaterThan($originalExpiry);
    expect($newExpiry->diffInDays($originalExpiry))->toBe(7);
});

test('manual revocation before expiration', function () {
    $user = User::factory()->create();
    $role = Role::create(['name' => 'manager', 'guard_name' => 'sanctum']);

    $user->assignRole([
        'role' => 'manager',
        'valid_until' => now()->addWeek(),
    ]);

    expect($user->hasRole('manager'))->toBeTrue();

    // Manually revoke before expiration
    $user->removeRole('manager');

    expect($user->hasRole('manager'))->toBeFalse();

    // Audit trail should show manual revocation
    $log = RoleAssignmentLog::where('user_id', $user->id)
        ->where('action', 'revoked')
        ->first();

    expect($log)->not->toBeNull();
    expect($log->revoked_before_expiration)->toBeTrue();
});

test('auto_revoke = false prevents automatic expiration', function () {
    $user = User::factory()->create();
    $role = Role::create(['name' => 'manager', 'guard_name' => 'sanctum']);

    $user->assignRole([
        'role' => 'manager',
        'valid_until' => now()->addHour(),
        'auto_revoke' => false,  // Requires manual revocation
    ]);

    // Travel past expiration
    $this->travel(2)->hours();

    // Run expiration command
    $this->artisan('roles:expire')->assertSuccessful();

    // Role still assigned (auto_revoke = false)
    $user->refresh();
    expect($user->hasRole('manager'))->toBeTrue();
});
```

### Mocking Time in Tests

```php
use Illuminate\Support\Facades\Date;

test('time travel to test expiration', function () {
    // Freeze time at Nov 11, 2025 10:00 AM
    Date::setTestNow('2025-11-11 10:00:00');

    $user = User::factory()->create();
    $role = Role::create(['name' => 'manager', 'guard_name' => 'sanctum']);

    // Assign role expiring in 1 hour
    $user->assignRole([
        'role' => 'manager',
        'valid_until' => now()->addHour(), // 11:00 AM
    ]);

    expect($user->hasRole('manager'))->toBeTrue();

    // Travel forward 2 hours (to 12:00 PM)
    $this->travel(2)->hours();

    // Now = 12:00 PM, role expired at 11:00 AM
    $this->artisan('roles:expire')->assertSuccessful();

    $user->refresh();
    expect($user->hasRole('manager'))->toBeFalse();
});
```

### Testing Audit Trail

```php
test('expiration creates audit trail log', function () {
    $user = User::factory()->create();
    $role = Role::create(['name' => 'manager', 'guard_name' => 'sanctum']);

    $user->assignRole([
        'role' => 'manager',
        'valid_until' => now()->addHour(),
        'reason' => 'Vacation coverage',
    ]);

    // Travel to expiration
    $this->travel(2)->hours();
    $this->artisan('roles:expire')->assertSuccessful();

    // Check audit trail
    $log = RoleAssignmentLog::where('user_id', $user->id)
        ->where('action', 'expired')
        ->first();

    expect($log)->not->toBeNull();
    expect($log->reason)->toBe('Vacation coverage');
    expect($log->expired_at)->not->toBeNull();
});
```

## Summary

**Temporal Assignments Quick Reference:**

| Aspect                   | Guidance                                               |
| ------------------------ | ------------------------------------------------------ |
| **Default Behavior**     | PERMANENT (no expiration)                              |
| **When to Use**          | Access has known, specific end date                    |
| **When Not to Use**      | Permanent employees, indefinite access, standard roles |
| **Automatic Expiration** | Yes, via scheduled command (every minute)              |
| **Extending Allowed**    | Yes, via API (logged in audit trail)                   |
| **Manual Revocation**    | Yes, before expiration (logged in audit trail)         |
| **Timezone**             | All timestamps stored/processed in UTC                 |
| **Best Practice**        | Always document reason, enable auto_revoke             |
| **Review Frequency**     | Monthly audit of upcoming expirations                  |

**Remember:** Permanent is default, temporal is optional. Don't make everything temporal—only use when access has a clear, specific end date.

---

**Next Steps:**

- Read [Direct Permissions Guide](./direct-permissions.md) for user-specific permission assignments
- Review [Central RBAC Architecture](../rbac-architecture.md) for system design
- See API Documentation (Issue #140) for complete endpoint reference

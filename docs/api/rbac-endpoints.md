<!-- SPDX-FileCopyrightText: 2025-2026 SecPal -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# RBAC API Endpoints Reference

Complete API reference for SecPal's Role-Based Access Control (RBAC) system.

## Overview

SecPal's RBAC API provides four functional areas:

1. **[Role Assignment API](#role-assignment-api)** - Assign/revoke roles to/from users (Phase 3 ✅)
2. **[Role Management API](#role-management-api)** - CRUD operations for roles (Phase 4 ⏳)
3. **[Permission Management API](#permission-management-api)** - CRUD operations for permissions (Phase 4 ⏳)
4. **[Direct Permission API](#direct-permission-api)** - Assign permissions directly to users (Phase 4 ⏳)

**Base URL:** `https://api.secpal.dev/v1`

**Authentication:** All endpoints require Bearer token authentication via Laravel Sanctum.

```http
Authorization: Bearer {your_access_token}
```

**Content Type:** All requests and responses use `application/json`.

---

## Role Assignment API

### Assign Role to User

Assign a role to a user. Supports both permanent and temporal assignments.

**Endpoint:** `POST /v1/users/{user}/roles`

**Authorization:** Requires `role.assign` permission

**URL Parameters:**

- `user` (integer) - User ID

**Request Body:**

```json
{
  "role": "manager",
  "valid_from": "2025-12-01T00:00:00Z",
  "valid_until": "2025-12-14T23:59:59Z",
  "reason": "Vacation coverage for Manager A"
}
```

**Parameters:**

| Field         | Type   | Required | Description                                                    |
| ------------- | ------ | -------- | -------------------------------------------------------------- |
| `role`        | string | Yes      | Role name (e.g., `Manager`, `HR`, `regional_manager`)          |
| `valid_from`  | string | No       | ISO 8601 timestamp when role becomes active (null = immediate) |
| `valid_until` | string | No       | ISO 8601 timestamp when role expires (null = permanent)        |
| `reason`      | string | No       | Justification for role assignment (max 500 chars)              |

**Success Response (201 Created):**

```json
{
  "data": {
    "user_id": 123,
    "role": "manager",
    "valid_from": "2025-12-01T00:00:00Z",
    "valid_until": "2025-12-14T23:59:59Z",
    "assigned_at": "2025-11-15T10:00:00Z",
    "assigned_by": 1,
    "expires_in_days": 29,
    "is_temporal": true
  }
}
```

**Error Responses:**

**422 Unprocessable Entity** - Validation error

```json
{
  "message": "The role field is required.",
  "errors": {
    "role": ["The role field is required."],
    "valid_until": ["The valid until must be a valid date after valid from."]
  }
}
```

**403 Forbidden** - Insufficient permissions

```json
{
  "message": "This action is unauthorized."
}
```

**404 Not Found** - User or role not found

```json
{
  "message": "User not found."
}
```

**cURL Example:**

```bash
curl -X POST https://api.secpal.dev/v1/users/123/roles \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "role": "manager",
    "valid_from": "2025-12-01T00:00:00Z",
    "valid_until": "2025-12-14T23:59:59Z",
    "reason": "Vacation coverage"
  }'
```

**JavaScript Example:**

```javascript
const response = await fetch("https://api.secpal.dev/v1/users/123/roles", {
  method: "POST",
  headers: {
    Authorization: `Bearer ${token}`,
    "Content-Type": "application/json",
  },
  body: JSON.stringify({
    role: "manager",
    valid_from: "2025-12-01T00:00:00Z",
    valid_until: "2025-12-14T23:59:59Z",
    reason: "Vacation coverage",
  }),
});

const data = await response.json();
console.log(data);
```

---

### List User's Roles

Get all roles assigned to a user, including temporal information.

**Endpoint:** `GET /v1/users/{user}/roles`

**Authorization:** Users may view their own roles; viewing another user's roles requires `role.read`

**URL Parameters:**

- `user` (integer) - User ID

**Success Response (200 OK):**

```json
{
  "data": [
    {
      "id": 2,
      "name": "manager",
      "description": "Branch management",
      "assigned_at": "2025-11-01T10:00:00Z",
      "assigned_by": 1,
      "valid_from": null,
      "valid_until": null,
      "is_temporal": false,
      "is_active": true
    },
    {
      "id": 5,
      "name": "regional_manager",
      "description": "Manages multiple branches",
      "assigned_at": "2025-12-01T00:00:00Z",
      "assigned_by": 1,
      "valid_from": "2025-12-01T00:00:00Z",
      "valid_until": "2025-12-14T23:59:59Z",
      "is_temporal": true,
      "is_active": true,
      "expires_in_days": 29
    }
  ]
}
```

**cURL Example:**

```bash
curl -X GET https://api.secpal.dev/v1/users/123/roles \
  -H "Authorization: Bearer YOUR_TOKEN"
```

---

### Revoke Role from User

Remove a role assignment from a user.

**Endpoint:** `DELETE /v1/users/{user}/roles/{role}`

**Authorization:** Requires `role.revoke` permission

**URL Parameters:**

- `user` (integer) - User ID
- `role` (string) - Role name

**Success Response (200 OK):**

```json
{
  "message": "Role revoked successfully",
  "data": {
    "user_id": 123,
    "role": "manager",
    "revoked_at": "2025-11-15T10:00:00Z",
    "revoked_by": 1
  }
}
```

**Error Responses:**

**404 Not Found** - Role not assigned to user

```json
{
  "message": "Role not assigned to this user."
}
```

**cURL Example:**

```bash
curl -X DELETE https://api.secpal.dev/v1/users/123/roles/manager \
  -H "Authorization: Bearer YOUR_TOKEN"
```

---

### Extend Role Expiration

Extend the expiration date of a temporal role assignment.

**Endpoint:** `PATCH /v1/users/{user}/roles/{role}/extend`

**Authorization:** Requires `roles.extend_expiration` permission

**URL Parameters:**

- `user` (integer) - User ID
- `role` (string) - Role name

**Request Body:**

```json
{
  "valid_until": "2025-12-31T23:59:59Z"
}
```

**Success Response (200 OK):**

```json
{
  "data": {
    "user_id": 123,
    "role": "manager",
    "old_valid_until": "2025-12-14T23:59:59Z",
    "new_valid_until": "2025-12-31T23:59:59Z",
    "extended_by": 1,
    "extended_at": "2025-11-15T10:00:00Z"
  }
}
```

**cURL Example:**

```bash
curl -X PATCH https://api.secpal.dev/v1/users/123/roles/manager/extend \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "valid_until": "2025-12-31T23:59:59Z"
  }'
```

---

## Role Management API

### List All Roles

Get a list of all roles in the system (predefined + custom).

**Endpoint:** `GET /v1/roles`

**Authorization:** Requires `role.read` permission

**Query Parameters:**

| Parameter  | Type    | Description                                     |
| ---------- | ------- | ----------------------------------------------- |
| `page`     | integer | Page number for pagination (default: 1)         |
| `per_page` | integer | Items per page (default: 15, max: 100)          |
| `search`   | string  | Search by role name or description              |
| `sort`     | string  | Sort field: `name`, `created_at`, `users_count` |
| `order`    | string  | Sort order: `asc`, `desc` (default: `asc`)      |

**Success Response (200 OK):**

```json
{
  "data": [
    {
      "id": 1,
      "name": "Manager",
      "description": "Operational management within assigned scopes",
      "permissions_count": 18,
      "users_count": 2,
      "created_at": "2025-11-01T10:00:00Z",
      "updated_at": "2025-11-01T10:00:00Z"
    },
    {
      "id": 2,
      "name": "HR",
      "description": "HR lifecycle and onboarding operations",
      "permissions_count": 16,
      "users_count": 8,
      "created_at": "2025-11-01T10:00:00Z",
      "updated_at": "2025-11-05T14:30:00Z"
    }
  ],
  "links": {
    "first": "https://api.secpal.dev/v1/roles?page=1",
    "last": "https://api.secpal.dev/v1/roles?page=3",
    "prev": null,
    "next": "https://api.secpal.dev/v1/roles?page=2"
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 3,
    "per_page": 15,
    "to": 15,
    "total": 42
  }
}
```

**cURL Example:**

```bash
curl -X GET "https://api.secpal.dev/v1/roles?page=1&per_page=15&sort=name" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

---

### Create Role

Create a new custom role with assigned permissions.

**Endpoint:** `POST /v1/roles`

**Authorization:** Requires `roles.create` permission. Authorization is currently enforced via Laravel Policy; route-level middleware is planned as a follow-up (see Issue #161).

**Request Body:**

```json
{
  "name": "regional_manager",
  "description": "Manages multiple branches in a region",
  "permissions": [
    "employees.read",
    "employees.update",
    "shifts.read",
    "shifts.create",
    "reports.generate"
  ]
}
```

**Parameters:**

| Field         | Type   | Required | Description                                    |
| ------------- | ------ | -------- | ---------------------------------------------- |
| `name`        | string | Yes      | Unique role name (lowercase, underscores only) |
| `description` | string | No       | Human-readable description (max 255 chars)     |
| `permissions` | array  | No       | Array of permission names to assign to role    |

**Success Response (201 Created):**

```json
{
  "data": {
    "id": 6,
    "name": "regional_manager",
    "description": "Manages multiple branches in a region",
    "permissions": [
      "employees.read",
      "employees.update",
      "shifts.read",
      "shifts.create",
      "reports.generate"
    ],
    "permissions_count": 5,
    "users_count": 0,
    "created_at": "2025-11-15T10:00:00Z"
  }
}
```

**Error Responses:**

**422 Unprocessable Entity** - Validation error

```json
{
  "message": "The name has already been taken.",
  "errors": {
    "name": ["The name has already been taken."],
    "permissions.0": ["The selected permission is invalid."]
  }
}
```

**cURL Example:**

```bash
curl -X POST https://api.secpal.dev/v1/roles \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "regional_manager",
    "description": "Manages multiple branches",
    "permissions": ["employees.read", "shifts.read"]
  }'
```

---

### Get Role Details

Get detailed information about a specific role, including assigned permissions.

**Endpoint:** `GET /v1/roles/{id}`

**Authorization:** Requires `role.read` permission

**URL Parameters:**

- `id` (integer) - Role ID

**Success Response (200 OK):**

```json
{
  "data": {
    "id": 2,
    "name": "manager",
    "description": "Branch management",
    "permissions": [
      {
        "id": 5,
        "name": "employees.read",
        "description": "View employee data"
      },
      {
        "id": 6,
        "name": "employees.create",
        "description": "Create new employees"
      },
      {
        "id": 15,
        "name": "shifts.read",
        "description": "View shift plans"
      }
    ],
    "permissions_count": 15,
    "users_count": 8,
    "created_at": "2025-11-01T10:00:00Z",
    "updated_at": "2025-11-05T14:30:00Z"
  }
}
```

**cURL Example:**

```bash
curl -X GET https://api.secpal.dev/v1/roles/2 \
  -H "Authorization: Bearer YOUR_TOKEN"
```

---

### Update Role

Update a role's name, description, and/or permissions.

**Endpoint:** `PATCH /v1/roles/{id}`

**Authorization:** Requires `roles.update` permission. Authorization is currently enforced via Laravel Policy; route-level middleware is planned as a follow-up (see Issue #161).

**URL Parameters:**

- `id` (integer) - Role ID

**Request Body:**

```json
{
  "name": "senior_regional_manager",
  "description": "Senior manager overseeing multiple regions",
  "permissions": ["employees.*", "shifts.*", "work_instructions.read", "reports.generate"]
}
```

**Success Response (200 OK):**

```json
{
  "data": {
    "id": 6,
    "name": "senior_regional_manager",
    "description": "Senior manager overseeing multiple regions",
    "permissions_count": 4,
    "users_count": 3,
    "updated_at": "2025-11-15T10:30:00Z"
  }
}
```

**cURL Example:**

```bash
curl -X PATCH https://api.secpal.dev/v1/roles/6 \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "description": "Updated description",
    "permissions": ["employees.*", "shifts.*"]
  }'
```

---

### Delete Role

Delete a custom role. **Cannot delete roles that are assigned to users.**

**Endpoint:** `DELETE /v1/roles/{id}`

**Authorization:** Requires `roles.delete` permission. Authorization is currently enforced via Laravel Policy; route-level middleware is planned as a follow-up (see Issue #161).

**URL Parameters:**

- `id` (integer) - Role ID

**Success Response (200 OK):**

```json
{
  "message": "Role deleted successfully"
}
```

**Error Responses:**

**422 Unprocessable Entity** - Role is assigned to users

```json
{
  "message": "Cannot delete role while assigned to users",
  "errors": {
    "role": ["This role is currently assigned to 5 users. Remove role assignments before deleting."]
  }
}
```

**cURL Example:**

```bash
curl -X DELETE https://api.secpal.dev/v1/roles/6 \
  -H "Authorization: Bearer YOUR_TOKEN"
```

---

## Permission Management API

### List All Permissions

Get all permissions grouped by resource.

**Endpoint:** `GET /v1/permissions`

**Authorization:** Authorized via Laravel Policy. Note: Route-level middleware will be added in future release (see Issue #161).

**Query Parameters:**

| Parameter  | Type    | Description                            |
| ---------- | ------- | -------------------------------------- |
| `resource` | string  | Filter by resource (e.g., "employees") |
| `page`     | integer | Page number for pagination             |
| `per_page` | integer | Items per page (default: 50, max: 100) |

**Success Response (200 OK):**

```json
{
  "data": {
    "employees": [
      {
        "id": 5,
        "name": "employees.read",
        "description": "View employee data",
        "roles_count": 4,
        "created_at": "2025-11-01T10:00:00Z"
      },
      {
        "id": 6,
        "name": "employees.create",
        "description": "Create new employees",
        "roles_count": 2,
        "created_at": "2025-11-01T10:00:00Z"
      }
    ],
    "shifts": [
      {
        "id": 15,
        "name": "shifts.read",
        "description": "View shift plans",
        "roles_count": 5,
        "created_at": "2025-11-01T10:00:00Z"
      }
    ]
  },
  "meta": {
    "total": 42,
    "resources": ["employees", "shifts", "work_instructions", "roles", "permissions"]
  }
}
```

**cURL Example:**

```bash
curl -X GET https://api.secpal.dev/v1/permissions \
  -H "Authorization: Bearer YOUR_TOKEN"
```

---

### Create Permission

Create a new custom permission.

**Endpoint:** `POST /v1/permissions`

**Authorization:** Requires `permissions.create` permission. Authorization is currently enforced via Laravel Policy; route-level middleware is planned as a follow-up (see Issue #161).

**Request Body:**

```json
{
  "name": "employees.export",
  "description": "Export employee data to CSV/Excel"
}
```

**Parameters:**

| Field         | Type   | Required | Description                                 |
| ------------- | ------ | -------- | ------------------------------------------- |
| `name`        | string | Yes      | Permission name (format: `resource.action`) |
| `description` | string | No       | Human-readable description (max 255 chars)  |

**Success Response (201 Created):**

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

**Error Responses:**

**422 Unprocessable Entity** - Invalid permission name format

```json
{
  "message": "The name format is invalid.",
  "errors": {
    "name": ["The name must follow the format: resource.action (e.g., employees.read)"]
  }
}
```

**cURL Example:**

```bash
curl -X POST https://api.secpal.dev/v1/permissions \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "employees.export",
    "description": "Export employee data"
  }'
```

---

### Get Permission Details

Get detailed information about a specific permission.

**Endpoint:** `GET /v1/permissions/{id}`

**Authorization:** Authorized via Laravel Policy. Note: Route-level middleware will be added in future release (see Issue #161).

**URL Parameters:**

- `id` (integer) - Permission ID

**Success Response (200 OK):**

```json
{
  "data": {
    "id": 5,
    "name": "employees.read",
    "description": "View employee data",
    "roles": [
      {
        "id": 1,
        "name": "Manager"
      },
      {
        "id": 2,
        "name": "HR"
      }
    ],
    "roles_count": 4,
    "direct_users_count": 2,
    "created_at": "2025-11-01T10:00:00Z"
  }
}
```

**cURL Example:**

```bash
curl -X GET https://api.secpal.dev/v1/permissions/5 \
  -H "Authorization: Bearer YOUR_TOKEN"
```

---

### Update Permission

Update a permission's description. **Note:** Permission names are immutable for security reasons.

**Endpoint:** `PATCH /v1/permissions/{id}`

**Authorization:** Requires `permissions.update` permission. Authorization is currently enforced via Laravel Policy; route-level middleware is planned as a follow-up (see Issue #161).

**URL Parameters:**

- `id` (integer) - Permission ID

**Request Body:**

```json
{
  "description": "View employee data (includes basic info only)"
}
```

**Success Response (200 OK):**

```json
{
  "data": {
    "id": 5,
    "name": "employees.read",
    "description": "View employee data (includes basic info only)",
    "updated_at": "2025-11-15T10:30:00Z"
  }
}
```

**cURL Example:**

```bash
curl -X PATCH https://api.secpal.dev/v1/permissions/5 \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "description": "Updated description"
  }'
```

---

### Delete Permission

Delete a custom permission. **Cannot delete if assigned to any role or user.**

**Endpoint:** `DELETE /v1/permissions/{id}`

**Authorization:** Requires `permissions.delete` permission. Authorization is currently enforced via Laravel Policy; route-level middleware is planned as a follow-up (see Issue #161).

**URL Parameters:**

- `id` (integer) - Permission ID

**Success Response (200 OK):**

```json
{
  "message": "Permission deleted successfully"
}
```

**Error Responses:**

**422 Unprocessable Entity** - Permission is in use

```json
{
  "message": "Cannot delete permission while assigned to roles or users",
  "errors": {
    "permission": [
      "This permission is assigned to 3 roles and 2 users. Remove assignments before deleting."
    ]
  }
}
```

**cURL Example:**

```bash
curl -X DELETE https://api.secpal.dev/v1/permissions/43 \
  -H "Authorization: Bearer YOUR_TOKEN"
```

---

## Direct Permission API

### List User's Permissions

Get all permissions for a user, showing role-based, direct, and combined permissions.

**Endpoint:** `GET /v1/users/{user}/permissions`

**Authorization:** Users may view their own permissions; viewing another user's permissions requires `permissions.read`

**URL Parameters:**

- `user` (integer) - User ID

**Success Response (200 OK):**

```json
{
  "data": {
    "via_roles": [
      {
        "name": "employees.read",
        "role": "manager",
        "role_id": 2
      },
      {
        "name": "shifts.read",
        "role": "manager",
        "role_id": 2
      }
    ],
    "direct": [
      {
        "name": "employees.export",
        "assigned_at": "2025-11-10T10:00:00Z",
        "assigned_by": 1,
        "valid_from": null,
        "valid_until": null,
        "is_temporal": false
      },
      {
        "name": "reports.generate",
        "assigned_at": "2025-11-01T10:00:00Z",
        "assigned_by": 1,
        "valid_from": "2025-11-01T00:00:00Z",
        "valid_until": "2025-11-30T23:59:59Z",
        "is_temporal": true,
        "expires_in_days": 15
      }
    ],
    "all": ["employees.read", "shifts.read", "employees.export", "reports.generate"]
  }
}
```

**Response Structure:**

- `via_roles` - Permissions inherited from assigned roles
- `direct` - Permissions assigned directly to user
- `all` - Combined list of all unique permissions (deduplicated)

**cURL Example:**

```bash
curl -X GET https://api.secpal.dev/v1/users/123/permissions \
  -H "Authorization: Bearer YOUR_TOKEN"
```

---

### Assign Direct Permission

Assign one or more permissions directly to a user, bypassing roles.

**Endpoint:** `POST /v1/users/{user}/permissions`

**Authorization:** Requires `permissions.assign_direct` permission. Authorization is currently enforced via Laravel Policy; route-level middleware is planned as a follow-up (see Issue #161).

**URL Parameters:**

- `user` (integer) - User ID

**Request Body (Permanent):**

```json
{
  "permissions": ["employees.export", "reports.generate"]
}
```

**Request Body (Temporal):**

```json
{
  "permissions": ["reports.generate"],
  "valid_from": "2025-11-01T00:00:00Z",
  "valid_until": "2025-11-30T23:59:59Z"
}
```

**Parameters:**

| Field         | Type   | Required | Description                           |
| ------------- | ------ | -------- | ------------------------------------- |
| `permissions` | array  | Yes      | Array of permission names             |
| `valid_from`  | string | No       | ISO 8601 timestamp (null = immediate) |
| `valid_until` | string | No       | ISO 8601 timestamp (null = permanent) |

**Success Response (201 Created):**

```json
{
  "data": {
    "user_id": 123,
    "permissions": [
      {
        "name": "employees.export",
        "assigned_at": "2025-11-15T10:00:00Z",
        "is_temporal": false
      },
      {
        "name": "reports.generate",
        "assigned_at": "2025-11-15T10:00:00Z",
        "valid_until": "2025-11-30T23:59:59Z",
        "is_temporal": true
      }
    ]
  }
}
```

**cURL Example:**

```bash
curl -X POST https://api.secpal.dev/v1/users/123/permissions \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "permissions": ["employees.export", "reports.generate"]
  }'
```

---

### Revoke Direct Permission

Remove a direct permission from a user. **Does not affect role-based permissions.**

**Endpoint:** `DELETE /v1/users/{user}/permissions/{permission}`

**Authorization:** Requires `permissions.revoke_direct` permission. Authorization is currently enforced via Laravel Policy; route-level middleware is planned as a follow-up (see Issue #161).

**URL Parameters:**

- `user` (integer) - User ID
- `permission` (string) - Permission name

**Success Response (200 OK):**

```json
{
  "message": "Direct permission revoked successfully",
  "data": {
    "user_id": 123,
    "permission": "employees.export",
    "revoked_at": "2025-11-15T10:00:00Z"
  }
}
```

**Important:** This only removes direct permissions. If the user has the same permission via a role, they will still have access through that role.

**cURL Example:**

```bash
curl -X DELETE https://api.secpal.dev/v1/users/123/permissions/employees.export \
  -H "Authorization: Bearer YOUR_TOKEN"
```

---

### List Direct Permissions Only

Get only the permissions assigned directly to a user (excludes role-based permissions).

**Endpoint:** `GET /v1/users/{user}/permissions/direct`

**Authorization:** Users may view their own direct permissions; viewing another user's direct permissions requires `permissions.read`

**URL Parameters:**

- `user` (integer) - User ID

**Success Response (200 OK):**

```json
{
  "data": [
    {
      "id": 5,
      "name": "employees.export",
      "description": "Export employee data",
      "assigned_at": "2025-11-10T10:00:00Z",
      "assigned_by": 1,
      "valid_from": null,
      "valid_until": null,
      "is_temporal": false
    },
    {
      "id": 25,
      "name": "reports.generate",
      "description": "Generate reports",
      "assigned_at": "2025-11-01T10:00:00Z",
      "assigned_by": 1,
      "valid_from": "2025-11-01T00:00:00Z",
      "valid_until": "2025-11-30T23:59:59Z",
      "is_temporal": true,
      "expires_in_days": 15
    }
  ]
}
```

**cURL Example:**

```bash
curl -X GET https://api.secpal.dev/v1/users/123/permissions/direct \
  -H "Authorization: Bearer YOUR_TOKEN"
```

---

## Common Error Responses

All endpoints may return the following error responses:

### 401 Unauthorized

Missing or invalid authentication token.

```json
{
  "message": "Unauthenticated."
}
```

### 403 Forbidden

User lacks required permission for the action.

```json
{
  "message": "This action is unauthorized."
}
```

### 404 Not Found

Requested resource does not exist.

For API resource lookups, SecPal returns a stable not-found payload without Laravel model names or other framework-internal details.

```json
{
  "message": "Resource not found."
}
```

### 422 Unprocessable Entity

Validation error in request data.

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "field_name": ["Error message for this field."]
  }
}
```

### 429 Too Many Requests

Rate limit exceeded.

```json
{
  "message": "Too many requests. Please try again later."
}
```

### 500 Internal Server Error

Server error occurred.

```json
{
  "message": "Server Error"
}
```

---

## Rate Limiting

All API endpoints are rate-limited to prevent abuse:

- **Authenticated requests:** 60 requests per minute per user
- **Unauthenticated requests:** 10 requests per minute per IP

Rate limit headers are included in all responses:

```http
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 58
X-RateLimit-Reset: 1636977600
```

When rate limit is exceeded, HTTP 429 is returned with `Retry-After` header.

---

## Pagination

List endpoints support pagination:

**Query Parameters:**

- `page` - Page number (default: 1)
- `per_page` - Items per page (default: 15, max: 100)

**Response Structure:**

```json
{
  "data": [...],
  "links": {
    "first": "https://api.secpal.dev/v1/roles?page=1",
    "last": "https://api.secpal.dev/v1/roles?page=5",
    "prev": null,
    "next": "https://api.secpal.dev/v1/roles?page=2"
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 5,
    "per_page": 15,
    "to": 15,
    "total": 73
  }
}
```

---

## Versioning

API version is specified in the URL: `/v1/`

**Current Version:** v1

Breaking changes will be introduced in new versions (v2, v3, etc.). Non-breaking changes (new fields, new endpoints) may be added to existing versions.

**Deprecation Policy:** Deprecated endpoints will be supported for at least 6 months after deprecation notice.

---

## Further Reading

- [Role Management Guide](../guides/role-management.md) - How to create and manage roles
- [Permission System Guide](../guides/permission-system.md) - Permission naming and organization
- [Direct Permissions Guide](../guides/direct-permissions.md) - When and how to use direct permissions
- [Temporal Roles Guide](../guides/temporal-roles.md) - Time-limited role assignments
- [RBAC Architecture](../rbac-architecture.md) - Complete system architecture documentation

---

**Document Version:** 1.0.0
**Last Updated:** 2025-11-15
**Status:** Complete
**Maintainer:** @kevalyq

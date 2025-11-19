<!--
SPDX-FileCopyrightText: 2025 SecPal Contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Secret Sharing Guide

## Overview

SecPal's Secret Sharing system allows users to grant controlled access to their encrypted secrets (passwords, credentials, notes) to other users or roles. This guide explains how the permission model works, how to use the API, and best practices for implementation.

## Permission Levels

The system uses a hierarchical permission model with three levels:

### Permission Hierarchy: `admin > write > read`

| Permission | Can View | Can Edit | Can Delete | Can Manage Shares | Can Upload/Delete Attachments |
| ---------- | -------- | -------- | ---------- | ----------------- | ----------------------------- |
| **read**   | ✅       | ❌       | ❌         | ❌                | Download only                 |
| **write**  | ✅       | ✅       | ❌         | ❌                | ✅ Upload & Delete            |
| **admin**  | ✅       | ✅       | ✅         | ✅                | ✅ Upload & Delete            |

**Important**: The secret **owner** always has full access (equivalent to `admin` level) regardless of any shares.

## Granting Access

### Share with a Specific User

```http
POST /v1/secrets/{secret}/shares
Content-Type: application/json

{
  "user_id": "019a9b50-1234-5678-9abc-def012345678",
  "permission": "write",
  "expires_at": "2025-12-31T23:59:59Z"  // Optional
}
```

**Response** (201 Created):

```json
{
  "data": {
    "id": "019a9b50-abcd-1234-5678-ef0123456789",
    "secret_id": "019a9b50-0123-4567-89ab-cdef01234567",
    "user_id": "019a9b50-1234-5678-9abc-def012345678",
    "role_id": null,
    "permission": "write",
    "granted_by": "019a9b50-fedc-ba98-7654-321098765432",
    "granted_at": "2025-11-19T09:00:00Z",
    "expires_at": "2025-12-31T23:59:59Z"
  }
}
```

### Share with a Role

```http
POST /v1/secrets/{secret}/shares
Content-Type: application/json

{
  "role_id": 5,
  "permission": "read"
}
```

**XOR Constraint**: You **cannot** specify both `user_id` and `role_id` in the same request. Choose one.

## Revoking Access

```http
DELETE /v1/secrets/{secret}/shares/{share}
```

**Response**: 204 No Content

Access is revoked immediately. The user will no longer be able to view, edit, or access attachments for the secret.

## Viewing Shares

List all active shares for a secret:

```http
GET /v1/secrets/{secret}/shares
```

**Response** (200 OK):

```json
{
  "data": [
    {
      "id": "019a9b50-abcd-1234-5678-ef0123456789",
      "secret_id": "019a9b50-0123-4567-89ab-cdef01234567",
      "user_id": "019a9b50-1234-5678-9abc-def012345678",
      "role_id": null,
      "permission": "write",
      "granted_by": "019a9b50-fedc-ba98-7654-321098765432",
      "granted_at": "2025-11-19T09:00:00Z",
      "expires_at": null
    }
  ]
}
```

## Permission Checks

### How It Works Internally

When a user attempts to access a secret, the system checks permissions in this order:

1. **Owner Check**: Is the user the secret owner? → Grant full access
2. **Direct Share Check**: Does the user have an active (non-expired) share?
3. **Role Share Check**: Does the user belong to a role that has an active share?

If **any** of these checks pass, access is granted with the **highest** permission level available.

### Implementation Example

```php
use App\Models\Secret;

$secret = Secret::find($secretId);
$user = auth()->user();

// Check if user can read the secret
if ($secret->userHasPermission($user, 'read')) {
    // User can view
}

// Check if user can write (edit or upload attachments)
if ($secret->userHasPermission($user, 'write')) {
    // User can edit secret or manage attachments
}

// Check if user can delete or manage shares
if ($secret->userHasPermission($user, 'admin')) {
    // User has full control
}
```

### Policy Integration

Laravel policies automatically enforce these checks:

- `SecretPolicy`: Controls CRUD operations on secrets
- `SecretAttachmentPolicy`: Controls attachment upload/download/delete
- `SecretSharePolicy`: Controls share management (owner-only currently)

## Expiration Behavior

- **`expires_at: null`**: Share never expires
- **`expires_at: "2025-12-31T23:59:59Z"`**: Share expires at the specified datetime
- **After Expiration**: User immediately loses access (checked on every request)

## Role-Based Sharing

### Use Case

Grant access to all members of a team/department without managing individual user shares.

### Example Workflow

1. Create a role: `Team Managers` (via RBAC system)
2. Assign users to the role
3. Share secret with role:

   ```http
   POST /v1/secrets/{secret}/shares
   {
     "role_id": 3,
     "permission": "write"
   }
   ```

4. **All users with the `Team Managers` role can now access the secret**
5. When a user is **removed from the role**, they **immediately lose access** to the secret

### Multiple Roles

If a user belongs to multiple roles, each with different permissions for the same secret:

- **The highest permission level applies**
- Example: User has roles `Viewers` (read) and `Editors` (write) → User gets `write` access

## Best Practices

### 1. Use Appropriate Permission Levels

- **read**: For view-only access (e.g., helpdesk viewing credentials)
- **write**: For users who need to update credentials or add notes
- **admin**: For co-owners who can delete secrets or manage sharing (use sparingly)

### 2. Set Expiration Dates for Temporary Access

```json
{
  "user_id": "...",
  "permission": "read",
  "expires_at": "2025-11-25T18:00:00Z" // Contractor access expires after project
}
```

### 3. Prefer Role-Based Sharing for Teams

Instead of:

```text
❌ Share with Alice (write)
❌ Share with Bob (write)
❌ Share with Carol (write)
```

Do this:

```text
✅ Share with "Engineering Team" role (write)
   → Alice, Bob, Carol are members of the role
```

**Benefits**:

- Easier management (add/remove users from role)
- Automatic access control when team membership changes
- Scales better for large teams

### 4. Audit Share Access Regularly

```http
GET /v1/secrets/{secret}/shares
```

Review active shares periodically and revoke access that's no longer needed.

### 5. Understand Owner Privileges

- **Owners bypass all permission checks**
- Sharing a secret with yourself is redundant (but allowed)
- Only owners can currently manage shares (may change in future updates)

## Common Scenarios

### Scenario 1: Temporary Contractor Access

```http
POST /v1/secrets/019a9b50-1234-5678-9abc-def012345678/shares
{
  "user_id": "019a9b50-contractor-uuid-here",
  "permission": "read",
  "expires_at": "2025-12-01T00:00:00Z"  // Project ends Nov 30
}
```

### Scenario 2: Team Shared Password

```http
POST /v1/secrets/019a9b50-database-creds-uuid/shares
{
  "role_id": 7,  // "Backend Developers" role
  "permission": "read"
}
```

### Scenario 3: Co-Owner for Critical System

```http
POST /v1/secrets/019a9b50-root-password-uuid/shares
{
  "user_id": "019a9b50-senior-admin-uuid",
  "permission": "admin"  // Can delete secret and manage shares
}
```

### Scenario 4: Revoke Access After Employee Departure

```http
DELETE /v1/secrets/019a9b50-1234-5678-9abc-def012345678/shares/019a9b50-share-uuid
```

## API Reference Summary

| Endpoint                                | Method | Description                                    |
| --------------------------------------- | ------ | ---------------------------------------------- |
| `/v1/secrets`                           | GET    | List user's secrets (filter: all/owned/shared) |
| `/v1/secrets`                           | POST   | Create a new secret                            |
| `/v1/secrets/{secret}`                  | GET    | View secret details                            |
| `/v1/secrets/{secret}`                  | PATCH  | Update secret                                  |
| `/v1/secrets/{secret}`                  | DELETE | Soft delete secret                             |
| `/v1/secrets/{secret}/shares`           | GET    | List shares for secret                         |
| `/v1/secrets/{secret}/shares`           | POST   | Grant access to user or role                   |
| `/v1/secrets/{secret}/shares/{share}`   | DELETE | Revoke access                                  |
| `/v1/secrets/{secret}/attachments`      | GET    | List attachments                               |
| `/v1/secrets/{secret}/attachments`      | POST   | Upload attachment                              |
| `/v1/attachments/{attachment}/download` | GET    | Download attachment                            |
| `/v1/attachments/{attachment}`          | DELETE | Delete attachment                              |

## Security Considerations

1. **Encryption**: All secret data (title, username, password, notes, URLs) is encrypted at rest using tenant-specific keys
2. **Field-Level Encryption**: Each tenant has isolated encryption keys (KEK/DEK pattern)
3. **Authorization**: Every request is validated through Laravel policies
4. **Audit Trail**: `granted_by` and `granted_at` fields track who created each share
5. **Soft Deletes**: Secrets are soft-deleted, allowing recovery if needed
6. **Cascade Deletes**: When a secret is force-deleted, all shares and attachments are removed

## Future Enhancements

- **Share Management by Admins**: Currently only owners can manage shares; future versions may allow `admin` permission users to grant/revoke shares
- **Notification System**: Notify users when they receive access to a secret
- **Share Activity Log**: Track when shares are used (view/edit events)
- **Bulk Sharing**: Share multiple secrets with a role in one API call

## Troubleshooting

### "403 Forbidden" when accessing a secret

**Possible causes**:

1. You are not the owner
2. No active share exists for you or your roles
3. The share has expired (`expires_at` is in the past)
4. Your permission level is insufficient (e.g., trying to delete with `write` permission)

**Solution**: Ask the secret owner to grant you access or increase your permission level.

### Share was granted but user still can't access

**Check**:

1. Is `expires_at` set and already passed?
2. If role-based: Is the user actually assigned to the role?
3. Use `GET /v1/secrets/{secret}/shares` to verify the share exists

### User was removed from role but can still access

**This should not happen**. Role-based access is checked on every request. If this occurs:

1. Verify the user was actually removed from the role (check `model_has_roles` table)
2. Check if the user has a **direct user-based share** (not role-based)
3. Report as a bug if neither condition is true

## Code Examples

### Filter Secrets by Type

```javascript
// Get only secrets owned by the user
fetch("/v1/secrets?filter=owned");

// Get only secrets shared with the user
fetch("/v1/secrets?filter=shared");

// Get all secrets (owned + shared) - default
fetch("/v1/secrets?filter=all");
fetch("/v1/secrets"); // Same as filter=all
```

### Check Share Status Before Action

```javascript
async function editSecret(secretId) {
  // First, verify you have write permission
  const secret = await fetch(`/v1/secrets/${secretId}`).then((r) => r.json());

  // If GET succeeds, you have at least 'read' permission
  // Try to update - will return 403 if you don't have 'write'
  const response = await fetch(`/v1/secrets/${secretId}`, {
    method: "PATCH",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ title_plain: "Updated Title" }),
  });

  if (response.status === 403) {
    alert("You only have read access to this secret");
  }
}
```

## Related Documentation

- [RBAC Architecture](../rbac-architecture.md) - Role and permission system
- [Encryption Architecture](../GUARD_ARCHITECTURE.md) - KEK/DEK encryption pattern
- [API OpenAPI Spec](../api/) - Full API reference with request/response schemas

---

**Last Updated**: 2025-11-19
**Phase**: 3 (Secret Management)
**Status**: Production-ready

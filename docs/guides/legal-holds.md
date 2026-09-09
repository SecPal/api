<!-- SPDX-FileCopyrightText: 2026 SecPal Contributors -->
<!-- SPDX-License-Identifier: CC0-1.0 -->

# Legal Hold API Guide

## Purpose and safety boundary

Use a Legal Hold to preserve Activity evidence when an authorized preservation
obligation applies. This guide explains the API's operation and safe use; it is
not legal advice, and SecPal does not determine whether an obligation exists.

A Hold has the closed lifecycle `active` -> `released`. It cannot be reopened,
deleted, expired, suspended, or moved to another state. Release is an explicit,
justified action, so treat a lifecycle conflict as a business-state conflict
(`409`), not as an authorization failure.

All examples use synthetic data and the API host `https://api.secpal.dev`. Send
an authenticated request with `Authorization: Bearer <token>`; never copy a
real token into documentation, tickets, or logs.

## Authorization and tenant boundaries

Legal Hold capabilities are tenant-scoped. Assign them through SecPal's
existing permission-management model; no named role automatically has them.

| Operation            | Capability            |
| -------------------- | --------------------- |
| List and inspect     | `legal_holds.read`    |
| Create               | `legal_holds.create`  |
| Attach an Activity   | `legal_holds.attach`  |
| Detach an attachment | `legal_holds.detach`  |
| Release              | `legal_holds.release` |

Tenant context is server-authoritative. Requests never accept `tenant_id`, and
Legal Holds, Activities, and attachments cannot cross tenant boundaries. A
valid identifier for an unavailable, nonexistent, or foreign-tenant target has
the same information-poor `404` response. The API does not reveal whether a
foreign-tenant resource exists.

## Endpoint reference

| Method and path                                                    | Capability            | Success                   |
| ------------------------------------------------------------------ | --------------------- | ------------------------- |
| `GET /v1/legal-holds`                                              | `legal_holds.read`    | `200` paginated summaries |
| `POST /v1/legal-holds`                                             | `legal_holds.create`  | `201` detail              |
| `GET /v1/legal-holds/{legalHold}`                                  | `legal_holds.read`    | `200` detail              |
| `POST /v1/legal-holds/{legalHold}/attachments`                     | `legal_holds.attach`  | `201` attachment          |
| `POST /v1/legal-holds/{legalHold}/attachments/{attachment}/detach` | `legal_holds.detach`  | `200` detached attachment |
| `POST /v1/legal-holds/{legalHold}/release`                         | `legal_holds.release` | `200` released detail     |

## Create a Legal Hold

`POST /v1/legal-holds` accepts exactly `case_reference` and `justification`.
Both are trimmed, non-blank strings; `case_reference` is at most 64 characters
and a justification is at most 2,000 characters. The server owns tenant,
actor, lifecycle timestamps, and audit facts. A new Hold is `active`. A
duplicate case reference in the active tenant is a `409` conflict.

```http
POST /v1/legal-holds HTTP/1.1
Authorization: Bearer <token>
Content-Type: application/json

{
  "case_reference": "CASE-2026-001",
  "justification": "Preserve evidence for the referenced proceeding."
}
```

```json
{
  "data": {
    "id": "11111111-1111-4111-8111-111111111111",
    "case_reference": "CASE-2026-001",
    "status": "active",
    "created_at": "2026-09-09T18:00:00Z",
    "released_at": null,
    "justification": "Preserve evidence for the referenced proceeding.",
    "release_justification": null,
    "attachments": []
  }
}
```

## List and inspect Legal Holds

`GET /v1/legal-holds` returns only Holds in the active tenant. It is paginated:
`page` defaults to 1, `per_page` defaults to 15, and `per_page` cannot exceed 100. Results are ordered deterministically by `created_at DESC`, then `id
DESC`. A collection item is a summary: it omits the business justification and
attachment history.

```http
GET /v1/legal-holds?per_page=15 HTTP/1.1
Authorization: Bearer <token>
```

```json
{
  "data": [
    {
      "id": "11111111-1111-4111-8111-111111111111",
      "case_reference": "CASE-2026-001",
      "status": "active",
      "created_at": "2026-09-09T18:00:00Z",
      "released_at": null
    }
  ],
  "links": {
    "first": "https://api.secpal.dev/v1/legal-holds?page=1",
    "last": "https://api.secpal.dev/v1/legal-holds?page=1",
    "prev": null,
    "next": null
  },
  "meta": {
    "current_page": 1,
    "per_page": 15,
    "last_page": 1,
    "total": 1,
    "from": 1,
    "to": 1
  }
}
```

`GET /v1/legal-holds/{legalHold}` returns detail. It includes the business
justification, release justification where applicable, and the complete
immutable attachment history. Detached attachments remain visible. Attachment
history is ordered by `attached_at ASC`, then `id ASC`.

```http
GET /v1/legal-holds/11111111-1111-4111-8111-111111111111 HTTP/1.1
Authorization: Bearer <token>
```

```json
{
  "data": {
    "id": "11111111-1111-4111-8111-111111111111",
    "case_reference": "CASE-2026-001",
    "status": "released",
    "created_at": "2026-09-09T18:00:00Z",
    "released_at": "2026-09-10T09:00:00Z",
    "justification": "Preserve evidence for the referenced proceeding.",
    "release_justification": "The preservation obligation has ended.",
    "attachments": [
      {
        "id": "22222222-2222-4222-8222-222222222222",
        "activity_id": 4242,
        "attached_at": "2026-09-09T18:05:00Z",
        "detached_at": null,
        "detachment_justification": null
      }
    ]
  }
}
```

Resources intentionally omit tenant IDs, internal actor snapshot IDs, Activity
payloads and properties, audit internals, hash values, Merkle data,
OpenTimestamp proof material, and storage or encryption internals. An
attachment's `activity_id` is an identity reference, not embedded Activity
content.

## Attach evidence

`POST /v1/legal-holds/{legalHold}/attachments` accepts exactly one positive
integer `activity_id`. It links one Activity visible to the caller in the
active tenant; it does not duplicate the Activity payload. A duplicate active
attachment is a `409`, and a released Hold cannot receive attachments.

```http
POST /v1/legal-holds/11111111-1111-4111-8111-111111111111/attachments HTTP/1.1
Authorization: Bearer <token>
Content-Type: application/json

{
  "activity_id": 4242
}
```

```json
{
  "data": {
    "id": "22222222-2222-4222-8222-222222222222",
    "activity_id": 4242,
    "attached_at": "2026-09-09T18:05:00Z",
    "detached_at": null,
    "detachment_justification": null
  }
}
```

## Detach evidence

`POST /v1/legal-holds/{legalHold}/attachments/{attachment}/detach` accepts
exactly one non-blank `justification` (at most 2,000 characters). Detachment
does not delete the historical attachment evidence. A second detachment is a
`409`, and a released Hold cannot be mutated. After a successful commit, the
detached attachment no longer protects the Activity from normal retention.

```http
POST /v1/legal-holds/11111111-1111-4111-8111-111111111111/attachments/22222222-2222-4222-8222-222222222222/detach HTTP/1.1
Authorization: Bearer <token>
Content-Type: application/json

{
  "justification": "Evidence is no longer within the proceeding scope."
}
```

```json
{
  "data": {
    "id": "22222222-2222-4222-8222-222222222222",
    "activity_id": 4242,
    "attached_at": "2026-09-09T18:05:00Z",
    "detached_at": "2026-09-10T08:30:00Z",
    "detachment_justification": "Evidence is no longer within the proceeding scope."
  }
}
```

## Release a Legal Hold

`POST /v1/legal-holds/{legalHold}/release` accepts exactly one non-blank
`justification` (at most 2,000 characters). It is the only lifecycle
transition: `active` -> `released`. Release is explicit and justified; a
released Hold cannot be reopened or mutated. It does not immediately delete
evidence.

```http
POST /v1/legal-holds/11111111-1111-4111-8111-111111111111/release HTTP/1.1
Authorization: Bearer <token>
Content-Type: application/json

{
  "justification": "The preservation obligation has ended."
}
```

```json
{
  "data": {
    "id": "11111111-1111-4111-8111-111111111111",
    "case_reference": "CASE-2026-001",
    "status": "released",
    "created_at": "2026-09-09T18:00:00Z",
    "released_at": "2026-09-10T09:00:00Z",
    "justification": "Preserve evidence for the referenced proceeding.",
    "release_justification": "The preservation obligation has ended.",
    "attachments": []
  }
}
```

> Warning: Releasing a Hold removes its active retention protection after the
> release commits. A later retention run may process Activities already beyond
> their ordinary retention period. Verify through the authorized operational
> process that the preservation obligation has genuinely ended before release.

## Retention and hash-chain interaction

An Activity is protected from normal retention deletion only while it has an
active attachment on an active Hold. A detached attachment does not protect it.
A released Hold does not protect it after the release commits. Detachment and
release restore normal retention eligibility; neither triggers immediate
deletion. Retention processing remains separate.

Activities remain in SecPal's existing tamper-evident Activity logging
architecture. Legal Holds neither copy nor replace Activity evidence, and held
Activities remain part of the Activity hash-chain. Retention does not falsely
mark a successor orphaned merely because an expired predecessor remains held.
If normal retention actually deletes a predecessor, the existing archive and
orphaned-genesis behavior applies.

## Audit and failure atomicity

Create, attach, detach, release, and authorized failed lifecycle mutations
produce privacy-minimized lifecycle audit evidence. A successful mutation and
its required success-audit evidence commit atomically. If required audit
persistence fails, the mutation rolls back and the client does not receive a
success response. This audit evidence is not an endpoint for exposing internal
audit-event metadata, and it is not best-effort security telemetry.

## Error handling

| Status | Meaning                                                                                                                                                                  |
| ------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `401`  | Authentication is required.                                                                                                                                              |
| `403`  | The authenticated actor lacks the required Legal Hold capability.                                                                                                        |
| `404`  | The valid identifier is unavailable, nonexistent, or tenant-inaccessible; this neutral response does not reveal foreign-tenant existence.                                |
| `409`  | A valid request conflicts with current state: duplicate case reference, duplicate active attachment, Hold not active, or attachment already detached.                    |
| `422`  | Validation failed: malformed UUID, missing field, blank or overlength bounded string, invalid positive Activity ID, unsupported extra body field, or invalid pagination. |
| `429`  | Normal API rate limiting.                                                                                                                                                |
| `500`  | Neutral infrastructure or server failure. A required audit persistence failure rolls back the Legal Hold mutation and is never a successful request.                     |

Do not rely on SQL errors, stack traces, or internal exception names as API
behavior.

## Operational release checklist

Before sending a release request, verify:

- The active tenant and intended Hold are correct.
- Intended attachments and their current status have been reviewed.
- The preservation obligation has ended through the operator's authorized process.
- A precise release justification is ready.
- The operator understands that a later retention run may delete overdue Activities.

## Contract authority

The authoritative machine-readable HTTP schema is maintained in
`SecPal/contracts`. This guide explains safe operation; use the current
Contracts OpenAPI document for schema-level detail and generated clients.

<!--
SPDX-FileCopyrightText: 2026 SecPal
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Activity Logging Admin Guide

This guide explains the shipped SecPal activity logging implementation for administrators and operators.

## Operational Scope

The activity logging system already ships with:

- API list, detail, and verification endpoints
- scoped authorization via tenant, organizational-unit, and leadership filtering
- hash-chain linking per tenant
- Merkle batching for grouped verification
- OpenTimestamp submission and later Bitcoin anchoring
- retention processing with archive-plus-hard-delete behavior

## Access Model

Viewing activity logs requires `activity_log.read`.

Additional rules still apply:

- tenant isolation is mandatory
- scoped users see only activities inside their accessible organizational units
- direct activity access uses leadership-based filtering for employee-caused entries
- activities from system or non-employee causers are handled separately from employee-caused activities

For operators this means that "has the permission" is not the same as "can see every row in the tenant".

## API Endpoints

- `GET /v1/activity-logs`
- `GET /v1/activity-logs/{activity}`
- `GET /v1/activity-logs/{activity}/verify`

List responses are paginated, newest first, with `50` items per page by default and `100` as the maximum.

## Verification Data In Responses

Every activity resource can include:

- `previous_hash`
- `event_hash`
- `merkle_root`
- `merkle_batch_id`
- `merkle_proof`
- `ots_submitted_at`
- `ots_confirmed_at`
- `has_ots_proof`
- orphaned-genesis markers and timestamps

`include_verification=1` adds four computed checks per row:

- `chain_valid`
- `chain_link_valid`
- `merkle_valid`
- `ots_valid`

Use that flag sparingly on list views. It is intended for focused investigation, not for bulk browsing.

## How Integrity Processing Works

### Hash Chain

- the activity row is inserted first
- hash processing is dispatched from the `created` hook
- the processing path uses synchronized execution plus database locking to keep predecessor selection consistent per tenant
- `event_hash` is therefore not guaranteed to be present before the row has completed post-insert processing

### Merkle Batching

- activities can be grouped into Merkle batches
- the stored `merkle_proof` demonstrates inclusion in the batch root
- single-entry batches are valid and can have an empty proof because the leaf is already the root

### OpenTimestamp

- SecPal submits Merkle roots, not raw personal data, to OpenTimestamps
- `ots_submitted_at` means the proof has been sent or recorded for further upgrade
- `ots_confirmed_at` means Bitcoin confirmation is available
- OpenTimestamp proof handling is documented in more detail in [OpenTimestamp Integration](OPENTIMESTAMP_INTEGRATION.md)

## Retention Operations

Use the retention command:

```bash
php artisan activity:apply-retention
php artisan activity:apply-retention --dry-run
php artisan activity:apply-retention --tenant=123
```

### Retention Model

Retention is based on legal duration per `log_name`, not on different security levels.

- 3 years: operational, security, authentication, RBAC, scope, customer, site, employee, HR, works-council, sensitive-access, and guard-book categories
- 8 years: invoice and payment related categories plus `contract_change`
- 10 years: `annual_closing`

The cutoff uses calendar-year retention. Example:

- activity created on `2022-03-15`
- 3-year retention applies through the end of `2025`
- deletion becomes eligible from `2026-01-01 00:00:00`

### What The Command Does

For each eligible activity it:

1. stores only minimal forensic data in `activity_log_archive`
2. marks the successor as orphaned genesis when a predecessor link is removed
3. hard deletes the original activity row

This is intentional. There is no soft-delete grace period for retained activity rows.

## Archive Model

`activity_log_archive` keeps only the minimal verification set:

- original activity ID
- tenant ID
- log category
- original timestamp
- `event_hash`
- `previous_hash`
- `merkle_root`
- `merkle_batch_id`

It does not retain descriptions, change properties, subject identifiers, causer identifiers, or other personal context.

## Operational Caveats

- A `null` verification field does not automatically mean tampering. It can mean the relevant forensic step has not completed or no proof is available yet.
- Orphaned genesis is an expected post-retention state, not necessarily corruption.
- Documentation and operational examples should use `getRetentionYearsForLogType()` or `getAllRetentionYears()` terminology.
- The source of truth for log-category retention is the `Activity` model, not outdated draft notes or old queue/command comments.

## Recommended Admin Checks

- verify synchronous hash-chain generation in the request path for missing `event_hash`/`previous_hash`, and verify queue processing for Merkle batching and OpenTimestamp jobs when queued forensic fields stop appearing
- use `--dry-run` before large retention runs
- investigate unexpected `false` verification results before assuming client or UI issues
- treat cross-tenant or cross-scope visibility complaints as authorization questions first, not as missing-data questions

## Related Documents

- [Activity Logging User Guide](ACTIVITY_LOGGING_USER_GUIDE.md)
- [Activity Logging Legal Guide](ACTIVITY_LOGGING_LEGAL_GUIDE.md)
- [OpenTimestamp Integration](OPENTIMESTAMP_INTEGRATION.md)
- [Queue Workers](QUEUE_WORKERS.md)

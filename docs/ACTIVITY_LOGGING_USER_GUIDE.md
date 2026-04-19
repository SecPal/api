<!--
SPDX-FileCopyrightText: 2026 SecPal
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Activity Logging User Guide

This guide explains how SecPal users can view and verify the shipped activity log from the API and frontend audit UI.

## Who Can Use It

- You need the `activity_log.read` permission.
- Tenant isolation always applies. You can only see activities from your own tenant.
- If your account uses organizational scopes, the list view only includes activities from your accessible organizational units.
- Leadership filtering applies to activities caused by employees. In scoped views, you only see activities from users whose management level falls into your allowed view range.

## What The Activity Log Shows

Each entry can include:

- when the event happened
- the log category (`log_name`)
- a human-readable description
- the affected subject and the causer, when available
- request metadata such as IP address and user agent
- forensic metadata such as hash-chain, Merkle, and OpenTimestamp fields

The frontend activity-log screen is backed by these API endpoints:

- `GET /v1/activity-logs`
- `GET /v1/activity-logs/{activity}`
- `GET /v1/activity-logs/{activity}/verify`

## Available Filters

`GET /v1/activity-logs` supports these user-facing filters:

- `from_date` and `to_date` for date ranges
- `log_name` for exact log-category matching
- `search` for description search
- `organizational_unit_id`
- `causer_type` and `causer_id`
- `subject_type` and `subject_id`
- `per_page` from `1` to `100`
- `include_verification` to attach verification results to each listed row

Notes:

- date filters accept calendar dates; `to_date` must not be earlier than `from_date`
- description search is SQL-escaped, so literal backslashes, `%`, and `_` are treated consistently instead of widening the search unexpectedly
- `include_verification=1` is more expensive than the default list view because it triggers cryptographic verification work per returned activity

## Typical Review Flow

1. Open the activity log list.
2. Narrow the time range and log category first.
3. Add causer or subject filters if you are investigating a specific person or record.
4. Open the individual activity when you need the full subject, causer, and forensic fields.
5. Use the verification endpoint or UI action when you need integrity evidence for a single entry.

## Understanding Verification Results

Verification can report four signals:

- `chain_valid`: the activity still fits into the tenant hash chain
- `chain_link_valid`: the direct predecessor link is valid for this record
- `merkle_valid`: the activity still matches its stored Merkle proof and root
- `ots_valid`: the OpenTimestamp proof verifies successfully

Important behavior:

- verification values can be `null` when required forensic data is not present yet
- newly created entries may not have a stable `event_hash` immediately at first render because hash processing happens after insert
- an entry marked as orphaned genesis can still verify as valid when its predecessor was legitimately removed by retention processing

## What Users Should Not Expect

- The activity log is read-only. The API does not provide edit or delete operations for individual entries.
- Scoped users do not get tenant-wide visibility just because they can open the activity log.
- The list endpoint is not intended to replace legal exports or full forensic reviews.

## When To Escalate

Escalate to an administrator or compliance reviewer when:

- an expected activity is missing from your scoped view
- verification returns `false` for chain, Merkle, or OpenTimestamp checks
- you need evidence beyond normal UI review, such as retention or blockchain-anchoring details

## Related Documents

- [Activity Logging Admin Guide](ACTIVITY_LOGGING_ADMIN_GUIDE.md)
- [Activity Logging Legal Guide](ACTIVITY_LOGGING_LEGAL_GUIDE.md)
- [OpenTimestamp Integration](OPENTIMESTAMP_INTEGRATION.md)

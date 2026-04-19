<!--
SPDX-FileCopyrightText: 2026 SecPal
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Activity Logging Legal Guide

This guide summarizes the shipped SecPal activity logging behavior for legal, audit, and compliance review.

## Purpose Of The System

SecPal activity logging is designed to provide:

- tenant-scoped auditability for operational and security events
- tamper-evident integrity signals through hash chaining and Merkle proofs
- optional external timestamp anchoring through OpenTimestamps and Bitcoin
- legally bounded retention with data minimization after the retention period ends

It is not a blanket promise that every log remains readable forever. The design explicitly balances forensic verification with GDPR storage limitation.

## What Is Retained In Active Logs

While an activity remains inside `activity_log`, the API can expose:

- description text
- subject and causer identifiers
- change properties
- IP address and user agent
- hash-chain, Merkle, and OpenTimestamp fields

This allows internal audit and operations teams to investigate the event in context during the lawful retention period.

## Integrity Layers

### 1. Hash Chain

Each activity can store:

- `previous_hash`
- `event_hash`

This allows later proof that a record still matches the expected sequence for its tenant. Activities without a predecessor are genesis entries.

### 2. Merkle Proofs

Activities may also carry:

- `merkle_root`
- `merkle_batch_id`
- `merkle_proof`

This provides batch-level inclusion evidence even when many activities are processed together.

### 3. OpenTimestamp / Bitcoin Anchoring

SecPal can submit Merkle roots to OpenTimestamps. The relevant activity fields are:

- `ots_submitted_at`
- `ots_confirmed_at`
- `ots_proof`

OpenTimestamp submission anchors digests, not the underlying personal data, to public timestamp infrastructure.

## Retention Model

Retention is driven by legal duration per activity category.

### 3-Year Categories

Operational and security categories, including authentication, RBAC, organizational scope changes, employee changes, HR access, works-council access, sensitive access, and guard-book-related entries, default to 3 years.

### 8-Year Categories

`invoice_generated`, `payment_processed`, and `contract_change` use 8-year retention.

### 10-Year Categories

`annual_closing` uses 10-year retention.

### Calendar-Year Cutoff

SecPal uses calendar-year retention boundaries. A log is retained through the end of the relevant retention year and becomes eligible for deletion on the following day.

Example:

- creation date: `2022-03-15`
- retention: 3 years
- retained through: `2025-12-31`
- eligible for deletion from: `2026-01-01`

## GDPR-Oriented Archive And Deletion Design

When retention expires, SecPal does not keep the full personal-data-bearing activity row.

Instead it:

1. copies minimal forensic fields into `activity_log_archive`
2. removes the original activity with a hard delete
3. marks any successor whose predecessor was removed as orphaned genesis

The archive intentionally retains only:

- activity ID
- tenant ID
- log category
- original timestamp
- event hash
- previous hash
- Merkle root
- Merkle batch ID

The archive intentionally excludes:

- description text
- `properties`
- subject references
- causer references
- request metadata such as IP address and user agent

This supports the GDPR storage-limitation principle while still preserving limited integrity evidence.

## Orphaned Genesis Meaning

An orphaned genesis marker indicates that a predecessor link disappeared because retention removed an older record, not because the chain was silently corrupted.

That is why:

- `is_orphaned_genesis = true` can still be a valid state
- chain-link verification can legitimately succeed after the predecessor was archived and deleted

For legal review, orphaned genesis should be interpreted as a documented retention-boundary artifact.

## Verification Semantics

The API verification endpoint returns four checks:

- `chain_valid`
- `chain_link_valid`
- `merkle_valid`
- `ots_valid`

Interpretation guidance:

- `true`: the currently available verification path checks out
- `false`: a verification failure was detected and should be investigated
- `null`: the relevant verification data is not available yet or is not applicable in that state

`null` is not evidence of tampering by itself.

## What This Guide Intentionally Does Not Claim

- It does not claim qualified eIDAS timestamps.
- It does not claim indefinite retention of full audit content.
- It does not claim tenant-wide visibility for every privileged reader; scope and leadership controls still constrain access.

## Related Legal And Technical References

- BewachV §21 Abs. 4
- HGB §257
- AO §147
- GDPR Art. 5(1)(e)
- GDPR Art. 17
- [OpenTimestamp Integration](OPENTIMESTAMP_INTEGRATION.md)
- [Activity Logging Admin Guide](ACTIVITY_LOGGING_ADMIN_GUIDE.md)

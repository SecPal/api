<!--
SPDX-FileCopyrightText: 2025 SecPal Contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<!-- markdownlint-disable MD034 MD036 MD051 -->

# Activity Logging User Guide

**Version:** 1.0
**Last Updated:** December 27, 2025
**Target Audience:** End Users, Employees

---

## 📋 Table of Contents

1. [Introduction](#introduction)
2. [Viewing Activity Logs](#viewing-activity-logs)
3. [Filtering Logs](#filtering-logs)
4. [Understanding Log Types](#understanding-log-types)
5. [GDPR Rights & Data Privacy](#gdpr-rights--data-privacy)
6. [Frequently Asked Questions](#frequently-asked-questions)

---

## Introduction

SecPal implements comprehensive activity logging to ensure transparency, security, and legal compliance. This system records important actions performed within the application, including:

- **CRUD operations** (Create, Read, Update, Delete)
- **Authentication events** (Logins, logouts, failed attempts)
- **Permission changes** (Role assignments, scope modifications)
- **Access to sensitive data** (HR data, salaries, contracts)

All logs are **tamper-proof** using cryptographic hashing and blockchain-anchored timestamps (OpenTimestamp).

### Why Activity Logging?

1. **Transparency:** Users can see who accessed or modified their data
2. **Security:** Detect unauthorized access attempts and security breaches
3. **Compliance:** Fulfill legal requirements (BewachV §21, GDPR Article 30)
4. **Accountability:** Provide evidence for audits and court proceedings

---

## Viewing Activity Logs

> **⚠️ NOTE:** The web-based Activity Log viewer is not yet implemented (Issue #394).
> This guide describes the **planned user interface** for when the feature is released.
> Currently, administrators can query logs directly via Laravel Tinker or database queries (see Admin Guide).

### Accessing Your Personal Logs (Planned)

Once the Activity Log viewer is implemented (Issue #394), users will be able to:

1. Navigate to **Settings** → **Privacy & Security** → **Activity Logs**
2. View a chronological list of activities related to their account:
   - Actions YOU performed (e.g., updated your profile)
   - Actions OTHERS performed on YOUR data (e.g., manager assigned you a role)
3. Each log entry will show:
   - **Timestamp:** When the action occurred
   - **Event Type:** What was done (e.g., `employee_updated`, `role_assigned`)
   - **Actor:** Who performed the action
   - **Description:** Human-readable summary
   - **Verification Status:** ✅ Verified (hash chain + Merkle + Bitcoin) or ⏳ Pending

### Accessing Organization Logs (Planned - Managers Only)

Managers with appropriate permissions will be able to view broader organizational activity logs:

1. Navigate to **Administration** → **Audit Trail**
2. Select organizational unit scope (if applicable)
3. View aggregated logs for employees, operations, and system events

**Note:** Access is restricted by organizational hierarchy (ADR-009). You can only view logs within your permitted scope. Some logs may be **inheritance-blocked** by lower organizational units.

---

## Filtering Logs (Planned)

> **⚠️ NOTE:** Filtering functionality will be available once Issue #394 is implemented.

When the Activity Log viewer is released, users will be able to filter logs:

### Planned Filters

| Filter                  | Description                     | Example                                        |
| ----------------------- | ------------------------------- | ---------------------------------------------- |
| **Date Range**          | Show logs between two dates     | Last 7 days, Last month, Custom                |
| **Event Type**          | Filter by log category          | Authentication, RBAC Changes, Employee Changes |
| **Actor**               | Show actions by specific user   | "Manager: John Doe"                            |
| **Subject**             | Show actions on specific entity | "Employee: Jane Smith"                         |
| **Verification Status** | Show only verified logs         | ✅ Verified, ⏳ Pending, ❌ Unverified         |

### Example Use Cases (Planned)

**Use Case 1: Check who changed my shift schedule**

- Filter: **Subject** = "My Name"
- Filter: **Event Type** = "Shift Management"
- Date Range: Last 30 days

**Use Case 2: Review recent login attempts**

- Filter: **Event Type** = "Authentication"
- Filter: **Subject** = "My Username"
- Date Range: Last 7 days

---

## Understanding Log Types

SecPal categorizes activity logs into **3 security levels** based on retention and verification requirements:

### Level 1: Standard Activity Logs (1 Year Retention)

**Log Types:**

- `default` - General system activities
- `employee_changes` - Employee profile updates (non-sensitive fields)
- `shift_management` - Shift assignments, schedule changes

**Retention:**

- Stored for **1 year**
- Soft-deleted after 1 year (recoverable for audit)
- Hard-deleted after **2 years** (permanent removal)

**Verification:**

- Hash chain (tamper detection)
- No Merkle tree or Bitcoin anchoring

---

### Level 2: Security-Critical Logs (3 Years Retention)

**Log Types:**

- `authentication` - Login, logout, failed login attempts
- `rbac_changes` - Role assignments, permission grants/revokes
- `scope_changes` - Organizational scope modifications
- `security` - Security-related events

**Retention:**

- Stored for **3 years**
- Archived after 3 years (hash-only, personal data removed)
- Hard-deleted after **5 years total**

**Verification:**

- Hash chain (tamper detection)
- Merkle tree (hourly batching)
- No Bitcoin anchoring

---

### Level 3: Legal-Critical Logs (7 Years Permanent Retention)

**Log Types:**

- `hr_access` - Access to sensitive HR data (salary, contracts, disciplinary records)
- `breaking_glass` - Emergency access usage
- `works_council_access` - Works Council data access (BetrVG compliance)
- `contract_change` - Customer contract modifications

**Retention:**

- **Permanent** (7 years minimum, no automatic deletion)
- Full cryptographic verification trail

**Verification:**

- Hash chain (tamper detection)
- Merkle tree (daily batching)
- **Bitcoin anchoring** via OpenTimestamp (legally admissible proof)

---

## GDPR Rights & Data Privacy

### Your Rights Under GDPR

#### 1. Right of Access (Article 15)

You can request a copy of all activity logs related to your account:

**How to request:**

1. Navigate to **Settings** → **Privacy** → **Data Export**
2. Select **Activity Logs** export
3. Receive JSON/CSV file within 30 days

---

#### 2. Right to Rectification (Article 16)

If you believe an activity log is **inaccurate**, you can request correction:

**Important:**

- Activity logs are **immutable by design** (tamper-proof)
- You CANNOT edit or delete historical logs
- You CAN add a **comment/annotation** to clarify or dispute a log entry

**How to dispute a log:**

1. Contact your manager or data protection officer
2. Provide log ID and explanation
3. A clarification comment will be appended to the log entry

---

#### 3. Right to Erasure ("Right to be Forgotten") (Article 17)

**Personal data** in activity logs is automatically deleted according to retention policies:

| Security Level | Retention Period  | What's Deleted         | What's Retained                       |
| -------------- | ----------------- | ---------------------- | ------------------------------------- |
| **Level 1**    | 1 year → 2 years  | All log content        | Nothing (hard delete)                 |
| **Level 2**    | 3 years → 5 years | Personal data          | Cryptographic hashes only             |
| **Level 3**    | 3 years (BewachV) | Personal data after 3y | Cryptographic hashes for verification |

**Important:**

- Cryptographic hashes (Level 2+3) are **NOT considered personal data** (GDPR Recital 26)
- Hashes enable **tamper verification** without revealing personal information
- Level 3 logs: Personal data deleted after 3 years (BewachV §21 Abs. 4), hashes retained for legal verification

**How to request early deletion:**

- Only possible for Level 1 logs in special cases (e.g., data breach, identity theft)
- Contact data protection officer with justification

---

#### 4. Right to Data Portability (Article 20)

You can export your activity logs in machine-readable format:

**Supported Formats:**

- **JSON** (recommended for technical users, includes all metadata)
- **CSV** (for spreadsheet import)
- **PDF** (human-readable summary)

**What's included:**

- Timestamp, event type, description
- Actor (who performed the action)
- Subject (what was affected)
- Properties (changed fields, old/new values)
- Verification data (hashes, Merkle proof, OpenTimestamp proof)

---

### Data Minimization (GDPR Article 5)

SecPal implements **automated data minimization**:

1. **Level 1 logs:** Personal data deleted after 2 years
2. **Level 2 logs:** Personal data archived after 3 years (hashes retained)
3. **Level 3 logs:** Retained for legal minimum (7 years)

**Why we retain cryptographic hashes:**

- **Legal compliance:** BewachV §21 requires tamper-proof records
- **Accountability:** GDPR Article 5(2) requires proof of compliance
- **Verification:** Enables court proceedings without storing personal data

---

## Frequently Asked Questions

### General Questions

**Q: Can I delete my activity logs?**
**A:** No. Activity logs are **immutable** and required for legal compliance (BewachV §21, GDPR Article 30). However, personal data is automatically deleted according to retention policies. Only cryptographic hashes are retained long-term.

**Q: Who can see my activity logs?**
**A:**

- **You** can see logs related to YOUR account
- **Your manager** can see logs within their organizational scope
- **Admins** with appropriate permissions can see broader logs
- **No one** can see inheritance-blocked logs from other organizational units

**Q: How do I know if a log was tampered with?**
**A:** Look for the verification status icon:

- ✅ **Verified:** Hash chain + Merkle tree + Bitcoin anchoring passed
- ⏳ **Pending:** Bitcoin confirmation pending (up to 24 hours)
- ❌ **Unverified:** Tampering detected or integrity check failed

---

### Technical Questions

**Q: What is a "hash chain"?**
**A:** A hash chain links each log entry to the previous one using cryptographic hashing (SHA256). If anyone tries to modify a log entry, the chain breaks, and tampering is immediately detected. Think of it like a blockchain for audit logs.

**Q: What is "OpenTimestamp"?**
**A:** OpenTimestamp is a service that anchors cryptographic proofs to the Bitcoin blockchain. This provides **immutable proof** that a log existed at a specific time. This is especially important for Level 3 logs (legal proceedings, court admissibility).

**Q: Why are some logs marked as "orphaned genesis"?**
**A:** When older logs are deleted due to retention policies, the **next remaining log** in the chain is marked as "orphaned genesis." This means:

- The predecessor was **legitimately deleted** (not tampered with)
- The hash chain continues **correctly** from this point forward
- This is **by design** and does NOT indicate tampering

**Q: Can I export logs with OpenTimestamp proofs?**
**A:** Yes. When you export activity logs, OpenTimestamp proofs are included in the export file. You can independently verify these proofs using the official OpenTimestamp tools (see Legal Verification Guide).

---

### Troubleshooting

**Q: I see a log I don't recognize. What should I do?**
**A:**

1. Check the **Actor** field - was it performed by a manager or admin?
2. Check the **Description** - does it reference an automated system process?
3. If still unclear, contact your manager or IT support

**Q: Why can't I see logs older than 1/3 years?**
**A:** Logs are automatically deleted according to retention policies (GDPR Article 5, BewachV § 21). Level 1 logs are deleted after 2 years, Level 2 after 5 years, Level 3 after 3 years. Cryptographic hashes are retained for verification purposes (GDPR-compliant as no personal data).

**Q: What if I notice suspicious activity in my logs?**
**A:**

1. **Immediately** report to your manager or IT security team
2. **Do not** log out or change passwords yet (preserve evidence)
3. Provide the **Log ID** and timestamp of suspicious entries
4. Security team will investigate and verify log integrity

---

## Additional Resources

- **Admin Guide:** See [ACTIVITY_LOGGING_ADMIN_GUIDE.md](./ACTIVITY_LOGGING_ADMIN_GUIDE.md) for management instructions
- **Legal Verification Guide:** See [ACTIVITY_LOGGING_LEGAL_GUIDE.md](./ACTIVITY_LOGGING_LEGAL_GUIDE.md) for court procedures
- **ADR-010:** See [20251221-activity-logging-audit-trail-strategy.md](../.github/docs/adr/20251221-activity-logging-audit-trail-strategy.md) for technical architecture
- **GDPR Compliance:** See [DSGVO.md](../LICENSES/DSGVO.md) (German) or [GDPR.md](../LICENSES/GDPR.md) (English)

---

**Support Contact:**

- **Email:** support@secpal.app
- **Documentation:** https://docs.secpal.app/activity-logging
- **Data Protection Officer:** dpo@secpal.app

<!--
SPDX-FileCopyrightText: 2025 SecPal Contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Activity Logging Legal Verification Guide

**Version:** 1.0  
**Last Updated:** December 27, 2025  
**Target Audience:** Legal Counsel, Auditors, Expert Witnesses

---

## 📋 Table of Contents

1. [Introduction](#introduction)
2. [Legal Admissibility](#legal-admissibility)
3. [Verification Procedures](#verification-procedures)
4. [Exporting Logs for Court](#exporting-logs-for-court)
5. [OpenTimestamp Proof Verification](#opentimestamp-proof-verification)
6. [Chain of Custody](#chain-of-custody)
7. [Expert Witness Guidance](#expert-witness-guidance)
8. [Frequently Asked Questions](#frequently-asked-questions)

---

## Introduction

SecPal's Activity Logging System is designed to provide **legally admissible evidence** for court proceedings, regulatory audits, and compliance reviews. This guide explains how to:

- Verify the cryptographic integrity of activity logs
- Export logs in court-admissible formats
- Independently verify OpenTimestamp proofs
- Establish chain of custody
- Prepare expert witness testimony

### Legal Framework

SecPal's activity logging complies with:

- **German Civil Procedure Code (ZPO)** §371, §371a (Electronic Documents)
- **EU eIDAS Regulation** (EU 910/2014) - Electronic Signatures
- **BewachV §21 Abs. 4** (Private Security Regulation)
- **GDPR** Article 30 (Records of Processing), Article 32 (Security)

---

## Legal Admissibility

### Requirements for Admissible Electronic Evidence (Germany)

Under **ZPO §371, §371a**, electronic documents are admissible if:

1. **Authenticity:** Document origin can be verified
2. **Integrity:** Document has not been modified
3. **Reliability:** Generation method is trustworthy
4. **Completeness:** All relevant data is included

**How SecPal Meets These Requirements:**

| Requirement | SecPal Implementation |
|-------------|----------------------|
| **Authenticity** | Logs signed with cryptographic hashes, linked to user IDs |
| **Integrity** | SHA256 hash chains detect tampering, Merkle trees provide hierarchical verification |
| **Reliability** | Open-source code (AGPL-3.0), auditable, follows industry standards (NIST SP 800-53) |
| **Completeness** | Sequential logging, no gaps in hash chain, orphaned genesis markers for deletions |

---

### Court Presentation Formats

Activity logs can be exported in three formats:

1. **PDF with Signatures (Human-Readable)**
   - Includes: Timestamp, Actor, Event, Description, Verification Status
   - Signed with X.509 certificate (optional)
   - Best for: Judges, non-technical audiences

2. **JSON with Cryptographic Proofs (Machine-Readable)**
   - Includes: Full log properties, hash chain, Merkle proof, OpenTimestamp proof
   - Best for: Expert witnesses, independent verification

3. **Blockchain-Anchored Certificate (OpenTimestamp)**
   - Includes: Bitcoin block height, transaction ID, proof file (.ots)
   - Best for: Proving existence at a specific time

---

## Verification Procedures

### Level 1: Hash Chain Verification (Basic)

**Purpose:** Verify that logs have not been modified since creation

**Requirements:**
- Access to activity log export (JSON format)
- SHA256 hash calculator (e.g., `openssl`, `sha256sum`, or online tool)

**Step-by-Step:**

1. **Export Log Data:**
   ```bash
   php artisan activity:export-log --id=12345 --format=json --output=log_12345.json
   ```

2. **Recalculate Event Hash:**
   ```bash
   # Extract log properties (tenant_id, log_name, description, etc.)
   # Concatenate in canonical order (see ADR-010)
   # Calculate SHA256 hash
   
   echo -n '{"tenant_id":1,"log_name":"employee_changes",...}' | openssl dgst -sha256
   ```

3. **Compare Hashes:**
   - **If match:** Log is authentic
   - **If mismatch:** Log has been tampered with

4. **Verify Previous Hash Link:**
   - Check that current log's `previous_hash` matches predecessor's `event_hash`
   - **If match:** Chain is intact
   - **If mismatch:** Chain broken (tampering or orphaned genesis)

---

### Level 2: Merkle Tree Verification (Enhanced)

**Purpose:** Verify log membership in a batch without verifying entire chain

**Requirements:**
- Activity log export with Merkle proof
- Merkle tree verification tool (Python script provided)

**Step-by-Step:**

1. **Export Log with Merkle Proof:**
   ```bash
   php artisan activity:export-log --id=12345 --include-merkle --format=json
   ```

2. **Run Verification Script:**
   ```python
   # verify_merkle.py
   import json
   import hashlib

   def hash_pair(left, right):
       return hashlib.sha256((left + right).encode()).hexdigest()

   def verify_merkle_proof(log_hash, proof, root):
       hash_value = log_hash
       for sibling in proof:
           if sibling['position'] == 'left':
               hash_value = hash_pair(sibling['hash'], hash_value)
           else:
               hash_value = hash_pair(hash_value, sibling['hash'])
       return hash_value == root

   # Load log export
   with open('log_12345.json') as f:
       log = json.load(f)

   result = verify_merkle_proof(
       log['event_hash'],
       log['merkle_proof'],
       log['merkle_root']
   )

   print("Verification:", "✅ PASS" if result else "❌ FAIL")
   ```

3. **Interpret Results:**
   - **✅ PASS:** Log is part of the batch, integrity confirmed
   - **❌ FAIL:** Proof is invalid, log may be tampered with or proof corrupted

---

### Level 3: Bitcoin Blockchain Verification (Maximum)

**Purpose:** Prove log existed at a specific time using Bitcoin blockchain

**Requirements:**
- OpenTimestamp proof file (.ots)
- OpenTimestamp CLI tool (https://github.com/opentimestamps/opentimestamps-client)

**Step-by-Step:**

1. **Export OpenTimestamp Proof:**
   ```bash
   php artisan activity:export-ots-proof --id=12345 --output=proof_12345.ots
   ```

2. **Install OpenTimestamp CLI:**
   ```bash
   pip3 install opentimestamps-client
   ```

3. **Verify Proof:**
   ```bash
   ots verify proof_12345.ots
   ```

4. **Expected Output:**
   ```
   Success! Bitcoin block 815234 attests data existed as of 2023-12-27 14:30:00 UTC
   ```

5. **Verify Bitcoin Block (Optional):**
   ```bash
   # Use any Bitcoin block explorer
   curl https://blockchain.info/rawblock/000000000000000000048d8cd8b4b8b8...

   # Or local Bitcoin Core node
   bitcoin-cli getblock <block_hash>
   ```

6. **Interpret Results:**
   - **Success + Bitcoin block confirmed:** Log DEFINITELY existed at that time
   - **Pending:** Bitcoin confirmation not yet received (wait 10-60 minutes)
   - **Failed:** Proof is invalid or corrupted

---

## Exporting Logs for Court

### Export Command

```bash
# Export single log
php artisan activity:export-log --id=12345 --format=pdf --output=exhibit_A.pdf

# Export date range
php artisan activity:export-logs --from=2023-01-01 --to=2023-12-31 --format=json --output=audit_2023.json

# Export all logs for a user
php artisan activity:export-logs --user=42 --format=pdf --output=employee_logs.pdf

# Export with all verification data
php artisan activity:export-logs --include-merkle --include-ots --format=json --output=full_audit.json
```

---

### Export Format: PDF (Human-Readable)

**Sample Output:**

```
╔═══════════════════════════════════════════════════════════════╗
║ SecPal Activity Log Export                                    ║
║ Generated: 2025-12-27 15:30:00 UTC                           ║
║ Certification: Cryptographically Verified                    ║
╚═══════════════════════════════════════════════════════════════╝

Log ID: 12345
Date: 2023-12-27 14:30:00 UTC
Event: employee_updated
Actor: Manager: John Doe (User ID: 42)
Subject: Employee: Jane Smith (User ID: 123)
Description: Updated employee profile

Properties Changed:
  - Position: Security Guard → Senior Security Guard
  - Salary: [REDACTED - Sensitive Data]

Cryptographic Verification:
  ✅ Hash Chain: VERIFIED
  ✅ Merkle Proof: VERIFIED (Batch #1735318800)
  ✅ Bitcoin Anchor: VERIFIED (Block #815234, 2023-12-27 14:32:15 UTC)

Event Hash: 7a8f3c2e1d9b4a5f6c8e0d2a1b3c4d5e6f7a8b9c0d1e2f3a4b5c6d7e8f9a0b1c
Previous Hash: 1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a1b2

---

This document is a certified export from SecPal Activity Logging System.
The cryptographic verification confirms that this log entry has not been
modified since its creation and was Bitcoin-anchored at the timestamp shown.

For independent verification, contact: legal@secpal.app
```

---

### Export Format: JSON (Machine-Readable)

**Sample Output:**

```json
{
  "export_metadata": {
    "generated_at": "2025-12-27T15:30:00Z",
    "secpal_version": "1.0.0",
    "export_format": "activity_log_v1",
    "certification": "cryptographically_verified"
  },
  "logs": [
    {
      "id": 12345,
      "tenant_id": 1,
      "log_name": "employee_changes",
      "description": "updated employee profile",
      "event": "employee_updated",
      "subject_type": "App\\Models\\Employee",
      "subject_id": 123,
      "causer_type": "App\\Models\\User",
      "causer_id": 42,
      "properties": {
        "old": {"position": "Security Guard"},
        "new": {"position": "Senior Security Guard"}
      },
      "created_at": "2023-12-27T14:30:00Z",
      "verification": {
        "event_hash": "7a8f3c2e1d9b4a5f6c8e0d2a1b3c4d5e6f7a8b9c0d1e2f3a4b5c6d7e8f9a0b1c",
        "previous_hash": "1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a1b2",
        "hash_chain_verified": true,
        "merkle_batch_id": 1735318800,
        "merkle_root": "9c0d1e2f3a4b5c6d7e8f9a0b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5e6f7a8b9",
        "merkle_proof_verified": true,
        "ots_confirmed_at": "2023-12-27T14:32:15Z",
        "ots_bitcoin_block": 815234,
        "ots_proof_verified": true
      }
    }
  ]
}
```

---

## OpenTimestamp Proof Verification

### What is OpenTimestamp?

OpenTimestamp is a **decentralized timestamping service** that anchors cryptographic hashes to the Bitcoin blockchain. This provides:

1. **Immutable Proof of Existence:** Proves data existed at a specific time
2. **Independent Verification:** Anyone can verify without trusting SecPal
3. **Cost-Effective:** Uses Bitcoin blockchain without per-transaction fees

### Verification Methods

#### Method 1: OpenTimestamp CLI (Recommended)

```bash
# Install
pip3 install opentimestamps-client

# Verify proof
ots verify proof.ots

# Expected output
Success! Bitcoin block 815234 attests data existed as of 2023-12-27 14:32:15 UTC
```

#### Method 2: OpenTimestamp Web Verifier

1. Visit https://opentimestamps.org/
2. Click "Verify"
3. Upload `.ots` proof file
4. View verification result

#### Method 3: Python Script (Programmatic)

```python
import opentimestamps
from opentimestamps.core.timestamp import Timestamp
from opentimestamps.core.notary import BitcoinBlockHeaderAttestation

# Load proof file
with open('proof.ots', 'rb') as f:
    proof = opentimestamps.deserialize(f.read())

# Verify against Bitcoin blockchain
result = opentimestamps.verify(proof)

if result.is_timestamp_complete():
    print(f"✅ Verified: Block {result.attestations[0].height}")
else:
    print("⏳ Pending: Bitcoin confirmation not yet received")
```

---

### Understanding OpenTimestamp Output

**Successful Verification:**
```
Success! Bitcoin block 815234 attests data existed as of 2023-12-27 14:32:15 UTC
```

**Interpretation:**
- **Block 815234:** Bitcoin block containing the attestation
- **2023-12-27 14:32:15 UTC:** Timestamp of the block (proof cannot be older than this)
- **Implication:** The log DEFINITELY existed at or before this time

**Pending Confirmation:**
```
Pending: Waiting for Bitcoin confirmation
Estimated time: 10-60 minutes
```

**Interpretation:**
- OpenTimestamp proof submitted but not yet confirmed by Bitcoin network
- Wait for 1-6 Bitcoin blocks (average 10 minutes per block)
- Retry verification later

**Failed Verification:**
```
Bad attestation: Proof does not match Bitcoin block
```

**Interpretation:**
- **Proof corrupted:** File damaged during export/transfer
- **Tampering:** Log was modified AFTER proof creation
- **Blockchain reorg:** Extremely rare, Bitcoin block was replaced (wait 6 blocks)

---

## Chain of Custody

### Establishing Chain of Custody for Court

**Definition:** Chain of custody documents who had access to evidence and when, ensuring integrity and admissibility.

**SecPal Chain of Custody:**

1. **Evidence Creation (Automated)**
   - Activity log created automatically upon user action
   - Timestamp: `created_at` field (database TIMESTAMP)
   - Actor: `causer_id` (authenticated user)
   - Hash: Calculated immediately (SHA256)

2. **Evidence Storage (Tamper-Proof)**
   - PostgreSQL database with append-only logging
   - Hash chain links each log to predecessor
   - Merkle tree batching (hourly for Level 2+3)
   - Bitcoin anchoring (daily for Level 3)

3. **Evidence Export (Auditable)**
   - Export command logs to `activity_log` (meta-logging)
   - Exported by: Admin user (logged)
   - Export timestamp: Recorded
   - Export hash: SHA256 of export file

4. **Evidence Transfer (Documented)**
   - Transfer to legal counsel: Document date + recipient
   - Transfer to court: Document exhibit number + filing date
   - Chain of custody form: Fill out template (see Appendix)

---

### Chain of Custody Form Template

```
═══════════════════════════════════════════════════════════
CHAIN OF CUSTODY FORM - ELECTRONIC EVIDENCE
═══════════════════════════════════════════════════════════

Case Number: _____________________
Evidence Type: SecPal Activity Logs
Export Date: _____________________
Exported By: _____________________

Evidence Description:
  Log IDs: _____________________
  Date Range: _____________________
  Export Format: [ ] PDF  [ ] JSON  [ ] Both
  Export Hash (SHA256): _____________________

═══════════════════════════════════════════════════════════
TRANSFER LOG
═══════════════════════════════════════════════════════════

Transfer #1:
  Date: _____________________
  From: SecPal Admin (_____________________) 
  To: Legal Counsel (_____________________) 
  Method: [ ] Email  [ ] USB  [ ] Secure Cloud
  Signature: _____________________

Transfer #2:
  Date: _____________________
  From: Legal Counsel (_____________________) 
  To: Court (_____________________) 
  Exhibit Number: _____________________
  Signature: _____________________

═══════════════════════════════════════════════════════════
VERIFICATION LOG
═══════════════════════════════════════════════════════════

Initial Verification:
  Date: _____________________
  Verifier: _____________________
  Hash Chain: [ ] Verified  [ ] Failed
  Merkle Proof: [ ] Verified  [ ] Failed  [ ] N/A
  OpenTimestamp: [ ] Verified  [ ] Failed  [ ] N/A
  Signature: _____________________

Court Verification (Expert Witness):
  Date: _____________________
  Verifier: _____________________
  Findings: _____________________
  Signature: _____________________

═══════════════════════════════════════════════════════════
```

---

## Expert Witness Guidance

### Qualifying as Expert Witness

**German Court (ZPO §402):**
- Must demonstrate **specialized knowledge** in:
  - Cryptography (SHA256 hashing, Merkle trees)
  - Blockchain technology (Bitcoin, OpenTimestamp)
  - Software engineering (database systems, audit logging)
- Credentials: Computer Science degree, IT security certification, published research

**US Court (Federal Rules of Evidence 702):**
- Similar requirements + Daubert standard (reliability, peer review, error rate)

---

### Expert Witness Testimony Outline

**1. Introduction (5 minutes)**
- State qualifications (education, experience, publications)
- Define scope of testimony (cryptographic verification of activity logs)
- Disclosures (no financial interest in SecPal)

**2. Technical Background (10 minutes)**
- Explain cryptographic hashing (SHA256)
- Explain hash chains (blockchain analogy)
- Explain Merkle trees (efficient verification)
- Explain OpenTimestamp (Bitcoin anchoring)

**3. Verification Process (15 minutes)**
- Describe verification performed:
  - Hash recalculation
  - Chain integrity check
  - Merkle proof verification
  - Bitcoin blockchain confirmation
- Present verification results
- Demonstrate with visual aids (hash chain diagram)

**4. Findings (5 minutes)**
- State conclusion:
  - "The activity log is **authentic and unmodified**"
  - "The log existed on [DATE] as evidenced by Bitcoin block [NUMBER]"
- Address tampering scenarios (what would detection look like)

**5. Cross-Examination Preparation**
- Anticipate questions:
  - "Could the database administrator modify logs?"
    - **Answer:** No, hash chain would break. Modification detectable.
  - "Could someone fake a Bitcoin timestamp?"
    - **Answer:** No, Bitcoin blockchain is immutable. Would require 51% attack (cost: billions).
  - "How do you know SecPal software is reliable?"
    - **Answer:** Open-source (AGPL-3.0), publicly auditable, follows NIST standards.

---

## Frequently Asked Questions

### General Questions

**Q: Are SecPal activity logs admissible in German courts?**  
**A:** Yes, under **ZPO §371, §371a** (electronic documents). Cryptographic verification satisfies integrity requirements.

**Q: Can logs be edited after creation?**  
**A:** No. Logs are **immutable by design**. Any modification breaks the hash chain and is immediately detectable.

**Q: What if a log is deleted due to retention policies?**  
**A:** The **next log** in the chain is marked as "orphaned genesis" with metadata explaining the deletion. This is legitimate and does NOT indicate tampering.

**Q: How long are logs retained?**  
**A:** Depends on security level and legal requirements:
- **Level 1:** 1 year (soft delete) → 2 years (hard delete)
- **Level 2:** 3 years (archive) → 5 years (hard delete)
- **Level 3:** 3 years (BewachV § 21 Abs. 4 minimum), then hash-only archives

**Note:** If your organization is subject to HGB § 257 (6-10 years for business records) or AO § 147 (10 years for tax documents), retention periods can be extended. Consult legal counsel.

---

### Technical Questions

**Q: What is the legal value of a Bitcoin timestamp?**  
**A:** Provides **irrefutable proof** that data existed at a specific time. Cannot be backdated or forged without controlling 51% of Bitcoin network (virtually impossible).

**Q: Can OpenTimestamp proofs be independently verified?**  
**A:** Yes. Use open-source OpenTimestamp CLI or web verifier. No reliance on SecPal required.

**Q: What if the database is hacked?**  
**A:** Hash chain breakage would be immediately detected during verification. Attacker cannot modify logs without breaking integrity.

**Q: What if SecPal company goes out of business?**  
**A:** Logs can still be verified independently:
- Export logs before shutdown
- Verify hashes manually (SHA256 calculator)
- Verify OpenTimestamp proofs (Bitcoin blockchain is permanent)

---

## Additional Resources

- **ADR-010:** [20251221-activity-logging-audit-trail-strategy.md](../.github/docs/adr/20251221-activity-logging-audit-trail-strategy.md)
- **User Guide:** [ACTIVITY_LOGGING_USER_GUIDE.md](./ACTIVITY_LOGGING_USER_GUIDE.md)
- **Admin Guide:** [ACTIVITY_LOGGING_ADMIN_GUIDE.md](./ACTIVITY_LOGGING_ADMIN_GUIDE.md)
- **BewachV §21:** https://www.gesetze-im-internet.de/bewachv_2019/__21.html
- **ZPO §371:** https://www.gesetze-im-internet.de/zpo/__371.html
- **OpenTimestamp:** https://opentimestamps.org/
- **Bitcoin Block Explorer:** https://blockchain.info/

---

**Legal Support Contact:**
- **Email:** legal@secpal.app
- **Expert Witness Services:** expert-witness@secpal.app
- **Emergency (during trial):** +49 (0) 123-456-789

<!-- Self-Review Checklist: https://github.com/kevalyq/SecPal/blob/main/DEVELOPMENT.md -->

<!-- SPDX-FileCopyrightText: 2025 SecPal -->
<!-- SPDX-License-Identifier: CC0-1.0 -->

# feat: Implement GDPR-compliant direct archiving for retention policies

**Closes #443** - Phase 2: Hard Delete & Archive (Option A)

## Summary

This PR implements **Option A (Direct Archiving)** from Issue #443, providing GDPR Article 17 compliant activity log retention policies with immediate hard deletion after archiving. The implementation removes personal data while preserving cryptographic integrity (hashes) for audit trail verification.

**Key Decision:** No soft delete grace period - logs are archived and hard deleted immediately upon retention policy execution (GDPR Art. 17 "unverzüglich").

## Changes

### 🔧 Implementation

**File:** `app/Console/Commands/ApplyRetentionPolicies.php`

**Key Changes:**

- ✅ Removed `--hard-delete` flag (archive + hard delete is now default and only behavior)
- ✅ Updated command description: "Archive hashes + hard delete (GDPR Art. 17)"
- ✅ Statistics now track `retention_X_archived` instead of `_deleted`
- ✅ Added `withTrashed()` to query - processes both active AND soft-deleted logs (legacy cleanup)
- ✅ Implemented `archiveAndHardDelete()` logic:
  - `DB::transaction()` for atomicity
  - `ActivityArchive::create()` with ONLY hashes (GDPR-compliant)
  - Orphaned genesis marking preserved
  - `forceDelete()` for hard deletion (personal data removed)

**Archive Structure (GDPR-compliant):**

```php
ActivityArchive fields:
- id (UUID)
- tenant_id (UUID)
- log_name (string)
- created_at (timestamp)
- event_hash (string)      // ✅ KEPT
- previous_hash (string)   // ✅ KEPT
- merkle_root (string)     // ✅ KEPT
- merkle_batch_id (UUID)   // ✅ KEPT

NOT stored (GDPR compliance):
- description              // ❌ REMOVED (personal data)
- properties               // ❌ REMOVED (personal data)
- subject_type             // ❌ REMOVED (personal data)
- subject_id               // ❌ REMOVED (personal data)
- causer_type              // ❌ REMOVED (personal data)
- causer_id                // ❌ REMOVED (personal data)
```

### ✅ Test Suite

**File:** `tests/Feature/Commands/ApplyRetentionPoliciesArchiveTest.php` (NEW, 301 lines)

**8 Comprehensive PEST Tests:**

1. ✅ **Direct archiving test** - Verifies expired logs are archived with only hashes and hard deleted
2. ✅ **Orphaned genesis test** - Verifies successor logs marked as orphaned genesis when predecessor archived
3. ✅ **Soft-deleted logs test** - Verifies `withTrashed()` processes both active and soft-deleted logs (legacy cleanup)
4. ✅ **Statistics test** - Verifies correct tracking of `retention_X_archived` for 3/8/10 year periods
5. ✅ **Merkle preservation test** - Verifies merkle_root and merkle_batch_id preserved in archive
6. ✅ **Dry-run test** - Verifies `--dry-run` flag prevents actual archiving
7. ✅ **Cutoff date test** - Verifies calendar year precision (BewachV §21 Abs. 4)
8. ✅ **GDPR compliance test** - Verifies NO personal data columns in archive

**Test Results:**

```
✓ All 8 tests passed (4.25s runtime)
✓ 51 assertions validated
✓ 0 failures, 0 warnings
```

### 📝 Documentation Updates

**File:** `.github/docs/adr/20251221-activity-logging-audit-trail-strategy.md`

- Updated Phase 4 status: ✅ COMPLETED
- Documented Option A implementation details
- Added GDPR Art. 17 compliance notes
- Explained withTrashed() for legacy cleanup
- Confirmed SoftDeletes trait preserved in Activity model (for other features)
- Scheduler configuration: Daily at 02:00 via `routes/console.php`

**File:** `routes/console.php` (No changes - scheduler already configured)

```php
// Schedule: Apply retention policies daily at 02:00
// See ADR-010 Phase 4: Retention Policies
// 3-tier strategy: Level 1 (1y→2y), Level 2 (3y→5y), Level 3 (permanent)
// BewachV §21 Abs. 4 + GDPR Article 5(1)(e) compliance
Schedule::command('activity:apply-retention')->dailyAt('02:00')->name('activity-retention');
```

## Legal Compliance

### ✅ GDPR Article 17 ("Right to Erasure")

**Requirement:** "unverzüglich" (immediate) deletion after retention period expires

**Implementation:**

- ✅ No soft delete grace period
- ✅ Direct archive + hard delete in single transaction
- ✅ Personal data removed immediately
- ✅ Only cryptographic hashes preserved (not personal data under GDPR)

### ✅ BewachV §21 Abs. 4 (German Security Service Regulations)

**Requirement:** Minimum 2-year retention for activity records

**Implementation:**

- ✅ 3-year retention for Level 1 (Bewachungsdienstleistungen)
- ✅ 8-year retention for Level 2 (HGB §257 Geschäftsbücher)
- ✅ 10-year retention for Level 3 (AO §147 Buchführungsunterlagen)
- ✅ Calendar year calculation (retention starts from end of calendar year)

## Quality Checks

### ✅ Test Suite

```bash
# Feature tests
ddev exec vendor/bin/pest tests/Feature/Commands/ApplyRetentionPoliciesArchiveTest.php
# Result: 8/8 passed ✅

# Full test suite
ddev exec vendor/bin/pest
# Result: 1747/1747 passed ✅
```

### ✅ Static Analysis

```bash
ddev exec vendor/bin/phpstan analyse
# Result: Level 9 - No errors ✅
```

### ✅ Code Formatting

```bash
ddev exec vendor/bin/pint
# Result: 408 files formatted ✅
```

## Technical Details

### Architecture Decisions

1. **No Backward Compatibility Required**

   - Version: 0.X.X (breaking changes allowed)
   - Removed all backward compatibility code
   - Documentation and comments in English only

2. **SoftDeletes Trait Preserved**

   - Activity model still uses SoftDeletes trait (line 73: `use SoftDeletes;`)
   - NOT used for retention policies (direct hard delete)
   - May be used by other features or for manual deletions
   - `withTrashed()` query ensures legacy soft-deleted logs are also archived

3. **withTrashed() Query Justification**
   - Line ~194: `Activity::withTrashed()->where(...)`
   - Processes BOTH active and soft-deleted logs
   - Rationale: Clean up legacy soft-deleted data that was never archived
   - Comment: "withTrashed() to also process already soft-deleted logs (legacy cleanup)"

### Database Schema

**ActivityArchive Table:**

- Already exists (migration: `2025_12_21_160227_create_activity_log_archive_table.php`)
- GDPR-compliant by design (only hash fields)
- NO changes required to migration

**Activity Table:**

- SoftDeletes column preserved (NOT removed)
- Rationale: May be used by other features
- Retention policy uses `forceDelete()` to bypass soft delete

## Development Process

### ✅ TDD Workflow

1. **Red Phase:**

   - Created 8 PEST tests first
   - Initial run: 3 failures (expected)

2. **Green Phase:**

   - Fixed UUID errors in GDPR test
   - Fixed hash retrieval (refresh() after create)
   - Added withTrashed() to query
   - Result: All 8 tests passed ✅

3. **Refactor Phase:**
   - PHPStan Level 9 validation ✅
   - Laravel Pint formatting ✅
   - Full test suite validation (1747/1747) ✅

### ✅ Self-Review Checklist

Per [Development Principles](https://github.com/kevalyq/SecPal/blob/main/DEVELOPMENT.md):

- ✅ **TDD:** Tests written first, implementation second
- ✅ **Clean Before Quick:** GDPR compliance prioritized over convenience
- ✅ **Quality First:** PHPStan Level 9 + Laravel Pint + Full test suite
- ✅ **English:** All documentation and comments in English
- ✅ **No Backward Compatibility:** 0.X.X version allows breaking changes

## Migration Notes

### For Existing Deployments

1. **Legacy Soft-Deleted Logs:**

   - Command now processes soft-deleted logs via `withTrashed()`
   - Legacy data will be archived on next retention policy run
   - No manual intervention required

2. **Scheduler:**

   - Already configured in `routes/console.php`
   - Runs daily at 02:00 (as before)
   - No changes to cron/scheduler configuration needed

3. **No Migration Required:**
   - No database schema changes
   - No config changes
   - Drop-in replacement for existing implementation

## Testing Instructions

### Manual Testing

```bash
# 1. Run retention policy manually
ddev exec php artisan activity:apply-retention

# 2. Dry-run mode (preview only)
ddev exec php artisan activity:apply-retention --dry-run

# 3. Run feature tests
ddev exec vendor/bin/pest tests/Feature/Commands/ApplyRetentionPoliciesArchiveTest.php

# 4. Run full test suite
ddev exec vendor/bin/pest

# 5. Static analysis
ddev exec vendor/bin/phpstan analyse
```

### Expected Results

1. Expired logs are archived with only hashes
2. Personal data columns NOT present in archive
3. Archived logs are hard deleted (forceDelete())
4. Successor logs marked as orphaned genesis
5. Statistics show `retention_X_archived` counts
6. Merkle tree data preserved in archive
7. All tests pass (1747/1747)
8. PHPStan Level 9 clean

## References

- **Issue:** #443 (Phase 2: Hard Delete & Archive)
- **ADR:** [20251221-activity-logging-audit-trail-strategy.md](/.github/docs/adr/20251221-activity-logging-audit-trail-strategy.md)
- **GDPR:** Article 17 (Right to Erasure), Article 5(1)(e) (Storage Limitation)
- **BewachV:** §21 Abs. 4 (Minimum 2-year retention)
- **HGB:** §257 (8-year retention for accounting records)
- **AO:** §147 (10-year retention for tax records)

## Screenshots

Not applicable (backend command implementation)

---

**Review Checklist:**

- [ ] Code follows TDD principles
- [ ] All tests pass (feature + full suite)
- [ ] PHPStan Level 9 clean
- [ ] Laravel Pint formatted
- [ ] GDPR compliance verified
- [ ] Documentation updated (ADR-010 Phase 4)
- [ ] No backward compatibility issues (0.X.X)
- [ ] English documentation/comments

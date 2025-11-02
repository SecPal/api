<!-- SPDX-FileCopyrightText: 2025 SecPal Contributors -->
<!-- SPDX-License-Identifier: CC0-1.0 -->

# PR #74 Retrospective: Password Reset Feature

**Date:** 2025-11-02  
**PR:** [#74 - Password Reset Feature (Production Test Phase 1)](https://github.com/SecPal/api/pull/74)  
**Final Commit:** `f575a55` (merged to main)  
**Duration:** ~6 hours (session interruption + recovery)

---

## 🎯 What Went Well

### 1. **Production Test Methodology** ✅

- TDD approach with 13 comprehensive tests (44 assertions)
- Security-first thinking (rate-limiting, email enumeration protection)
- Documentation-driven development with retrospective

### 2. **Self-Review Checklist Success** ✅

- Successfully created `SELF_REVIEW_CHECKLIST.md` during session
- Applied systematically to catch pattern issues before CI
- Verified all CRITICAL pattern issues (Pest syntax, assertOk, Form Requests) already fixed
- Nitpick comments addressed methodically (constants, rate limiters, refactorings)

### 3. **Breaking Change Recovery** ✅

- Detected 2 breaking changes via pre-commit hooks (42 + 13 test failures)
- Debugged systematically using DDEV
- Root cause analysis documented for learning

### 4. **Quality Standards** ✅

- PHPStan Level 9 (0 errors)
- All 127 tests passing (376 assertions)
- REUSE 3.3 compliant (125/125 files)
- Prettier + Markdownlint clean

---

## ❌ What Went Wrong

### 1. **Pint False Confidence** 🚨 CRITICAL

**Problem:**

```bash
# We ran locally:
ddev exec vendor/bin/pint  # Auto-fix mode

# CI ran:
./vendor/bin/pint --test   # Check-only mode
```

**Result:**

- Local: ✅ "All files clean" (after auto-fix)
- CI: ❌ "1 style issue: `no_whitespace_in_blank_line`"

**Root Cause:**

- Running `pint` without `--test` **hides issues** by auto-fixing them
- CI uses `--test` (check-only), so local auto-fix creates **false confidence**
- User edited `bootstrap/app.php` after our checks (added whitespace to blank line)

**Impact:**

- Failed CI check after push
- Required hotfix commit (`939fa97`)
- Delayed merge by ~10 minutes

**Fix Applied:**

- Updated `SELF_REVIEW_CHECKLIST.md` Phase 1 with **CRITICAL warning**
- Added explicit `--test` first, then auto-fix workflow
- Added to "Common Mistakes to Avoid" (#8)

**Prevention:**

```bash
# ✅ CORRECT workflow:
ddev exec vendor/bin/pint --test  # Check first
ddev exec vendor/bin/pint         # Fix if needed
ddev exec vendor/bin/pint --test  # Verify fix
```

### 2. **Copilot Comment Confusion**

**Problem:**

- 28 Copilot comments appeared after initial push
- 6 "new" comments appeared after force-push
- Comments referenced old commit SHAs (`2ea1363`)

**Reality:**

- Most comments were **already addressed** in previous commits
- GitHub API does NOT update comments on force-push
- Comments were **stale** and not relevant to current state

**Impact:**

- Wasted time triaging "new" comments
- Confusion about whether fixes were applied
- Manual verification required for each comment

**Prevention:**

- Always check `commit_id` and `original_commit_id` in comment metadata
- Compare comment SHA with current HEAD SHA
- Ignore comments from outdated SHAs after force-push

---

## 📚 Key Learnings

### 1. **CI/Local Parity is Critical**

- ✅ DO: Use **exact same commands** as CI (e.g., `pint --test`)
- ❌ DON'T: Use auto-fix commands (`pint`) that hide issues from CI
- **Principle:** If CI checks it, local workflow must check it identically

### 2. **Self-Review Checklist Gaps**

- Phase 1 had `pint` command but lacked **`--test` emphasis**
- Updated to include:
  - ⚠️ CRITICAL warning about `--test` flag
  - Explicit workflow: check → fix → verify
  - Explanation of why auto-fix alone is dangerous

### 3. **Force-Push Side Effects**

- GitHub Copilot comments persist across force-pushes
- Comments are **SHA-bound**, not **PR-bound**
- Manual triage required to identify stale comments

### 4. **Session Recovery Best Practices**

- ✅ Always start with `git status` + `git log` after interruption
- ✅ Verify all work committed/pushed before continuing
- ✅ Check CI status to understand current state

---

## 🔧 Process Improvements Implemented

### 1. **SELF_REVIEW_CHECKLIST.md Updates**

**Added to Phase 1 (Pint section):**

```markdown
⚠️ **CRITICAL:** ALWAYS run `--test` FIRST to check for issues!

**Why:** Running `pint` without `--test` auto-fixes issues locally but hides them.
CI uses `--test` (check-only), so local auto-fix creates false confidence!
```

**Added to "Common Mistakes" section:**

```markdown
8. ❌ **Running `pint` without `--test` first**
   - Fix: ALWAYS run `pint --test` before auto-fix
   - Reason: Auto-fix hides issues that CI will catch
   - CI uses `--test` (check-only), creating false confidence if you only auto-fix locally
```

### 2. **Documentation Created**

- ✅ `SELF_REVIEW_CHECKLIST.md` (320 lines)
- ✅ `ISSUE74_RETROSPECTIVE.md` (this file)
- ✅ Updated `PRODUCTION_TEST_PASSWORD_RESET.md` with learnings

---

## 📊 Metrics

### Code Changes

- **Files Changed:** 16
- **Additions:** +1,419 lines
- **Deletions:** -49 lines
- **Tests Created:** 13 (44 assertions)
- **Total Tests:** 127 (376 assertions)

### Quality Checks

- ✅ PHPStan Level 9: 0 errors
- ✅ Pint: 66 files checked (after hotfix)
- ✅ REUSE: 125/125 files compliant
- ✅ Tests: 127/127 passing

### Iteration Counts

- **Commits:** 9 total
- **Force Pushes:** 1 (after nitpick fixes)
- **Hotfix Commits:** 1 (Pint whitespace issue)
- **Breaking Changes:** 2 (rate limiter + Redis dependency)
- **Breaking Changes Fixed:** 2/2 (100%)

### Time Investment

- **Total Session:** ~6 hours (with interruption)
- **Production Test:** ~90 minutes (TDD)
- **Self-Review Creation:** ~45 minutes
- **Nitpick Fixes:** ~60 minutes
- **Breaking Change Debugging:** ~30 minutes
- **Pint Hotfix:** ~10 minutes

---

## ✅ Verification

### Pre-Merge State

```bash
# All quality checks passed:
✓ PHPStan Level 9 (0 errors)
✓ Pint (66 files, 0 issues)
✓ Tests (127/127 passing, 376 assertions)
✓ REUSE (125/125 files compliant)
✓ Prettier + Markdownlint (0 errors)
```

### Post-Merge State

```bash
# Merged to main as:
Commit: f575a55
Method: squash
Status: success
Branch: main
```

---

## 🎯 Future Recommendations

### 1. **Enforce `--test` in CI Config**

- Consider adding CI check that verifies local workflow
- Add git hook that runs `pint --test` before commit
- Document flag in `.github/copilot-instructions.md`

### 2. **Copilot Comment Handling**

- Add workflow to dismiss stale comments after force-push
- Create script to compare comment SHA with HEAD SHA
- Document "ignore comments from old SHAs" in contributor guide

### 3. **Self-Review Automation**

- Consider pre-commit hook that runs full checklist
- Add `make check` command that runs all Phase 1 steps
- Create CI job that validates checklist compliance

### 4. **Session Recovery Protocol**

- Document standard recovery steps (git status, log, CI check)
- Add to contributor guidelines
- Consider automated session recovery script

---

## 🔗 Related Documentation

- [Self-Review Checklist](./SELF_REVIEW_CHECKLIST.md) - Updated with Pint learnings
- [Production Test Report](./PRODUCTION_TEST_PASSWORD_RESET.md) - Original TDD methodology
- [Epic Workflow](./EPIC_WORKFLOW.md) - Feature development process
- [Copilot Instructions](../.github/copilot-instructions.md) - AI coding guidelines

---

## 📝 Conclusion

**Success Factors:**

- ✅ Systematic self-review process caught most issues early
- ✅ TDD methodology ensured comprehensive test coverage
- ✅ Documentation-first approach created knowledge base
- ✅ Breaking changes caught by pre-commit hooks (not CI)

**Improvement Areas:**

- ⚠️ Pint workflow needed explicit `--test` emphasis
- ⚠️ Copilot comment triage required manual effort
- ⚠️ User edits between checks created false confidence

**Key Takeaway:**

> **CI/Local Parity is Critical.** If CI runs `cmd --flag`, local workflow MUST run `cmd --flag` identically. Auto-fix tools (`pint`, `prettier`) should be verification step, not discovery step.

**Process Enhancement:**
The `SELF_REVIEW_CHECKLIST.md` now explicitly warns about this pattern and provides correct workflow. Future features should follow updated checklist from start.

---

**Status:** ✅ Merged to main (`f575a55`)  
**Next Steps:** Apply learnings to next feature (Production Test Phase 2)

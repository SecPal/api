<!-- SPDX-FileCopyrightText: 2025 SecPal -->
<!-- SPDX-License-Identifier: CC0-1.0 -->

# Epic Workflow Implementation Summary

Quick reference guide for the Epic & Sub-Issue workflow implementation.

## Problem

Issue #50 was split across 7 main PRs, causing:

- ❌ Each PR closed the issue prematurely
- ❌ Inconsistent project board status
- ❌ No granular PR tracking
- ❌ Manual status updates required

## Solution

**Sub-Issue Pattern** using GitHub's native tasklist features:

- ✅ Epic stays open until all sub-issues complete
- ✅ Each PR gets its own sub-issue (1:1 mapping)
- ✅ Automatic progress tracking in GitHub Projects
- ✅ Only the last PR closes the epic

## Files Created

### Organization Templates (SecPal/.github)

**`.github/ISSUE_TEMPLATE/epic.yml`**

- Template for multi-PR features (3+ PRs)
- YAML form with interactive fields
- Automatic progress tracking via tasklists
- Organization-wide availability

**`.github/ISSUE_TEMPLATE/sub-issue.yml`**

- Template for individual tasks within an epic
- Links to parent epic
- Focused acceptance criteria
- PR linking instructions

### Documentation (api repo)

**`docs/EPIC_WORKFLOW.md`**

- Complete workflow guide
- Step-by-step instructions
- Best practices and examples
- FAQ section

**`docs/ISSUE50_RETROSPECTIVE.md`**

- Case study from Issue #50
- What went wrong/right
- Recommendations for future epics
- Timeline and metrics

**`DEVELOPMENT.md`** (updated)

- Added "Working with Epics" section
- Quick start guide
- Link to full documentation

## Quick Start

### Creating a New Epic

```bash
# 1. Create epic via GitHub UI or CLI
gh issue create --repo SecPal/api --template epic.yml --title "[EPIC] Feature"

# 2. Create sub-issues (one per PR)
gh issue create --repo SecPal/api --template sub-issue.yml --title "PR-1: Description"

# 3. Edit epic to link sub-issues in tasklist
# 4. Each PR uses: Fixes #<sub-issue-number>
# 5. Last PR only: Closes #<epic-number>
```

### Key Principles

1. **Epic = Large Feature** (3+ PRs, clear milestones)
2. **Sub-Issue = One PR** (~400-600 LOC, focused goal)
3. **PR Links to Sub-Issue** (not epic, except last PR)
4. **GitHub Automates Status** (no manual updates needed)

## Benefits

### For Project Management

- ✅ Automatic progress tracking ("5 of 7 complete")
- ✅ Granular status per PR
- ✅ Epic stays open until complete
- ✅ Clear dependencies

### For Development

- ✅ Focused sub-issues easier to implement
- ✅ Smaller PRs easier to review
- ✅ Clear acceptance criteria per task
- ✅ Parallel work on independent sub-issues

### For Project Board

- ✅ Each sub-issue appears independently
- ✅ Automatic status synchronization
- ✅ Visual progress indicators
- ✅ Filtering by epic/sub-issue

## Validation

All documentation passes linting:

```bash
npx markdownlint-cli2 docs/EPIC_*.md docs/ISSUE50_RETROSPECTIVE.md DEVELOPMENT.md
# Result: 0 errors
```

## Templates Location

Templates are **organization-wide** in the `SecPal/.github` repository:

- Available when creating issues in ANY SecPal repo
- Consistent across all projects (DRY principle)
- YAML format (interactive forms)

## Related Files

- **Templates:** `SecPal/.github/.github/ISSUE_TEMPLATE/`
  - `epic.yml` - Epic template
  - `sub-issue.yml` - Sub-issue template
- **Documentation:** `SecPal/docs/`
  - `EPIC_WORKFLOW.md` - Complete guide
  - `ISSUE50_RETROSPECTIVE.md` - Case study
- **Quick Reference:** `SecPal/api/DEVELOPMENT.md`

## Next Steps

### For Future Epics

1. Use templates when creating large features
2. Plan sub-issues before starting work
3. Update epic as plan evolves
4. Document lessons learned after completion

### For Issue #50

- Documented as case study (no retroactive changes needed)
- Serves as real-world example
- Templates ready for next epic

## Impact

This implementation provides:

- **Process Improvement**: Clearer workflow for large features
- **Automation**: GitHub handles status tracking
- **Documentation**: Templates and guides prevent confusion
- **Learning**: Issue #50 documented as reference
- **DRY**: Organization-wide templates (not per-repo)

---

**Created:** 2025-11-02
**Author:** @kevalyq
**Status:** ✅ Complete and ready to use

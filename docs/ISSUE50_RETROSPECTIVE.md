<!-- SPDX-FileCopyrightText: 2025 SecPal -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Issue #50 Retrospective: Learning from Multi-PR Epic

## Summary

Issue #50 ("Multi-tenant security, field encryption & blind indexes") was successfully implemented across **7 main PRs** and several supporting PRs. However, the workflow exposed challenges with GitHub's automatic project status updates when multiple PRs reference the same issue.

This document captures lessons learned and demonstrates how the **Sub-Issue pattern** (now documented in [EPIC_WORKFLOW.md](./EPIC_WORKFLOW.md)) would have made tracking cleaner.

## What Happened

### Epic Structure

Issue #50 defined 7 implementation phases:

- PR-0: Org `.github` DRY & Preflight
- PR-1: Migrations & Base Schema
- PR-2: Services — KeyStore & BlindIndex
- PR-3: Tenant Middleware & RBAC
- PR-4: Model/Observer & Repository
- PR-5: API Endpoints (JSON)
- PR-6: Rotation & Maintenance
- PR-7: Hardening & Cleanups

### Actual PRs Merged

| PR # | Title                                                                     | LOC  | Status                      |
| ---- | ------------------------------------------------------------------------- | ---- | --------------------------- |
| #51  | feat: org .github DRY & preflight (PR-0)                                  | 108  | ✅ Merged                   |
| #52  | PR-1: Migrations & Base Schema                                            | 394  | ✅ Merged                   |
| #53  | PR-2: TenantKey Model & KEK Management                                    | 504  | ✅ Merged                   |
| #55  | feat: PR-3 - Tenant Middleware & RBAC Wiring                              | 593  | ✅ Merged                   |
| #59  | docs: Quick wins from PR review analysis                                  | 505  | ✅ Merged (supporting)      |
| #60  | feat: Add Sanctum API token authentication (Issue #50 PR-4)               | 450  | ✅ Merged                   |
| #61  | fix: Resolve PostgreSQL BYTEA binary encoding issue (Issue #50 PR-4)      | 822  | ✅ Merged (supporting)      |
| #63  | fix: resolve parallel test execution failures (Issue #62)                 | ~150 | ✅ Merged (supporting)      |
| #64  | feat: PR-5 - Person API endpoints with auth and permissions               | 439  | ✅ Merged                   |
| #65  | feat: PR-6 - Key rotation and maintenance commands + DEK-based encryption | 822  | ✅ Merged                   |
| #67  | feat: PR-7 - Hardening & cleanups                                         | 281  | ✅ Merged                   |
| #68  | refactor: improve DRY and add pre-PR checklist                            | 120  | ✅ Merged (post-completion) |

**Total:** 12 PRs, ~5,188 LOC (excluding vendor/tests), completed in 1 day

## Problems Encountered

### 1. Project Status Confusion

**Issue:** Each PR description mentioned "Fixes #50" or "Closes #50", causing:

- ❌ GitHub Projects automatically moved #50 to "Done" after first PR merged
- ❌ Manual re-opening of #50 required after each PR
- ❌ Project board showed inconsistent epic status
- ❌ Difficult to track which parts were complete

### 2. No Granular Progress Tracking

**Issue:** The epic had a checklist in the description, but:

- ❌ Checklist wasn't automatically synchronized with PR status
- ❌ Manual updates required after each merge
- ❌ No way to see individual PR progress in project board
- ❌ Contributors couldn't easily see dependencies

### 3. Supporting PRs Not Planned For

**Issue:** Unexpected PRs emerged during implementation:

- #59: Process improvements from review analysis
- #61: BYTEA encoding fix (discovered during PR-4)
- #63: Parallel test isolation fix (discovered during testing)
- #68: Post-completion DRY improvements

These weren't in the original plan, creating confusion about epic scope.

## How Sub-Issues Would Have Helped

### Ideal Structure

```text
Epic Issue #50 (stays open until all sub-issues closed)
├─ Sub-Issue #51: PR-0 Org DRY & Preflight
├─ Sub-Issue #52: PR-1 Migrations & Base Schema
├─ Sub-Issue #53: PR-2 TenantKey Model & KEK
├─ Sub-Issue #55: PR-3 Tenant Middleware & RBAC
├─ Sub-Issue #60: PR-4 Sanctum Auth
│  ├─ Supporting PR #61: BYTEA encoding fix
│  └─ Supporting PR #63: Parallel test fix
├─ Sub-Issue #64: PR-5 Person API Endpoints
├─ Sub-Issue #65: PR-6 Key Rotation & Maintenance
└─ Sub-Issue #67: PR-7 Hardening & Cleanups (closes #50)

Post-completion:
└─ Sub-Issue #68: DRY improvements & checklist
```

### Benefits

**For Project Tracking:**

- ✅ Each sub-issue has independent status in project board
- ✅ Epic automatically shows "5 of 7 complete" progress
- ✅ No manual status updates needed
- ✅ Clear which parts are done vs in-progress vs blocked

**For Contributors:**

- ✅ Clear focus: each sub-issue = one PR's worth of work
- ✅ Dependencies explicit: "Depends on: #52"
- ✅ Supporting PRs can link to sub-issues (not epic)
- ✅ New contributors can pick up any sub-issue

**For Reviews:**

- ✅ Smaller, focused PRs easier to review
- ✅ Each sub-issue has specific acceptance criteria
- ✅ Less context-switching for reviewers
- ✅ Can review PRs in parallel (if no dependencies)

## What Went Well

Despite the tracking issues, Issue #50 was a success:

### Strengths

1. **Clear Requirements**: Epic description had detailed technical specs
2. **Small PRs**: Most PRs stayed under 600 LOC (excluding tests)
3. **TDD Approach**: 114 tests, 332 assertions, 0 failures
4. **Quality Gates**: All PRs passed Pint, PHPStan, PEST
5. **Documentation**: Each PR had clear descriptions and checklists
6. **Iterative Improvements**: Supporting PRs (#59, #61, #63, #68) improved quality

### Metrics

- **Velocity:** 12 PRs in 1 day (highly productive)
- **Quality:** 0 regressions, all tests passing
- **Coverage:** TDD from start, comprehensive test suites
- **Documentation:** README, DEVELOPMENT.md, inline docs updated

## Recommendations for Future Epics

### 1. Use Sub-Issue Pattern from Start

**Before starting work on a large feature:**

1. Create epic issue using organization "Epic" template
2. Break down into sub-issues (~400-600 LOC each)
3. Create sub-issues using organization "Sub-Issue" template
4. Link all sub-issues in epic's tasklist
5. Start work on first sub-issue

**During implementation:**

- Each PR references its sub-issue: `Fixes #<sub-issue-number>`
- Supporting PRs can reference sub-issues or epic: `Part of: #<number>`
- Only the LAST PR closes the epic: `Closes #<epic-number>`

### 2. Plan for Unknowns

Reserve flexibility for unexpected work:

```markdown
## 📋 Sub-Issues & Work Plan

### Planned Implementation

- [ ] #52 PR-1: Migrations
- [ ] #53 PR-2: Models
- [ ] #54 PR-3: API

### Supporting Tasks (created as needed)

- [ ] #61 Bugfix: Encoding issue (discovered during PR-2)
- [ ] #63 Improvement: Test isolation (discovered during testing)
```

### 3. Document Scope Changes

When the plan changes (new sub-issues added):

- Update epic description with new sub-issues
- Add comment explaining why
- Update project board if necessary

### 4. Celebrate Milestones

Comment on the epic after major milestones:

- "✅ Core implementation complete (PRs 1-4 merged)"
- "✅ API endpoints live (PR-5 merged)"
- "🎉 Epic complete! All 7 PRs merged, 114 tests passing"

Keeps team informed and motivated.

## Key Takeaways

### For Project Management

1. **Large features = Epics with sub-issues** (not single issues with multiple PRs)
2. **Automate status tracking** (let GitHub do the work)
3. **Plan for unknowns** (budget time for supporting PRs)
4. **Celebrate milestones** (keep team informed)

### For Development

1. **Small PRs are easier to review** (target <600 LOC)
2. **TDD pays off** (caught issues early, all tests green)
3. **Quality gates work** (Pint, PHPStan, PEST prevented regressions)
4. **Documentation is crucial** (README, DEVELOPMENT.md, inline docs)

### For Process

1. **Templates help** (PR/issue templates ensure consistency)
2. **Checklists work** (pre-PR checklist caught issues)
3. **Iterate on process** (Issue #50 led to EPIC_WORKFLOW.md)
4. **Learn from experience** (this document!)

## Related Documentation

- [EPIC_WORKFLOW.md](./EPIC_WORKFLOW.md) - Complete guide to sub-issue pattern
- [DEVELOPMENT.md](../DEVELOPMENT.md) - Development guidelines
- [Epic Template](https://github.com/SecPal/.github/blob/main/.github/ISSUE_TEMPLATE/epic.yml) - Organization template
- [Sub-Issue Template](https://github.com/SecPal/.github/blob/main/.github/ISSUE_TEMPLATE/sub-issue.yml) - Organization template

## Timeline (for Reference)

- **2025-11-01 16:24:** Issue #50 created
- **2025-11-01 16:54:** PR #51 merged (PR-0)
- **2025-11-01 17:44:** PR #52 merged (PR-1)
- **2025-11-01 18:40:** PR #53 merged (PR-2)
- **2025-11-01 19:12:** PR #55 merged (PR-3)
- **2025-11-01 19:53:** PR #59 merged (process improvements)
- **2025-11-01 20:38:** PR #60 merged (PR-4 Sanctum)
- **2025-11-01 22:38:** PR #61 merged (BYTEA fix)
- **2025-11-01 23:04:** PR #63 merged (parallel test fix)
- **2025-11-01 23:48:** PR #64 merged (PR-5)
- **2025-11-02 00:52:** PR #65 merged (PR-6)
- **2025-11-02 01:23:** PR #67 merged (PR-7) - **Issue #50 closed**
- **2025-11-02 01:52:** PR #68 merged (post-completion DRY)

**Total time:** ~9 hours for 12 PRs (1 developer with Copilot assistance)

---

**Created:** 2025-11-02
**Author:** @kevalyq
**Purpose:** Document lessons learned and guide future epic implementations

<!-- SPDX-FileCopyrightText: 2025 SecPal -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Epic & Sub-Issue Workflow

## Problem Statement

When implementing large features that require multiple PRs (e.g., Issue #50 with 7 PRs), GitHub's automatic project status updates become problematic:

- ❌ Each PR that references `Fixes #50` would close the issue prematurely
- ❌ Project board shows the epic as "Done" after the first PR merges
- ❌ No granular tracking of individual PRs in the project board
- ❌ Difficult to see which parts are complete and which are still pending

## Solution: Sub-Issues Pattern

Use **Sub-Issues** (GitHub's native tasklist feature) to break epics into trackable chunks:

```text
Epic Issue #50 (stays open)
├─ Sub-Issue #52: PR-1 Migrations
├─ Sub-Issue #53: PR-2 TenantKey Model
├─ Sub-Issue #55: PR-3 Middleware
├─ Sub-Issue #60: PR-4 Sanctum Auth
├─ Sub-Issue #64: PR-5 API Endpoints
├─ Sub-Issue #65: PR-6 Key Rotation
└─ Sub-Issue #67: PR-7 Hardening (closes epic)
```

## Workflow

### 1. Create Epic Issue

Use the organization template in GitHub: **"🗺️ Epic (Multi-PR Feature)"**

```bash
# Via GitHub UI: New Issue → Choose "Epic" template
# Or via GitHub CLI:
gh issue create --template epic.yml --title "[EPIC] Multi-tenant encryption"
```

Key sections:

- **Goal**: High-level feature description
- **Sub-Issues & Work Plan**: Tasklist linking to sub-issues
- **Acceptance Criteria**: When is the epic complete?
- **Non-Goals**: What's explicitly out of scope?

### 2. Create Sub-Issues

Use the organization template: **"📦 Sub-Issue (Part of Epic)"**

For each logical chunk of work (~400-600 LOC):

```bash
gh issue create \
  --template sub-issue.yml \
  --title "PR-1: Migrations & Base Schema" \
  --label "type/sub-issue,area/database"
```

**Sub-issue checklist:**

- [ ] Links to parent epic: Enter epic number in form
- [ ] Clear, focused goal (one PR's worth of work)
- [ ] Acceptance criteria specific to this sub-issue
- [ ] Dependencies noted (if any)

### 3. Update Epic with Sub-Issue Links

Edit the epic's tasklist to link sub-issues:

```markdown
## 📋 Sub-Issues & Work Plan

- [ ] #52 PR-1: Migrations & Base Schema
- [ ] #53 PR-2: TenantKey Model & KEK Management
- [ ] #55 PR-3: Tenant Middleware & RBAC
- [ ] #60 PR-4: Sanctum Auth
- [ ] #64 PR-5: API Endpoints
- [ ] #65 PR-6: Key Rotation & Maintenance
- [ ] #67 PR-7: Hardening & Cleanups
```

GitHub will automatically:

- ✅ Show progress: "2 of 7 tasks complete"
- ✅ Check off completed sub-issues
- ✅ Track each sub-issue separately in project boards

### 4. Create PRs Linked to Sub-Issues

**Each PR description should reference its sub-issue:**

```markdown
## Summary

Implements migrations for envelope key management.

## Related Issues

Fixes #52
Part of: #50
```

**⚠️ Critical:** Use `Fixes #<sub-issue>`, NOT `Fixes #<epic>`!

**Only the LAST PR** closes the epic:

```markdown
Fixes #67
Closes #50 ← Only in the final PR!
```

### 5. Project Board Automation

With this pattern, GitHub Projects will:

- ✅ Track each sub-issue independently with its own status
- ✅ Show epic progress automatically (e.g., "4/7 complete")
- ✅ Keep epic in "In Progress" until all sub-issues are closed
- ✅ Move epic to "Done" only when the last sub-issue closes

## Best Practices

### Epic Sizing

**Good epic candidates:**

- ✅ Large features requiring 3+ PRs
- ✅ Multi-phase implementations (setup → core → integration → docs)
- ✅ Features with clear milestones
- ✅ Work spanning multiple areas (DB, API, auth, etc.)

**Not suitable for epics:**

- ❌ Single-PR features (just use a regular issue)
- ❌ Bug fixes (unless they require major refactoring)
- ❌ Simple refactorings

### Sub-Issue Sizing

Keep sub-issues focused:

- ✅ ~400-600 LOC per PR (excluding tests)
- ✅ Single logical unit (e.g., "Add migrations" not "Add migrations and API endpoints")
- ✅ Independently reviewable and testable
- ✅ Can be merged without breaking main branch

### Labeling Strategy

Apply consistent labels for filtering:

**Epic labels:**

- `type/epic` (automatically applied by template)
- Area labels: `area/api`, `area/security`, `area/database`
- Priority: `priority/high`, `priority/medium`, `priority/low`

**Sub-issue labels:**

- `type/sub-issue` (automatically applied by template)
- Same area/priority labels as parent epic
- Additional: `good first issue`, `breaking-change`, etc.

### Dependencies Between Sub-Issues

Document dependencies explicitly in the sub-issue template:

```markdown
## 🔗 Dependencies

- Depends on: #52 (migrations must exist first)
- Blocks: #55, #60 (middleware needs these keys)
```

Helps contributors understand the order of work.

## GitHub Features Used

### Tasklists 2.0

GitHub's native sub-issue feature:

- Checkboxes with issue references: `- [ ] #123`
- Auto-updates when referenced issues close
- Visual progress tracking
- Nested tasklists supported

**Documentation:**
<https://docs.github.com/en/issues/managing-your-tasks-with-tasklists/about-tasklists>

### Issue Keywords

Control when issues close:

- `Fixes #123` → Closes issue when PR merges
- `Closes #123` → Same as Fixes
- `Resolves #123` → Same as Fixes
- `Part of: #123` → Links without closing (use in PR description)
- `Related to: #123` → Links without closing

**Documentation:**
<https://docs.github.com/en/issues/tracking-your-work-with-issues/linking-a-pull-request-to-an-issue>

## Real-World Example: Issue #50

### Epic Structure

```markdown
# Issue #50: Multi-tenant security, encryption & Sanctum auth

## Work Plan

- [x] #52 PR-1: Migrations & Base Schema
- [x] #53 PR-2: TenantKey Model & KEK Management
- [x] #55 PR-3: Tenant Middleware & RBAC
- [x] #60 PR-4: Sanctum Auth
- [x] #64 PR-5: API Endpoints
- [x] #65 PR-6: Key Rotation
- [x] #67 PR-7: Hardening & Cleanups
```

### What Went Wrong (Before This Pattern)

1. All PRs referenced `Fixes #50` or mentioned it in descriptions
2. No clear tracking of which parts were done
3. Epic closed prematurely (manually reopened)
4. Project board showed inconsistent status
5. Difficult to see progress at a glance

### How It Should Have Been Done

1. Create epic #50 using the organization template
2. Create 7 sub-issues (#52, #53, #55, #60, #64, #65, #67)
3. Update #50 tasklist with sub-issue references
4. Each PR uses `Fixes #<sub-issue-number>`
5. Only PR #67 uses `Closes #50`
6. GitHub automatically tracks progress

### Lessons Learned

- ✅ Sub-issues provide granular tracking without overhead
- ✅ Project boards work better with many small issues than one large issue
- ✅ Clear parent-child relationship makes dependencies obvious
- ✅ Automated progress tracking reduces manual status updates
- ⚠️ Requires upfront planning (create sub-issues before starting work)

See [ISSUE50_RETROSPECTIVE.md](./ISSUE50_RETROSPECTIVE.md) for full analysis.

## Retrofitting Existing Epics

If you have an in-progress epic:

### Option 1: Document for History (Recommended)

Add a section to the epic:

```markdown
## 📚 Retrospective

This epic was completed using direct PR references instead of sub-issues.
For future epics, we now use the Sub-Issue pattern (see docs/EPIC_WORKFLOW.md).

### Completed PRs:

- PR #52: Migrations & Base Schema ✅
- PR #53: TenantKey Model ✅
  ...
```

### Option 2: Create Sub-Issues Retroactively (Not Recommended)

Only if you need granular project board history for metrics/velocity analysis.

## Tools & Commands

### GitHub CLI (gh)

```bash
# Create epic
gh issue create --repo SecPal/api --template epic.yml --title "[EPIC] Feature name"

# Create sub-issue
gh issue create --repo SecPal/api --template sub-issue.yml --title "PR-1: Description"

# View epic with sub-issues
gh issue view 50

# List all epics
gh issue list --label "type/epic"

# List all sub-issues for an epic (requires manual checking)
gh issue list --label "type/sub-issue" --search "Part of: #50"
```

### Automation Ideas (Future)

- Script to auto-create sub-issues from epic tasklist
- GitHub Action to enforce "only last PR closes epic" rule
- Bot to update epic progress in comments
- Dashboard showing all epics and their progress

## FAQ

### Q: Can I nest epics (sub-epics)?

**A:** Yes, but keep it simple (2 levels max). Use sparingly; flat structure is usually clearer.

### Q: What if a sub-issue needs to be split into multiple PRs?

**A:** Two options:

1. **Recommended:** Original sub-issue becomes a mini-epic with its own sub-issues
2. **Simple:** Create multiple PRs that all reference the same sub-issue (last one uses `Fixes`)

### Q: Can I close a sub-issue without a PR?

**A:** Yes! Not everything needs a PR:

- Research tasks (close with findings in a comment)
- Configuration changes done directly
- Documentation-only updates

### Q: What if I forget and use `Fixes #<epic>` in a middle PR?

**A:** Two options:

1. **Before merge:** Edit PR description to use `Part of: #<epic>` instead
2. **After merge:** Reopen the epic manually, add comment explaining

### Q: Where are the templates?

**A:** The Epic and Sub-Issue templates are **organization-wide** templates in the `SecPal/.github` repository. They're automatically available when you create a new issue in any SecPal repository.

## Related Documentation

- [GitHub Tasklists Documentation](https://docs.github.com/en/issues/managing-your-tasks-with-tasklists/about-tasklists)
- [Linking PRs to Issues](https://docs.github.com/en/issues/tracking-your-work-with-issues/linking-a-pull-request-to-an-issue)
- [GitHub Projects Automation](https://docs.github.com/en/issues/planning-and-tracking-with-projects/automating-your-project)
- [DEVELOPMENT.md](../DEVELOPMENT.md) - General contribution guidelines
- [CONTRIBUTING.md](../CONTRIBUTING.md) - How to contribute
- [ISSUE50_RETROSPECTIVE.md](./ISSUE50_RETROSPECTIVE.md) - Detailed case study

## Feedback & Improvements

This pattern was developed after completing Issue #50. If you have suggestions for improvement, please:

1. Open a discussion in the repo
2. Or create an issue with label `process-improvement`
3. Or submit a PR updating this document

**Maintainer:** @kevalyq
**Last Updated:** 2025-11-02

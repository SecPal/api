<!--
SPDX-FileCopyrightText: 2025 SecPal Contributors
SPDX-License-Identifier: CC0-1.0
-->

# Copilot Reminder Patterns

Quick prompts to reinforce core principles during development sessions.

## 🚀 Quick Reminders

### Start of Session

```text
@workspace Review our 5 core principles in .github/copilot-instructions.md before we start.
```

### Before Major Changes

```text
⚠️ STOP: Confirm you've checked:
1. Quality First - Is this the cleanest solution?
2. TDD - Have you written the test first?
3. DRY - Does similar code already exist?
4. Clean First - Should we refactor before adding features?
5. Self Review - Will this pass all quality gates?
```

### Before Committing

```text
Run the pre-push checklist from copilot-instructions.md before I commit.
```

### When I Catch Violations

```text
You violated [PRINCIPLE]. Re-read our core principles and try again.
```

## 📋 Detailed Checklists

### Feature Implementation Checklist

Copy-paste this when starting a new feature:

```markdown
- [ ] **TDD**: Written failing test first
- [ ] **DRY**: Checked for existing similar code
- [ ] **Quality**: Code is clean and readable
- [ ] **Edge Cases**: Tested nulls, empty values, invalid input
- [ ] **Constants**: No magic numbers
- [ ] **Tests**: All tests pass
- [ ] **Static Analysis**: PHPStan passes
- [ ] **Style**: Pint passes
- [ ] **Size**: PR <600 LOC
```

### Refactoring Checklist

Copy-paste this when refactoring:

```markdown
- [ ] **Tests First**: Existing tests still pass before changes
- [ ] **DRY**: Extracted duplicated logic
- [ ] **Clean**: Removed dead code
- [ ] **Readable**: Variable/method names are descriptive
- [ ] **Tests After**: All tests still pass after changes
- [ ] **Coverage**: Added tests for previously uncovered code
```

### Bug Fix Checklist

Copy-paste this when fixing a bug:

```markdown
- [ ] **TDD**: Written regression test that fails
- [ ] **Root Cause**: Identified why bug occurred
- [ ] **Fix**: Minimal change to fix issue
- [ ] **Test**: Regression test now passes
- [ ] **Edge Cases**: Added tests for similar edge cases
- [ ] **One Topic**: Not mixing bug fix with other changes
```

## 🎯 Session Start Template

At the beginning of each session, use this:

```text
Hi! Before we start:
1. Review our 5 core principles (copilot-instructions.md)
2. Check for any failing tests
3. Run boost:update if needed
4. Confirm: You understand TDD is mandatory

Ready?
```

## 🛑 Emergency Brake Pattern

If I'm repeatedly violating principles:

```text
STOP. You're repeatedly violating our principles.

Re-read the MANDATORY CORE PRINCIPLES section in .github/copilot-instructions.md.

For the next change:
1. Explain HOW you'll follow each of the 5 principles
2. Show me the test FIRST
3. Then show me the implementation
4. Then run the self-review checklist

Do NOT proceed until you've confirmed you understand.
```

## 🔄 Periodic Reminders

Every ~5 interactions:

```text
Quick checkpoint: Have we been following TDD and self-review checklist?
```

## 📚 Documentation Reference Pattern

When documentation is unclear:

```text
Our principles say [X], but the code does [Y]. Which is correct?
Let's document the decision in an ADR.
```

## 🎓 Learning Pattern

After catching a violation:

```text
What could we add to copilot-instructions.md to prevent this mistake in the future?
```

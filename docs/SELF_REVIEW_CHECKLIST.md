<!-- SPDX-FileCopyrightText: 2025 SecPal Contributors -->
<!-- SPDX-License-Identifier: CC0-1.0 -->

# Self-Review Checklist

This checklist ensures code quality and consistency **before** creating a PR.

## Phase 1: Functional Review ✅

**Goal:** Ensure the code works correctly.

- [ ] **All tests pass locally**

  ```bash
  ddev exec php artisan test
  ```

- [ ] **PHPStan passes with no errors**

  ```bash
  ddev exec vendor/bin/phpstan analyze
  ```

- [ ] **Pint code style passes**

  ```bash
  ddev exec vendor/bin/pint --test
  ```

  If fails, auto-fix with:

  ```bash
  ddev exec vendor/bin/pint
  ```

- [ ] **REUSE compliance passes**

  ```bash
  reuse lint
  ```

- [ ] **Markdownlint passes (if docs changed)**

  ```bash
  markdownlint-cli2 "**/*.md" "!vendor/**" "!node_modules/**"
  ```

## Phase 2: Pattern Review 🎯

**Goal:** Ensure code matches project conventions.

### 2.1 Compare with Existing Code

- [ ] **Test assertions match existing patterns**

  ```bash
  # Check what assertions existing tests use
  grep -r "assertStatus\|assertOk\|assertJson" tests/Feature/
  ```

  ✅ Use `assertOk()` instead of `assertStatus(200)`

- [ ] **Validation follows existing patterns**

  ```bash
  # Check if similar endpoints use Form Requests
  ls -la app/Http/Requests/
  ```

  ✅ Extract validation into Form Request classes (like `TokenRequest`)

- [ ] **Factory fields match model properties**
  - Read model PHPDoc `@property` annotations
  - Verify factory `definition()` only uses valid fields
  - Check transient properties (e.g., `email_plain` generates `email_enc` + `email_idx`)

- [ ] **Test syntax matches project standard**

  ```bash
  # Check if project uses Pest or PHPUnit
  grep -r "uses(RefreshDatabase" tests/Feature/ | head -1
  ```

  ✅ Use Pest's `it()` or `test()` functions, NOT PHPUnit classes

### 2.2 Code Style Consistency

- [ ] **Laravel spacing conventions**
  - ✅ `if (! $variable)` with space after `!`
  - ❌ NOT `if (!$variable)` without space

- [ ] **Loop patterns**

  ```bash
  # Check how existing tests handle loops
  grep -r "for (\|foreach\|collect.*each" tests/Feature/
  ```

  ✅ Prefer `collect()->each()` over `for` loops in Laravel

- [ ] **Magic numbers extracted to constants**
  - Token lengths, timeouts, limits should be named constants
  - Example: `Str::random(64)` → define `PASSWORD_RESET_TOKEN_LENGTH = 64`

### 2.3 Documentation Accuracy

- [ ] **Comments match implementation**
  - Verify throttle comments (e.g., `throttle:5,60` = "5 per 60 minutes", not "per hour")
  - Check route comments match actual behavior
  - Ensure PHPDoc types match actual types

- [ ] **Inline comments are accurate**
  - Security comments explain why (email enumeration prevention)
  - TODO comments have context and tracking

## Phase 3: Cleanup Review 🧹

**Goal:** Remove temporary/unused code.

- [ ] **No unused imports**

  ```bash
  # PHPStan will catch unused imports
  ddev exec vendor/bin/phpstan analyze --level=9
  ```

- [ ] **No temporary files committed**

  ```bash
  git status
  # Look for: *.bak, *.tmp, .DS_Store, etc.
  ```

- [ ] **No debug code**
  - Remove `dd()`, `dump()`, `var_dump()`
  - Remove commented-out code blocks
  - Remove `Log::debug()` unless intentional

- [ ] **Git diff review**

  ```bash
  git diff --cached
  ```

  - Check for accidental changes
  - Verify all changes are intentional
  - Look for inconsistent spacing/formatting

## Phase 4: Consistency Check 🔍

**Goal:** Ensure new code fits the existing codebase.

### 4.1 Before Writing Tests

```bash
# 1. Check existing test structure
ls -la tests/Feature/Auth/
cat tests/Feature/Auth/AuthTest.php | head -50

# 2. Check assertion patterns
grep -r "assert" tests/Feature/Auth/AuthTest.php | head -10

# 3. Check test naming
grep -r "it(\|test(" tests/Feature/Auth/
```

### 4.2 Before Writing Validation

```bash
# 1. Check if Form Requests exist
ls -la app/Http/Requests/

# 2. Check existing Form Request structure
cat app/Http/Requests/TokenRequest.php

# 3. Use same pattern for new validation
```

### 4.3 Before Writing Factories

```bash
# 1. Read model PHPDoc
cat app/Models/Person.php | grep -A 20 "@property"

# 2. Check existing factory patterns
cat database/factories/UserFactory.php

# 3. Verify transient properties
# Example: email_plain → auto-generates email_enc + email_idx
```

## Phase 5: Pre-Commit Final Check ✅

- [ ] **Run preflight checks**

  ```bash
  git add -A
  git commit -m "..."
  # Pre-push hooks will run automatically
  ```

- [ ] **Review commit message**
  - Follows conventional commits format
  - Describes WHAT and WHY
  - References issue numbers if applicable

- [ ] **Check PR size**

  ```bash
  git diff --stat main...HEAD
  ```

  - If > 600 lines, consider splitting
  - Create `.preflight-allow-large-pr` with justification if needed

---

## 🚨 Common Mistakes to Avoid

1. ❌ **Writing PHPUnit tests in Pest project**
   - Check: `uses(RefreshDatabase::class)` at file top
   - Use: `it()` or `test()` functions

2. ❌ **Using `assertStatus(200)` instead of `assertOk()`**
   - Check: Existing test patterns
   - Reason: Laravel Boost guidelines

3. ❌ **Inline validation instead of Form Requests**
   - Check: Existing `app/Http/Requests/` classes
   - Pattern: Follow `TokenRequest` example

4. ❌ **Spacing: `if (!x)` instead of `if (! x)`**
   - Fix: Run Pint locally
   - Reason: Laravel code style convention

5. ❌ **Committing temporary files (.bak, .tmp)**
   - Fix: Review `git status` before commit
   - Consider: Add to `.gitignore`

6. ❌ **Factory fields not matching model**
   - Fix: Read model PHPDoc before factory
   - Check: Transient properties behavior

7. ❌ **Comments not matching code**
   - Fix: Review all comments for technical accuracy
   - Example: `throttle:5,60` = "5 per 60 minutes", not "per hour"

---

## 📝 Self-Review Template

Copy this to your commit message or PR description:

```markdown
## Self-Review Checklist

### Phase 1: Functional ✅

- [x] All tests pass locally
- [x] PHPStan passes (level 9)
- [x] Pint code style passes
- [x] REUSE compliance passes

### Phase 2: Pattern Review ✅

- [x] Test assertions match existing patterns
- [x] Validation follows Form Request pattern
- [x] Factory fields match model properties
- [x] Test syntax matches project standard (Pest)
- [x] Code style matches Laravel conventions

### Phase 3: Cleanup ✅

- [x] No unused imports
- [x] No temporary files
- [x] Git diff reviewed

### Phase 4: Consistency ✅

- [x] Compared with existing similar code
- [x] Follows established patterns
- [x] Magic numbers extracted to constants (if applicable)

### Notes:

- Followed TokenRequest pattern for Form Requests
- Used assertOk() instead of assertStatus(200)
- Verified all comments match implementation
```

---

## 🎯 When to Use This Checklist

**Always:**

- Before creating a PR
- After implementing a new feature
- After fixing a bug that touched multiple files

**Especially when:**

- Writing tests (check existing patterns)
- Adding validation (check Form Requests)
- Creating factories (check model PHPDoc)
- Implementing new endpoints (check similar endpoints)

---

## 🔗 Related Documentation

- [Production Test Methodology](./PRODUCTION_TEST_PASSWORD_RESET.md)
- [Epic Workflow](../../docs/EPIC_WORKFLOW.md)
- [Copilot Instructions](../../.github/.github/copilot-instructions.md)
- [Contributing Guidelines](../CONTRIBUTING.md)

---

**Remember:** The goal is to catch issues **before** Copilot review, **before** CI, and **before** human reviewers spend time on preventable issues.

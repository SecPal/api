<!--
SPDX-FileCopyrightText: 2025 SecPal Contributors
SPDX-License-Identifier: CC0-1.0
-->

# Development Setup

Quick start guide for SecPal API development.

## ⚠️ Core Principles (READ FIRST)

**These principles are non-negotiable and are enforced in `.github/copilot-instructions.md`:**

1. **🎯 Quality First** - Clean before quick, maintainable before feature-complete
2. **🧪 TDD** - Write failing test FIRST, then implement
3. **🔄 DRY** - Check for existing code before writing new
4. **🧹 Clean Before Quick** - Refactor when you touch code
5. **👀 Self Review Before Push** - Run all quality gates locally

**📋 Quick Reminder Patterns:** See [`docs/COPILOT_REMINDER_PATTERNS.md`](./docs/COPILOT_REMINDER_PATTERNS.md) for prompts to keep Copilot aligned with these principles.

---

## Prerequisites

- PHP 8.4+
- Composer 2.x
- PostgreSQL 15+ (or DDEV for local development)
- VS Code (recommended)

## Installation

See [README.md](./README.md) for full installation instructions.

## Test Database Setup (Automated)

**✅ Fully automated via DDEV hooks** - No manual intervention required!

### How It Works

DDEV automatically creates test databases on every `ddev start` via `.ddev/config.yaml` post-start hook:

- `testing` - Main test database
- `testing_test_1` - Parallel test DB (process 1)
- `testing_test_2` - Parallel test DB (process 2)

### Why Multiple Test Databases?

When running tests in **parallel** (e.g., with `php artisan test --parallel`), Pest uses multiple processes for faster execution. Each process needs its own isolated database to prevent race conditions and data conflicts.

**Configuration:**

- `phpunit.xml` sets `DB_DATABASE=testing` as base name
- When running tests in parallel (e.g., with `php artisan test --parallel`), Pest automatically appends `_test_1`, `_test_2` suffixes to the database name for each process.
- DDEV hook creates all DBs idempotently (checks existence first), so the additional databases are always available if you choose to run tests in parallel.

### How to Run Tests in Parallel

To run your tests in parallel and utilize the additional databases, use:

```bash
php artisan test --parallel
```

This will run your tests across multiple processes, each using its own isolated test database.

### Verification

```bash
# List all databases
ddev psql -c '\l'

# Should show:
# - testing
# - testing_test_1
# - testing_test_2
```

### Troubleshooting

If tests fail with "database does not exist":

```bash
# Restart DDEV to trigger post-start hook
ddev restart

# Or manually verify hook execution
ddev logs -s db | grep "CREATE DATABASE"
```

## IDE Configuration

### VS Code (Recommended)

Add these to your **global** VS Code settings (`Ctrl+Shift+P` → "Preferences: Open User Settings (JSON)"):

```json
{
  "terminal.integrated.shellIntegration.enabled": true,
  "github.copilot.chat.modelContextProtocol.enabled": true,
  "github.copilot.chat.modelContextProtocol.servers": {
    "laravel-boost-secpal": {
      "command": "php",
      "args": ["artisan", "boost:mcp"],
      "cwd": "${workspaceFolder}/api"
    }
  }
}
```

**Why global?** Shell integration and MCP servers must be configured globally, not per-workspace.

### Verify Setup

```bash
# Shell integration should work without warnings
# Open new terminal in VS Code - no "Enable shell integration" message

# Laravel Boost MCP should autocomplete
# In Copilot Chat, type: @laravel-boost-secpal
```

## Laravel Boost

Laravel Boost provides AI context about your project structure, models, routes, and configuration.

### Updating Boost Guidelines

#### ⚠️ Important: Boost requires database access

```bash
# Always run through DDEV (not directly with php artisan)
ddev exec php artisan boost:update

# Why? Boost uses the database for:
# - Caching scan results
# - Analyzing Eloquent models
# - Reading database schema
```

**When to update:**

- After major changes (new models, migrations, routes)
- After pulling changes from team members
- When starting a new feature branch
- If Copilot seems out of sync with current code

**⚠️ Important:** `boost:update` overwrites `boost.json`! If you have custom guidelines in `boost.json`, they will be lost. Always add custom guidelines AFTER running `boost:update`, not before.

**⚠️ Auto-fixing required:** After `boost:update`, the generated `.github/copilot-instructions.md` needs to be auto-fixed for our linting rules:

```bash
ddev exec php artisan boost:update                         # Generate guidelines
npx markdownlint-cli2 --fix .github/copilot-instructions.md  # Fix linting issues
```

### Boost Commands

```bash
ddev exec php artisan boost:update   # Refresh project guidelines
ddev exec php artisan boost:mcp      # Start MCP server (usually automatic via VS Code)
ddev exec php artisan boost:install  # Initial Boost setup
```

## Testing

```bash
./vendor/bin/pest              # Run all tests
./vendor/bin/pest --coverage   # With coverage report
```

## Code Quality

```bash
./vendor/bin/pint              # Auto-fix code style
./vendor/bin/phpstan analyse   # Static analysis
```

### Pre-Push Review Checklist

**Before every `git push`, run this checklist to avoid multi-iteration PRs:**

```bash
# 1. Review your changes
git diff HEAD~1 HEAD | less
# Look for: code duplication, magic numbers, missing constants, unclear variable names

# 2. Run tests (via DDEV for database access)
ddev exec ./vendor/bin/pest
# All tests must pass. Fix intermittent failures (test isolation issues).

# 3. Check code style
ddev exec ./vendor/bin/pint
# Must output "No files need formatting" or auto-fix and commit changes.

# 4. Static analysis
ddev exec ./vendor/bin/phpstan analyse
# Must show "0 errors". Use baseline for unavoidable vendor issues.

# 5. Pre-push hooks will run automatically
git push
# If hooks fail, fix issues and repeat checklist.
```

**Common Issues Caught by This Checklist:**

- ❌ Code duplication (extract to helper functions)
- ❌ Magic numbers (use constants: `SODIUM_CRYPTO_SECRETBOX_KEYBYTES`)
- ❌ Unclear comments (be specific about WHY, not just WHAT)
- ❌ Test isolation failures (use `RefreshDatabase` trait)
- ❌ Missing edge case tests (empty values, invalid UUIDs, etc.)

**Why This Matters:**

- Prevents Copilot review comments from accumulating
- Reduces PR iterations from 4 commits → 1 commit
- Shows respect for reviewers' time
- Catches issues locally before CI

## Best Practices

### Pull Request Guidelines

**Size Matters:**

- Keep PRs focused and reviewable (< 400 lines changed recommended)
- GitHub best practice: ~400 lines maximum for effective review
- Break large features into incremental PRs with clear boundaries

**Incremental Development:**

1. **Foundation first**: Core infrastructure (models, migrations)
2. **Business logic**: Repositories, services, commands
3. **API layer**: Controllers, resources, validation
4. **Security & Polish**: Rate limiting, audit logging, documentation

**Avoid "Foundational" PRs:**

- Large "foundational" PRs (5000+ lines) are hard to review and risky to revert
- Better: Small, tested, merged PRs that build on each other
- Each PR should be independently reviewable and revertable

**Before Merging:**

- ✅ All tests passing
- ✅ Code quality checks (Pint, PHPStan) pass
- ✅ Pre-commit hooks clean
- ✅ No feature creep - resist adding "just one more thing"

### Working with AI (Copilot)

**Decision Fatigue:**

- Complex features can lead to multiple direction changes
- If overwhelmed, reset and start fresh rather than accumulating complexity
- Clear planning upfront prevents scope creep

**Git Discipline:**

- Commit critical fixes separately from feature work
- Use descriptive commit messages (conventional commits)
- Easy to reset: close PR, delete branch, start clean from main

### Pre-PR Quality Checklist

**Before opening a Pull Request, ensure you've completed these checks:**

#### 1. Parallel Test Execution ✅

```bash
# Catches race conditions and test isolation issues
ddev . ./vendor/bin/pest --parallel
```

**Why?** Tests may pass sequentially but fail in parallel (Issue #50: PR #63)

#### 2. Code Duplication Check 🔍

```bash
# Search for duplicated logic that should be extracted
grep -r "function normalize" app/
grep -r "base64_encode.*generateBlindIndex" app/
grep -r "strtolower(trim(" app/

# Or use PHPStan:
ddev . ./vendor/bin/phpstan analyse --no-progress
```

**Action:** Extract duplicated code into traits, helpers, or methods.

#### 3. Magic Numbers & Constants 🔢

```bash
# Search for hardcoded values
grep -r "0700\|sha256\|32\|24\|16" app/ --exclude-dir=vendor
```

**Action:** Replace with named constants:

```php
// ❌ Bad
mkdir($dir, 0700, true);
hash_hmac('sha256', $data, $key);

// ✅ Good
mkdir($dir, self::KEY_DIRECTORY_PERMISSIONS, true);
hash_hmac(self::HMAC_ALGORITHM, $data, $key);
```

#### 4. Quality Gates (Automated) ✅

```bash
# This runs automatically in pre-push hook:
ddev . ./vendor/bin/pint           # PSR-12 style
ddev . ./vendor/bin/phpstan analyse # Level 9 static analysis
ddev . ./vendor/bin/pest            # All tests
```

**Action:** Fix all errors before push.

#### 5. Commit Message Format 📝

Use [Conventional Commits](https://www.conventionalcommits.org/):

```bash
feat: add key rotation commands
fix: resolve test isolation issue
refactor: extract NormalizesPersonFields trait
docs: update README security section
test: add parallel execution tests
```

#### 6. PR Size Discipline 📊

- **Target:** <600 LOC per PR
- **Acceptable exceptions:**
  - Critical bugfix + refactor (must be atomic)
  - Architectural changes (command suite)
  - Vendor migrations (can't be split)

**Action:** If >600 LOC, document justification in PR body.

#### 7. Review ALL Copilot Suggestions 🤖

- Don't blindly accept/reject suggestions
- Understand WHY Copilot suggests changes
- Group related fixes into ONE commit (not iterative pushes)

**Action:** Review → Bulk-fix → Test → Push (not Push → Review → Push → Review)

---

### Quick Pre-PR Command

```bash
# Run this before opening PR:
ddev . ./vendor/bin/pest --parallel && \
ddev . ./vendor/bin/pint && \
ddev . ./vendor/bin/phpstan analyse && \
echo "✅ Ready for PR!"
```

---

### Resolving Copilot Review Comments

**After addressing Copilot review comments, resolve them via the GitHub UI:**

1. **Fix all comments** in your code and push changes
2. **Wait for comments to be outdated** (GitHub auto-detects this)
3. **Resolve threads** via GitHub PR UI (not via comment replies)
4. **Do NOT reply to comments** with "Fixed" - just resolve the thread

**Why not use GraphQL directly?**

- GitHub UI handles thread resolution automatically
- Safer than manual GraphQL mutations
- Integrated with PR review workflow
- Less error-prone

**Common Mistake:**

- ❌ Replying with "Fixed" in comments (clutters thread)
- ✅ Push fix, let GitHub detect outdated comment, resolve thread

## Working with Epics & Multi-PR Features

For large features requiring multiple PRs (like Issue #50 with 7 PRs), use the **Sub-Issue pattern**:

### Quick Start

1. **Create Epic Issue**: Use organization template "🗺️ Epic (Multi-PR Feature)"
2. **Create Sub-Issues**: One per PR using "📦 Sub-Issue (Part of Epic)" template
3. **Link Sub-Issues**: Update epic's tasklist with sub-issue references
4. **PR References**:
   - Each PR: `Fixes #<sub-issue-number>`
   - ONLY last PR: `Closes #<epic-number>`

### Why?

- ✅ Automatic progress tracking in GitHub Projects ("5 of 7 complete")
- ✅ Granular status per PR (not just epic-level)
- ✅ Epic stays open until ALL PRs are merged
- ✅ Clear dependencies between PRs

### Templates

The Epic and Sub-Issue templates are **organization-wide** (in `SecPal/.github`):

- Available when creating issues in any SecPal repository
- YAML format with interactive form fields
- Consistent across all projects

### Full Documentation

See [docs/EPIC_WORKFLOW.md](./docs/EPIC_WORKFLOW.md) for complete guide including:

- When to use epics vs regular issues
- How to create and link sub-issues
- Project board automation
- Real-world example (Issue #50 retrospective)
- Best practices and FAQ

**Related Documentation:**

- [EPIC_WORKFLOW.md](./docs/EPIC_WORKFLOW.md) - Complete workflow guide
- [ISSUE50_RETROSPECTIVE.md](./docs/ISSUE50_RETROSPECTIVE.md) - Case study
- [EPIC_IMPLEMENTATION_SUMMARY.md](./docs/EPIC_IMPLEMENTATION_SUMMARY.md) - Quick reference

## Documentation

- [Database Schema](./docs/database-schema.md)
- [Encryption Strategy](./docs/ENCRYPTION_STRATEGY.md)
- [Database Decisions](./docs/DATABASE_DECISIONS.md)
- [Epic Workflow](./docs/EPIC_WORKFLOW.md) - Multi-PR feature tracking

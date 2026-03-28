<!--
SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
SPDX-License-Identifier: CC0-1.0
-->

# Development Setup

Quick start guide for SecPal API development.

## ⚠️ Core Principles (READ FIRST)

**SecPal follows organization-wide development principles documented centrally.**

### 📚 Read the Full Principles Guide

👉 **[Development Principles & Best Practices](https://github.com/SecPal/.github/blob/main/docs/development-principles.md)**

This guide covers:

- 🎯 Essential Development Principles (Quality First, TDD, DRY, Clean Before Quick, Self Review)
- 🏗️ SOLID Principles (SRP, OCP, LSP, ISP, DIP)
- 🧩 Additional Principles (KISS, YAGNI, Separation of Concerns, Fail Fast)
- 🔒 Security & Best Practices

### 🚀 Quick Reference

**Essential Principles:**

1. **Quality First** - Clean before quick, maintainable before feature-complete
2. **TDD (Test-Driven Development)** - Write failing test FIRST, then implement
3. **DRY (Don't Repeat Yourself)** - Check for existing code before writing new
4. **Clean Before Quick** - Refactor when you touch code
5. **Self Review Before Push** - Run all quality gates locally

**Design Principles:**

- **SOLID** - Single Responsibility, Open/Closed, Liskov Substitution, Interface Segregation, Dependency Inversion
- **KISS** - Keep It Simple, Stupid
- **YAGNI** - You Aren't Gonna Need It
- **Separation of Concerns** - Controller → Service → Repository pattern
- **Fail Fast** - Validate early, use type hints, catch errors at entry points

**Security & Best Practices:**

- **Security by Design** - Input validation always, never log sensitive data
- **Convention over Configuration** - Follow Laravel conventions & PSR-12

**📋 Copilot Reminder Patterns:** See [`docs/COPILOT_REMINDER_PATTERNS.md`](./docs/COPILOT_REMINDER_PATTERNS.md) for prompts to keep Copilot aligned with these principles.

---

## Prerequisites

- PHP 8.4+
- Composer 2.x
- PostgreSQL 16+
- VS Code (recommended)

## Installation

See [README.md](./README.md) for full installation instructions.

## Test Database Setup (Automated)

**✅ Fully automated during local test bootstrap** - No DDEV dependency required.

### How It Works

When you run `php artisan test`, the shared API test bootstrap now ensures the PostgreSQL test database needed by the current process exists before Laravel starts refreshing it:

- `testing` - Main test database
- `testing_test_1` - Parallel test DB (process 1)
- `testing_test_2` - Parallel test DB (process 2)

### Why Multiple Test Databases?

When running tests in **parallel** (e.g., with `php artisan test --parallel`), Pest uses multiple processes for faster execution. Each process needs its own isolated database to prevent race conditions and data conflicts.

**Requirements:**

- Local PostgreSQL server reachable via your `.env`
- The configured PostgreSQL user must be allowed to create databases

**Configuration:**

- `phpunit.xml` sets `DB_DATABASE=testing` as base name
- When running tests in parallel (e.g., with `php artisan test --parallel`), Pest automatically appends `_test_1`, `_test_2` suffixes to the database name for each process.
- The test bootstrap creates the required database idempotently (checks existence first). Parallel workers create their own suffixed `testing_test_<token>` databases as needed.

### How to Run Tests in Parallel

To run your tests in parallel and utilize the additional databases, use:

```bash
php artisan test --parallel
```

This will run your tests across multiple processes, each using its own isolated test database.

### Verification

```bash
# List all databases
psql -h 127.0.0.1 -U "$DB_USERNAME" -d postgres -c '\l'

# Should show:
# - testing
# - testing_test_1
# - testing_test_2
```

### Troubleshooting

If tests fail with database bootstrap errors:

```bash
# Verify the configured PostgreSQL role can create databases
psql -h 127.0.0.1 -U "$DB_USERNAME" -d postgres -c '\du'

# Then rerun the targeted test file
php artisan test tests/Feature/HealthCheckTest.php
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
      "command": "sh",
      "args": ["-c", "cd /absolute/path/to/your/SecPal/api && php artisan boost:mcp"]
      // Note: Replace '/absolute/path/to/your/SecPal/api' with your actual project path!
      // Example: "cd /home/youruser/code/SecPal/api && php artisan boost:mcp"
    }
  }
}
```

**Why global?** Shell integration and MCP servers must be configured globally, not per-workspace.

**Note:** Keep the project path in the shell command so the MCP server starts from the API repository root.

**Important:** After configuring MCP servers for the first time, you need to:

1. Restart VS Code completely
2. Open Copilot Chat
3. Enable the MCP server if it's disabled (it may be disabled by default on first start)
   - In Copilot Chat, type `@` to see available tools
   - If `laravel-boost-secpal` appears grayed out or with a disabled icon
   - Right-click on it and select "Enable" or click the toggle in Chat settings (⚙️ icon)

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
# Run from the API repository root
php artisan boost:update

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
php artisan boost:update                                  # Generate guidelines
npx markdownlint-cli2 --fix .github/copilot-instructions.md  # Fix linting issues
```

### Boost Commands

```bash
php artisan boost:update   # Refresh project guidelines
php artisan boost:mcp      # Start MCP server (usually automatic via VS Code)
php artisan boost:install  # Initial Boost setup
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

# 2. Run tests
php artisan test --parallel
# All tests must pass. Fix intermittent failures (test isolation issues).

# 3. Check code style
./vendor/bin/pint
# Must output "No files need formatting" or auto-fix and commit changes.

# 4. Static analysis
./vendor/bin/phpstan analyse
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
php artisan test --parallel
```

**Why?** Tests may pass sequentially but fail in parallel (Issue #50: PR #63)

#### 2. Code Duplication Check 🔍

```bash
# Search for duplicated logic that should be extracted
grep -r "function normalize" app/
grep -r "base64_encode.*generateBlindIndex" app/
grep -r "strtolower(trim(" app/

# Or use PHPStan:
./vendor/bin/phpstan analyse --no-progress
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
./vendor/bin/pint            # PSR-12 style
./vendor/bin/phpstan analyse # Level 9 static analysis
php artisan test             # All tests
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
php artisan test --parallel && \
./vendor/bin/pint && \
./vendor/bin/phpstan analyse && \
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

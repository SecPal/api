<!--
SPDX-FileCopyrightText: 2025 SecPal Contributors
SPDX-License-Identifier: CC0-1.0
-->

# Development Setup

Quick start guide for SecPal API development.

## Prerequisites

- PHP 8.4+
- Composer 2.x
- PostgreSQL 15+ (or DDEV for local development)
- VS Code (recommended)

## Installation

See [README.md](./README.md) for full installation instructions.

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

## Documentation

- [Database Schema](./docs/database-schema.md)
- [Encryption Strategy](./docs/ENCRYPTION_STRATEGY.md)
- [Database Decisions](./docs/DATABASE_DECISIONS.md)

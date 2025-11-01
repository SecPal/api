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

## Documentation

- [Database Schema](./docs/database-schema.md)
- [Encryption Strategy](./docs/ENCRYPTION_STRATEGY.md)
- [Database Decisions](./docs/DATABASE_DECISIONS.md)

<!--
SPDX-FileCopyrightText: 2025-2026 SecPal
SPDX-License-Identifier: CC0-1.0
-->

# SecPal API

> Laravel backend API for SecPal - Digital guard book and security service management

[![Quality Gates](https://github.com/SecPal/api/actions/workflows/quality.yml/badge.svg)](https://github.com/SecPal/api/actions/workflows/quality.yml)
[![PR Size](https://github.com/SecPal/api/actions/workflows/pr-size.yml/badge.svg)](https://github.com/SecPal/api/actions/workflows/pr-size.yml)
[![codecov](https://codecov.io/gh/SecPal/api/branch/main/graph/badge.svg)](https://codecov.io/gh/SecPal/api)
[![License: AGPL v3+](https://img.shields.io/badge/License-AGPL%20v3+-blue.svg)](https://www.gnu.org/licenses/agpl-3.0)

## About

SecPal API is the backend service for the SecPal platform, built with Laravel 13 and PostgreSQL. It provides a RESTful API for managing security service operations, guard books, and related functionality.

## Tech Stack

- **Framework:** Laravel 13
- **Database:** PostgreSQL
- **Testing:** PEST
- **Code Style:** Laravel Pint (PSR-12)
- **Static Analysis:** PHPStan (Level Max) with Larastan
- **PHP Version:** 8.4+

## Key Features

### 🔐 Authentication (Laravel Sanctum)

SecPal uses **Laravel Sanctum** with dual authentication modes:

1. **httpOnly Cookie Authentication (SPA Mode)** - Recommended for browser-based SPAs
   - XSS-resistant with httpOnly cookies
   - CSRF protection via Laravel's built-in middleware
   - Session-based authentication for React PWA

2. **Bearer Token Authentication** - For API clients
   - Personal Access Tokens (PAT) for mobile apps
   - Token-based authentication for CLI tools and integrations

**Quick Start:**

```bash
# SPA: Get CSRF token, then login
GET /sanctum/csrf-cookie
POST /v1/auth/login { "email": "...", "password": "..." }
POST /v1/auth/logout

# API Clients: Get Bearer token
POST /v1/auth/token { "email": "...", "password": "...", "device_name": "mobile" }
# Use: Authorization: Bearer {token}

# Self-service (both session and Bearer auth)
GET /v1/me
```

### Official Auth / Self-Service Surface

| Client type                           | Login endpoint        | Logout endpoint        | Self-service endpoint |
| ------------------------------------- | --------------------- | ---------------------- | --------------------- |
| Browser / first-party SPA             | `POST /v1/auth/login` | `POST /v1/auth/logout` | `GET /v1/me`          |
| Android / native / CLI / integrations | `POST /v1/auth/token` | `POST /v1/auth/logout` | `GET /v1/me`          |

The following guessed aliases are intentionally not part of the public surface and return `404 Not Found`: `GET /v1/auth/me`, `GET /v1/user`, `GET /v1/user/profile`, and `GET /v1/profile`.

**Documentation:** [Authentication API Guide](docs/api/authentication.md)

### 🔐 Role-Based Access Control (RBAC)

Comprehensive RBAC system with temporal role assignments and direct permission management.

**Features:**

- **5 Predefined Roles**: Admin, Manager, Guard, Client, Works Council
- **52 Permissions** across 7 resources (employees, shifts, work_instructions, roles, permissions, works_council, reports)
- **Temporal Role Assignments**: Assign roles with `valid_from`/`valid_until` dates for automatic expiration
- **Direct Permissions**: Assign permissions directly to users, bypassing roles for fine-grained control
- **Permission Inheritance**: User permissions = Role permissions ∪ Direct permissions
- **Idempotent Seeder**: Predefined roles auto-recreate if deleted
- **16 REST API Endpoints**: Full CRUD for roles, permissions, assignments, and direct permissions

**API Examples:**

```bash
# List all roles with counts
GET /v1/roles

# Assign role to user with expiration
POST /v1/users/{id}/roles
{
  "role": "Manager",
  "valid_from": "2025-11-15T00:00:00Z",
  "valid_until": "2025-12-31T23:59:59Z"
}

# Assign direct permission (bypass role)
POST /v1/users/{id}/permissions
{
  "permissions": ["employees.export", "reports.generate"]
}

# List user's all permissions (role + direct)
GET /v1/users/{id}/permissions
# Returns: { "via_roles": [...], "direct": [...], "all": [...] }
```

**Documentation:**

- [Role Management Guide](docs/guides/role-management.md)
- [Permission System](docs/guides/permission-system.md)
- [Temporal Roles](docs/guides/temporal-roles.md)
- [Direct Permissions](docs/guides/direct-permissions.md)
- [API Reference](docs/api/rbac-endpoints.md)

### 👥 Employee Status And Invitation Rules

SecPal uses exactly five valid employee lifecycle statuses:

- `applicant`: Candidate record only. No onboarding invitation and no onboarding portal access yet.
- `pre_contract`: Contract preparation phase. This is the only status where onboarding is allowed and `send_invitation: true` may be used.
- `active`: Employee is active. Onboarding invitations are no longer allowed.
- `on_leave`: Employee is temporarily absent but still employed. Onboarding invitations are not allowed.
- `terminated`: Employment has ended. Onboarding invitations are not allowed.

Admin rule of thumb:

- Use `pre_contract` if you want to invite the employee into onboarding.
- Do not rely on form submission to discover the rule. The UI should explain the restriction before submit, and the API rejects `send_invitation: true` for every status other than `pre_contract`.
- Filtering and validation use the same official status set: `applicant`, `pre_contract`, `active`, `on_leave`, `terminated`.

### 🔒 Envelope Encryption

- PHP 8.4 or higher
- Composer 2.x
- PostgreSQL 15+ or 16+
- Extensions: `mbstring`, `xml`, `ctype`, `iconv`, `intl`, `pdo_pgsql`

## Installation

### 1. Clone the repository

```bash
git clone https://github.com/SecPal/api.git
cd api
```

### 2. Install dependencies

```bash
composer install
```

### 3. Configure environment

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` and configure your database:

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=secpal
DB_USERNAME=your_username
DB_PASSWORD=your_password
```

### 4. Run migrations

```bash
php artisan migrate
```

### 5. Security: Generate Key Encryption Key (KEK)

SecPal uses envelope encryption for sensitive data. You must generate a KEK file before running the application:

```bash
# Create the keys directory if it doesn't exist
mkdir -p storage/keys

# Generate a random 256-bit KEK and store it securely
php -r "file_put_contents('storage/keys/kek.key', random_bytes(32));"
chmod 0600 storage/keys/kek.key
```

**IMPORTANT:** Never commit the KEK file! It's already in `.gitignore`.

Add the KEK path to your `.env`:

```env
KEK_PATH=storage/keys/kek.key
```

> For development, use the relative path above. In production, set `KEK_PATH` to the absolute path of your KEK file (ideally outside the web root), and ensure file permissions are `0600`.

#### Key Rotation

SecPal provides Artisan commands for key lifecycle management:

```bash
# Generate new tenant with envelope keys
php artisan keys:generate-tenant

# Rotate KEK and re-wrap all tenant keys (creates backup)
php artisan keys:rotate-kek

# Rotate DEK for specific tenant (re-encrypts all data)
php artisan keys:rotate-dek {tenant_id}

# Rebuild blind indexes for specific tenant
php artisan idx:rebuild {tenant_id}
```

**Best Practices:**

- Rotate KEK annually or after suspected compromise
- Rotate tenant DEKs when offboarding users with access
- Keep KEK backups (created by `keys:rotate-kek`) in secure offline storage
- Test rotation procedures in staging before production

### 6. Set up development tools

```bash
# Install pre-commit hooks
./scripts/setup-pre-commit.sh

# Install pre-push hooks
./scripts/setup-pre-push.sh
```

## Production Deployment

**For production deployment**, see the comprehensive guides:

- 📖 [Production Deployment Guide](docs/deployment.md) - Complete setup instructions
- ✅ [Deployment Checklist](docs/deployment-checklist.md) - Quick reference checklist
- 🌐 [Uberspace Deployment](docs/deployment-uberspace.md) - Uberspace-specific guide

**Key differences from development:**

- Use `composer install --no-dev --optimize-autoloader`
- Set `APP_ENV=production` and `APP_DEBUG=false`
- Store KEK outside web root with `0600` permissions
- Run `php artisan tenant:setup` for tenant key initialization
- Verify deployment with `php artisan app:validate-setup`
- Monitor health checks: `/health/ready` and `/health/live`

## Development

### Running the development server

```bash
php artisan serve
```

The API will be available at <http://localhost:8000>.

### Code Quality

**Code Style (Laravel Pint):**

```bash
# Check code style
./vendor/bin/pint --test

# Auto-fix code style
./vendor/bin/pint
```

**Static Analysis (PHPStan):**

```bash
./vendor/bin/phpstan analyse
```

**Testing (PEST):**

```bash
# Run all tests
./vendor/bin/pest

# Run tests in parallel
./vendor/bin/pest --parallel

# Run specific test
./vendor/bin/pest --filter=ExampleTest

# Run with coverage (requires pcov or xdebug extension)
./vendor/bin/pest --coverage --min=80
```

**Note:** Coverage requires `pcov` (preferred) or `xdebug`. Install via:

```bash
# For pcov (faster, recommended)
pecl install pcov
sudo sh -c 'echo "extension=pcov.so" > /etc/php/$(php -r "echo PHP_MAJOR_VERSION.\".\".PHP_MINOR_VERSION;")/cli/conf.d/99-pcov.ini'

# If you do not have root privileges, add to your user-level php.ini:
# Find your php.ini location: php --ini
# Add: extension=pcov.so
```

CI workflows automatically have coverage enabled.

### Pre-commit Checks

Before committing, the following checks run automatically:

- REUSE compliance
- Code formatting (Prettier, markdownlint)
- YAML linting

### Pre-push Checks

Before pushing, the preflight script runs:

- All pre-commit checks
- Laravel Pint code style check
- PHPStan static analysis
- ⚡ Tests **skipped by default** (run in CI)
- PR size check (600 lines)

To run manually:

```bash
# Fast mode (no tests, ~30s)
./scripts/preflight.sh

# With tests (~5 min)
PREFLIGHT_RUN_TESTS=1 ./scripts/preflight.sh
```

To bypass (not recommended):

```bash
git push --no-verify
```

### Preflight Checklist

Before each commit/PR, ensure:

- ✅ KEK file exists at `storage/keys/kek.key` with permissions `0600`
- ✅ `.env` has `KEK_PATH` set correctly
- ✅ Database connection is configured and migrations ran
- ✅ `./vendor/bin/pint` passes (PSR-12) - **auto-checked**
- ✅ `./vendor/bin/phpstan analyse` passes (level max) - **auto-checked**
- ✅ Tests pass: `ddev exec php artisan test --parallel` - **runs in CI**
- ✅ No hardcoded secrets in code
- ✅ REUSE compliance (all files have license headers) - **auto-checked**

💡 **Tip:** Items marked "auto-checked" run in pre-push hook. Tests run in CI only (for speed).

## Project Structure

```text
api/
├── app/              # Application code
│   ├── Http/         # Controllers, Middleware, Requests, Resources
│   ├── Models/       # Eloquent models
│   └── Providers/    # Service providers
├── config/           # Configuration files
├── database/         # Migrations, factories, seeders
├── routes/           # API routes
├── tests/            # PEST tests
│   ├── Unit/         # Unit tests
│   └── Feature/      # Feature/Integration tests
├── scripts/          # Development scripts
└── storage/          # Logs, cache, uploads
```

## API Documentation

API documentation is maintained separately in the [contracts repository](https://github.com/SecPal/contracts) using OpenAPI 3.1 specification.

## RBAC System

SecPal implements a comprehensive Role-Based Access Control (RBAC) system with temporal role assignments and direct permission management.

### Core Features

- **Role-based access control** with predefined roles (Admin, Manager, Guard, Client, Works Council)
- **Temporal role assignments** with automatic expiration for time-limited access
- **Direct permissions** allowing exceptions without creating new roles
- **All roles are equal** and fully manageable (no system/custom distinction)
- **Idempotent seeder** recreates deleted predefined roles

### Three Core Concepts

#### 1. No System Roles

All roles are equal - predefined roles (Admin, Manager, etc.) can be deleted if not assigned to users. Deleted predefined roles are automatically recreated on next seeder run. This approach provides simplicity and flexibility without artificial distinctions. See [ADR-005](https://github.com/SecPal/.github/blob/main/docs/adr/005-rbac-design-decisions.md) for rationale.

#### 2. Direct Permissions

Users can have permissions assigned directly, bypassing roles entirely. This allows exceptional access without creating single-use roles. Permission hierarchy: `User Permissions = Role Permissions ∪ Direct Permissions`. See [`docs/guides/direct-permissions.md`](docs/guides/direct-permissions.md) for detailed patterns.

#### 3. Temporal Assignments

Role and permission assignments are permanent by default. Temporal constraints (`valid_from`, `valid_until`) are optional for time-limited access (vacation coverage, projects, events). See [`docs/guides/temporal-roles.md`](docs/guides/temporal-roles.md) for use cases.

### Quick Examples

**Assign Permanent Role:**

```bash
POST /v1/users/{id}/roles
{"role": "manager"}
```

**Assign Temporal Role:**

```bash
POST /v1/users/{id}/roles
{
  "role": "manager",
  "valid_until": "2025-12-14T23:59:59Z"
}
```

**Assign Direct Permission:**

```bash
POST /v1/users/{id}/permissions
{"permissions": ["employees.export"]}
```

### Documentation

- **Architecture Overview:** [`docs/rbac-architecture.md`](docs/rbac-architecture.md)
- **Direct Permissions Guide:** [`docs/guides/direct-permissions.md`](docs/guides/direct-permissions.md)
- **Temporal Roles Guide:** [`docs/guides/temporal-roles.md`](docs/guides/temporal-roles.md)
- **Design Decisions:** [ADR-005](https://github.com/SecPal/.github/blob/main/docs/adr/005-rbac-design-decisions.md)
- **API Documentation:** [Issue #140](https://github.com/SecPal/api/issues/140)

## 🤖 Automation

This repository uses automated project board management. Issues and PRs are automatically added to the [SecPal Roadmap](https://github.com/orgs/SecPal/projects/1) with status based on labels and PR state.

**Quick Start:**

```bash
# Create issue (auto-added to project board)
gh issue create --label "enhancement" --title "..."

# Draft PR workflow (recommended)
gh pr create --draft --body "Closes #123"  # → 🚧 In Progress
gh pr ready <PR>                            # → 👀 In Review
gh pr merge <PR> --squash                   # → ✅ Done
```

See [Project Automation docs](https://github.com/SecPal/.github/blob/main/docs/workflows/PROJECT_AUTOMATION.md) for details.

## Contributing

Please read [CONTRIBUTING.md](CONTRIBUTING.md) for details on our code of conduct and the process for submitting pull requests.

### Branch Protection

The `main` branch is protected with the following rules:

- Required status checks must pass
- Pull request reviews required
- Conversations must be resolved
- Force pushes are disabled
- Deletions are disabled
- Branch protections apply to administrators

### Pull Request Guidelines

- Keep PRs small (< 600 lines changed)
- Write descriptive commit messages
- Include tests for new features
- Update documentation as needed
- Ensure all CI checks pass

## License

This project uses a dual-licensing model:

### Open Source License

Licensed under [AGPL-3.0-or-later](LICENSES/AGPL-3.0-or-later.txt) for:

- Open source projects compliant with AGPL
- Personal use and experimentation
- Educational purposes
- Community contributions

### Commercial License

For use cases incompatible with AGPL, commercial licenses are available.
Contact us for details.

See [LICENSE](LICENSE) for full details.

## Security

### Encryption Architecture

SecPal implements **multi-tenant envelope encryption** with the following security properties:

**Key Hierarchy:**

- **KEK (Key Encryption Key)**: Master key stored in `storage/keys/kek.key` (mode 0600)
- **Per-Tenant DEK**: Data Encryption Key for encrypting PII fields (email, phone, notes)
- **Per-Tenant idx_key**: Index key for generating blind indexes (searchable without decryption)

**Encrypted Fields:**

- `email_enc`, `phone_enc`, `note_enc` - Encrypted with tenant DEK using XChaCha20-Poly1305
- Stored as JSON: `{"ciphertext": "base64", "nonce": "base64"}`

**Blind Indexes:**

- `email_idx`, `phone_idx` - HMAC-SHA256 of normalized values using idx_key
- Enable equality search without decryption
- Tenant-isolated (same email in different tenants produces different indexes)

### Security Considerations

**✅ What SecPal Protects Against:**

- Database compromise (all PII encrypted at rest)
- Cross-tenant data access (tenant-specific keys + middleware isolation)
- Unauthorized API access (Sanctum PAT authentication + Spatie RBAC)

**⚠️ Known Limitations:**

- **Full-Text Search Leakage**: The `note_tsv` field contains plaintext tokens for FTS. If FTS on notes is required, accept this trade-off or implement separate FTS infrastructure.
- **Blind Index Frequency Analysis**: Repeated values (e.g., common email domains) can be detected through blind index frequency patterns.
- **Application-Level Access**: Authenticated users with proper permissions can decrypt data (by design).

**🔒 Operational Security:**

- Never commit KEK file (already in `.gitignore`)
- Store production KEK outside web root with 0600 permissions
- Use key rotation commands regularly (see "Key Rotation" section above)
- Monitor `storage/logs` for any accidental PII leakage (tests enforce this)
- Backup KEK securely before rotation (kept by `keys:rotate-kek`)

### Reporting Vulnerabilities

See [SECURITY.md](SECURITY.md) for information about reporting security vulnerabilities.

## Code of Conduct

This project adheres to the [Contributor Covenant Code of Conduct](CODE_OF_CONDUCT.md).

## Support

- **Issues:** [GitHub Issues](https://github.com/SecPal/api/issues)
- **Discussions:** [GitHub Discussions](https://github.com/orgs/SecPal/discussions)
- **Documentation:** [Project Wiki](https://github.com/SecPal/api/wiki)

## Translation

SecPal uses [Translation.io](https://translation.io) for managing translations. Translation.io offers free unlimited accounts for open source projects.

## Related Repositories

- [SecPal/.github](https://github.com/SecPal/.github) - Organization-wide settings
- [SecPal/contracts](https://github.com/SecPal/contracts) - OpenAPI specifications
- [SecPal/frontend](https://github.com/SecPal/frontend) - React frontend application

---

**SecPal** - Empowering security services with digital solutions.

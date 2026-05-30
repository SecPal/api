<!--
SPDX-FileCopyrightText: 2025-2026 SecPal
SPDX-License-Identifier: CC0-1.0
-->

# SecPal API

> Laravel backend API for SecPal — operations software for German private security services.

[![Quality Gates](https://github.com/SecPal/api/actions/workflows/quality.yml/badge.svg)](https://github.com/SecPal/api/actions/workflows/quality.yml)
[![PR Size](https://github.com/SecPal/api/actions/workflows/pr-size.yml/badge.svg)](https://github.com/SecPal/api/actions/workflows/pr-size.yml)
[![codecov](https://codecov.io/gh/SecPal/api/branch/main/graph/badge.svg)](https://codecov.io/gh/SecPal/api)
[![License: AGPL v3+](https://img.shields.io/badge/License-AGPL%20v3+-blue.svg)](https://www.gnu.org/licenses/agpl-3.0)

## About

SecPal API is the Laravel 13 backend for SecPal — the operations software for German private security services. It provides the RESTful API powering the guard book, shift planning, and operational workflows.

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

`POST /v1/auth/login` is intentionally browser-only. Stateless API-style calls are rejected with `400 Bad Request`, while browser-context login failures such as invalid credentials continue to surface as `422 Unprocessable Entity`.

`POST /v1/auth/logout` is the canonical logout endpoint for both auth modes. Its behavior follows the auth mechanism Sanctum actually resolved for the current request: Bearer-token requests revoke only the current token, while stateful SPA requests invalidate the browser session and clear remember-me state. If a request accidentally carries both cookies and an `Authorization` header, the resolved auth context wins; raw header presence alone must not change logout behavior.

The following guessed aliases are intentionally not part of the public surface and return `404 Not Found`: `GET /v1/auth/me`, `GET /v1/user`, `GET /v1/user/profile`, and `GET /v1/profile`.

**Documentation:** [Authentication API Guide](docs/api/authentication.md)

### 🔐 Role-Based Access Control (RBAC)

Comprehensive RBAC system with temporal role assignments and direct permission management.

**Features:**

- **7 Predefined Roles**: Employee, Employee Read Only, HR, Manager, Guard, Client, Works Council
- **Comprehensive Permissions** across core operational resources, administration, onboarding, and device enrollment
- **Temporal Role Assignments**: Assign roles with `valid_from`/`valid_until` dates for automatic expiration
- **Direct Permissions**: Assign permissions directly to users, bypassing roles for fine-grained control
- **Permission Inheritance**: User permissions = Role permissions ∪ Direct permissions
- **Idempotent Seeder**: Predefined roles auto-recreate if deleted
- **RBAC REST surface**: Role and permission CRUD, user role assignment (including extend), and direct user permissions — see [`docs/api/rbac-endpoints.md`](docs/api/rbac-endpoints.md) and `routes/api.php` (avoid relying on a hand-maintained endpoint count)

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
- [Activity Logging User Guide](docs/ACTIVITY_LOGGING_USER_GUIDE.md)
- [Activity Logging Admin Guide](docs/ACTIVITY_LOGGING_ADMIN_GUIDE.md)
- [Activity Logging Legal Guide](docs/ACTIVITY_LOGGING_LEGAL_GUIDE.md)
- [API Reference](docs/api/rbac-endpoints.md)

### 👥 Employee Status And Invitation Rules

SecPal uses exactly five valid employee lifecycle statuses:

- `applicant`: Candidate record only. No onboarding invitation and no onboarding portal access yet.
- `pre_contract`: Contract preparation phase. This is the only status where onboarding is allowed and `send_invitation: true` may be used.
- `active`: Employee is active. Onboarding invitations are no longer allowed.
- `on_leave`: Employee is temporarily absent but still employed. Onboarding invitations are not allowed, and runtime access is reduced to the read-only baseline until the employee returns.
- `terminated`: Employment has ended. Onboarding invitations are not allowed.

Status rule of thumb:

- Use `pre_contract` if you want to invite the employee into onboarding.
- Do not rely on form submission to discover the rule. The UI should explain the restriction before submit, and the API rejects `send_invitation: true` for every status other than `pre_contract`.
- Filtering and validation use the same official status set: `applicant`, `pre_contract`, `active`, `on_leave`, `terminated`.
- `employment_end_date` and `retention_period_end` are lifecycle-managed retention fields. Do not write them through the generic employee create or patch endpoints; they are derived during the termination / retention workflow.

### 🛂 BWR Manual Authority Submission Workflow

Use the dedicated BWR endpoints as the only supported workflow. Do not write BWR fields through the generic employee `PATCH` endpoint.

1. Keep the employee in `pre_contract` and complete the mandatory BewachV / BWR data set.
2. Trigger `POST /v1/employees/{employee}/bwr/export` once the employee is export-ready.
3. Store or download the returned export file and submit it to the authority outside SecPal.
4. Treat the employee's `bwr_status` as `pending` while the authority decision is outstanding.
5. When approval arrives, call `PUT /v1/employees/{employee}/bwr/status` with `status=active` to set `bwr_status` to `active`, the 7-digit `bwr_id`, and optional approval notes.
6. When the authority rejects, suspends, or revokes the registration, record the corresponding `bwr_status` via the same dedicated status endpoint and keep the explanation in `notes`.

Operational notes:

- Export attempts that still miss mandatory data fail with `422 Employee is not ready for BWR export.` plus field-specific errors.
- Successful export writes the `BWR export generated` audit entry and moves `bwr_status` from `not_registered` to `pending`.
- Successful activation writes the `BWR status updated` audit entry, timestamps `bwr_registered_at`, and auto-deletes the stored ID document copy when it is no longer needed.
- The auto-deletion also writes `ID document copy automatically deleted (BWR active)` to the audit log and queues the HR notification mail.

### 📋 Compliance Alerts And Assignment Blocking

SecPal exposes operational compliance alerts through `GET /v1/employees/compliance-alerts` for HR and dispatch overview screens.

Supported alert sources include:

- expiring or expired non-EU work permits
- expiring ID documents
- expiring firearms, first-aid, fire-safety, and evacuation certifications
- expiring `additional_certifications` entries

Operational rules:

1. Use `compliance_status=warning`, `critical`, or `expired` to focus the overview on the highest active severity per employee.
2. Treat `warning` as advance notice only. These employees still remain assignable.
3. Treat `critical` and `expired` alerts as operational blockers. Site assignment creation rejects those employees with `422` and returns the blocking document list.
4. Use the daily `php artisan employees:send-compliance-alert-notifications` command to queue the employee-facing warning, critical, and first-day-expired mails.

Implementation notes:

- The compliance-alerts endpoint filters the full alert set before pagination so `meta.total`, page boundaries, and alert visibility stay consistent even when non-alert employees exist in the same tenant scope.
- Work-permit alerts are derived from the same `expiring_documents` aggregation that drives assignment blocking and notification delivery, so overview, dispatch, and mail flows stay synchronized.

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

### 5. Security: Generate the KEK and bootstrap the first tenant

SecPal uses envelope encryption for sensitive data. Generate the root KEK first, then bootstrap the first tenant envelope keys:

```bash
php artisan keys:generate-kek
php artisan tenant:setup
```

**IMPORTANT:** Never commit the KEK file. By default it is created at `storage/app/keys/kek.key` and is already ignored by Git.

If you want a non-default location, set it explicitly in `.env` before running the command:

```env
KEK_PATH=/absolute/path/to/kek.key
```

Use `php artisan keys:generate-tenant` only after the KEK already exists and you need additional tenant envelope keys.

#### Key Rotation

SecPal provides Artisan commands for key lifecycle management:

```bash
# Generate the root KEK used by all tenant envelope keys
php artisan keys:generate-kek

# Bootstrap the first tenant on a fresh deployment
php artisan tenant:setup

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
# Run all tests through Laravel's local entrypoint
php artisan test

# Run all tests
./vendor/bin/pest

# Run tests in parallel
./vendor/bin/pest --parallel

# Run specific test
./vendor/bin/pest --filter=ExampleTest

# Run with coverage (requires pcov or xdebug extension)
./vendor/bin/pest --coverage --min=80
```

Local test runs now boot the API with a generated isolated test env file. The repository `.env` is only used as a fallback source for `DB_HOST`, `DB_PORT`, `DB_USERNAME`, and `DB_PASSWORD` when those keys are not already exported in the shell; deployment-only `BOOTSTRAP_*` values and `APP_KEY` no longer need to be toggled for `php artisan test`.

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

- ✅ KEK file exists at `storage/app/keys/kek.key` with permissions `0600`
- ✅ `.env` has `KEK_PATH` set correctly
- ✅ Database connection is configured and migrations ran
- ✅ `./vendor/bin/pint` passes (PSR-12) - **auto-checked**
- ✅ `./vendor/bin/phpstan analyse` passes (level max) - **auto-checked**
- ✅ Tests pass: `php artisan test` - **runs in CI**
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

- **Role-based access control** with predefined roles (Employee, Employee Read Only, HR, Manager, Guard, Client, Works Council)
- **Temporal role assignments** with automatic expiration for time-limited access
- **Direct permissions** allowing exceptions without creating new roles
- **All roles are equal** and fully manageable (no system/custom distinction)
- **Idempotent seeder** recreates deleted predefined roles

### Three Core Concepts

#### 1. No System Roles

All roles are equal - predefined roles (Employee, HR, Manager, etc.) can be deleted if not assigned to users. Deleted predefined roles are automatically recreated on next seeder run. This approach provides simplicity and flexibility without artificial distinctions. See [ADR-005](https://github.com/SecPal/.github/blob/main/docs/adr/005-rbac-design-decisions.md) for rationale.

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

- **KEK (Key Encryption Key)**: Master key stored in `storage/app/keys/kek.key` (mode 0600)
- **Per-Tenant DEK**: Data Encryption Key for encrypting PII fields (email, phone, notes)
- **Per-Tenant idx_key**: Index key for generating blind indexes (searchable without decryption)

**Encrypted Fields:**

- `email_enc`, `phone_enc`, `note_enc` - Encrypted with tenant DEK using XChaCha20-Poly1305
- Stored as JSON: `{"ciphertext": "base64", "nonce": "base64"}`

**Blind Indexes:**

- `email_idx`, `phone_idx` - HMAC-SHA256 of normalized values using idx_key
- Enable equality search without decryption
- Tenant-isolated (same email in different tenants produces different indexes)

**Employee Contact Data:**

- `employees.phone` is stored encrypted as `phone_enc` with a tenant-scoped `phone_idx` blind index.
- `employees.email` and `users.email` currently remain plaintext because authentication, onboarding-link validation, and global email uniqueness still rely on direct exact-value lookups.
- Replacing plaintext employee/user email would require a separate global lookup-key design rather than the current tenant-isolated blind-index pattern.

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

SecPal uses repo-native PO/Gettext catalogs managed through Polyglot. Translate the checked-in catalogs directly or with a gettext editor such as POedit; the Polyglot web UI must not be exposed in production.

## Related Repositories

- [SecPal/.github](https://github.com/SecPal/.github) - Organization-wide settings
- [SecPal/contracts](https://github.com/SecPal/contracts) - OpenAPI specifications
- [SecPal/frontend](https://github.com/SecPal/frontend) - React frontend application

---

**SecPal** - Empowering security services with digital solutions.

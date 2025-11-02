<!--
SPDX-FileCopyrightText: 2025 SecPal
SPDX-License-Identifier: CC0-1.0
-->

# SecPal API

> Laravel backend API for SecPal - Digital guard book and security service management

[![Quality Gates](https://github.com/SecPal/api/actions/workflows/quality.yml/badge.svg)](https://github.com/SecPal/api/actions/workflows/quality.yml)
[![PR Size](https://github.com/SecPal/api/actions/workflows/pr-size.yml/badge.svg)](https://github.com/SecPal/api/actions/workflows/pr-size.yml)
[![License: AGPL v3+](https://img.shields.io/badge/License-AGPL%20v3+-blue.svg)](https://www.gnu.org/licenses/agpl-3.0)

## About

SecPal API is the backend service for the SecPal platform, built with Laravel 12 and PostgreSQL. It provides a RESTful API for managing security service operations, guard books, and related functionality.

## Tech Stack

- **Framework:** Laravel 12
- **Database:** PostgreSQL
- **Testing:** PEST
- **Code Style:** Laravel Pint (PSR-12)
- **Static Analysis:** PHPStan (Level Max) with Larastan
- **PHP Version:** 8.4+

## Requirements

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
- PEST tests with ≥80% coverage
- PR size check (600 lines)

To run manually:

```bash
./scripts/preflight.sh
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
- ✅ `./vendor/bin/pint` passes (PSR-12)
- ✅ `./vendor/bin/phpstan analyse` passes (level max)
- ✅ `./vendor/bin/pest --coverage --min=80` passes
- ✅ No hardcoded secrets in code
- ✅ REUSE compliance (all files have license headers)

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

## Related Repositories

- [SecPal/.github](https://github.com/SecPal/.github) - Organization-wide settings
- [SecPal/contracts](https://github.com/SecPal/contracts) - OpenAPI specifications
- [SecPal/frontend](https://github.com/SecPal/frontend) - React frontend application

---

**SecPal** - Empowering security services with digital solutions.

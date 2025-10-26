<!--<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

SPDX-FileCopyrightText: 2025 SecPal

SPDX-License-Identifier: CC0-1.0<p align="center">

--><a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>

<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>

# SecPal API<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>

<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>

> Laravel backend API for SecPal - Digital guard book and security service management</p>

[![REUSE Compliance](https://img.shields.io/badge/REUSE-compliant-green)](https://reuse.software/)## About Laravel

[![License: AGPL-3.0-or-later](https://img.shields.io/badge/License-AGPL%203.0+-blue.svg)](LICENSE)

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

## About

- [Simple, fast routing engine](https://laravel.com/docs/routing).

SecPal API is the backend service for the SecPal platform, built with Laravel 12 and PostgreSQL. It provides a RESTful API for managing security service operations, guard books, and related functionality.- [Powerful dependency injection container](https://laravel.com/docs/container).

- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.

## Tech Stack- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).

- Database agnostic [schema migrations](https://laravel.com/docs/migrations).

- **Framework:** Laravel 12- [Robust background job processing](https://laravel.com/docs/queues).

- **Database:** PostgreSQL- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

- **Testing:** PEST

- **Code Style:** Laravel Pint (PSR-12)Laravel is accessible, powerful, and provides tools required for large, robust applications.

- **Static Analysis:** PHPStan (Level Max) with Larastan

- **PHP Version:** 8.4+## Learning Laravel

## RequirementsLaravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

- PHP 8.4 or higherYou may also try the [Laravel Bootcamp](https://bootcamp.laravel.com), where you will be guided through building a modern Laravel application from scratch.

- Composer 2.x

- PostgreSQL 15+ or 16+If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

- Extensions: `mbstring`, `xml`, `ctype`, `iconv`, `intl`, `pdo_pgsql`

## Laravel Sponsors

## Installation

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### 1. Clone the repository

### Premium Partners

````bash

git clone https://github.com/SecPal/api.git- **[Vehikl](https://vehikl.com)**

cd api- **[Tighten Co.](https://tighten.co)**

```- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**

- **[64 Robots](https://64robots.com)**

### 2. Install dependencies- **[Curotec](https://www.curotec.com/services/technologies/laravel)**

- **[DevSquad](https://devsquad.com/hire-laravel-developers)**

```bash- **[Redberry](https://redberry.international/laravel-development)**

composer install- **[Active Logic](https://activelogic.com)**

````

## Contributing

### 3. Configure environment

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

````bash

cp .env.example .env## Code of Conduct

php artisan key:generate

```In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).



Edit `.env` and configure your database:## Security Vulnerabilities



```envIf you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

DB_CONNECTION=pgsql

DB_HOST=127.0.0.1## License

DB_PORT=5432

DB_DATABASE=secpalThe Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

DB_USERNAME=your_username
DB_PASSWORD=your_password
````

### 4. Run migrations

```bash
php artisan migrate
```

### 5. Set up development tools

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

The API will be available at `http://localhost:8000`.

### Code Quality

#### Code Style (Laravel Pint)

```bash
# Check code style
./vendor/bin/pint --test

# Auto-fix code style
./vendor/bin/pint
```

#### Static Analysis (PHPStan)

```bash
./vendor/bin/phpstan analyse
```

#### Testing (PEST)

```bash
# Run all tests
./vendor/bin/pest

# Run tests in parallel
./vendor/bin/pest --parallel

# Run specific test
./vendor/bin/pest --filter=ExampleTest

# Run with coverage
./vendor/bin/pest --coverage
```

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
- PEST tests
- PR size check (600 lines)

To run manually:

```bash
./scripts/preflight.sh
```

To bypass (not recommended):

```bash
git push --no-verify
```

## Project Structure

```
api/
├── app/              # Application code
│   ├── Http/         # Controllers, Middleware, Requests, Resources
│   ├── Models/       # Eloquent models
│   ├── Services/     # Business logic
│   └── Repositories/ # Data access layer
├── config/           # Configuration files
├── database/         # Migrations, factories, seeders
├── routes/           # API routes
├── tests/            # PEST tests
│   ├── Unit/         # Unit tests
│   └── Feature/      # Feature/Integration tests
├── scripts/          # Development scripts
└── docs/             # Additional documentation
```

## API Documentation

API documentation is maintained separately in the [contracts repository](https://github.com/SecPal/contracts) using OpenAPI 3.1 specification.

## Contributing

Please read [CONTRIBUTING.md](CONTRIBUTING.md) for details on our code of conduct and the process for submitting pull requests.

### Branch Protection

The `main` branch is protected with the following rules:

- Required status checks must pass:
  - REUSE Compliance
  - License Compatibility
  - Laravel Pint
  - PHPStan
  - PEST Tests
  - Formatting Check
  - CodeQL Analysis
- Pull request reviews (0 required for single maintainer, will increase)
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

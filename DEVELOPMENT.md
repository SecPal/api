<!--
SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
SPDX-License-Identifier: CC0-1.0
-->

# API development and tests

Use the API quick start for a fresh standalone checkout. Herdr assignments
provide their own PHP, Composer and test-database tooling.
[API quick start](README.md#quick-start) ·
[Assigned API validation](https://github.com/SecPal/.github/blob/main/WORKSPACE_SETUP.md#api-php-validation)

## Test database

Use a disposable PostgreSQL 18 test instance. The test bootstrap creates an
isolated configuration and provisions the local `testing` database and
per-process databases for parallel tests. Supply the test connection through
`DB_HOST`, `DB_PORT`, `DB_USERNAME` and `DB_PASSWORD` when the local defaults
differ.

The test role needs permission to create its databases, or ownership of the
pre-created test databases and permission to create tables in their `public`
schemas. [Test bootstrap](tests/bootstrap.php) · [Test case](tests/TestCase.php)

Run `composer test` for the maintained parallel and serial suites. Use
`php artisan test --filter=ExampleTest` to focus on an existing test.
[Composer scripts](composer.json) · [Contributor checks](CONTRIBUTING.md#setup-and-checks)

## Local services

- [Mailpit and mail dispatch](docs/MAIL_SYSTEM.md)
- [Local queue workers and diagnosis](docs/QUEUE_WORKERS.md)
- [Address-data import and versioning](docs/ADDRESS_DATA.md)

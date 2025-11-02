<!--
SPDX-FileCopyrightText: 2025 SecPal
SPDX-License-Identifier: CC0-1.0
-->

# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Translation.io integration for multi-language support (en, de)
- Configuration file `config/translation.php` for Translation.io
- `TRANSLATIONIO_KEY` environment variable for API key management
- Translation management via `php artisan translation:*` commands
- Pint `--test --dirty` workflow in preflight script for CI parity
- Pre-commit hook for Laravel Pint auto-formatting
- CHANGELOG validation in preflight script
- Initial Laravel 12 setup with PostgreSQL support
- PEST testing framework integration
- PHPStan static analysis with Larastan
- Laravel Pint code style checking
- REUSE 3.3 compliance
- GitHub Actions CI/CD workflows
- Pre-commit and pre-push hooks
- API-only configuration (no frontend scaffolding)
- Comprehensive documentation

[unreleased]: https://github.com/SecPal/api/commits/main

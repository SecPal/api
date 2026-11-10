<!--
SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
SPDX-License-Identifier: CC0-1.0
-->

# SecPal API

> SecPal – A guard's best friend

[![Quality Gates](https://github.com/SecPal/api/actions/workflows/quality.yml/badge.svg)](https://github.com/SecPal/api/actions/workflows/quality.yml)
[![PR Size](https://github.com/SecPal/api/actions/workflows/pr-size.yml/badge.svg)](https://github.com/SecPal/api/actions/workflows/pr-size.yml)
[![codecov](https://codecov.io/gh/SecPal/api/branch/main/graph/badge.svg)](https://codecov.io/gh/SecPal/api)
[![License: AGPL v3+](https://img.shields.io/badge/License-AGPL%20v3+-blue.svg)](LICENSES/AGPL-3.0-or-later.txt)

## About

SecPal supports professional security operations, including private security
services, in-house and plant protection, corporate security, and comparable
operational security organisations. This repository contains the Laravel API for
the main SecPal product.

## Repository responsibilities

The API owns the server-side application and persistence boundary for SecPal. It
implements operational and workforce workflows, exposes the versioned interfaces
used by browser and native clients, and integrates those workflows with
PostgreSQL-backed state, queues, and scheduled work.

Its principal responsibilities include:

- browser-session and native/API authentication;
- authorization, tenant isolation, and scoped data access;
- transactional domain workflows and sensitive workforce and operational data;
- application-layer encryption and blind-index boundaries;
- audit/activity integrity and privacy-minimized security-event output; and
- the API application and container contract consumed by deployment systems.

The public API contract is maintained separately in
[SecPal/contracts](https://github.com/SecPal/contracts).

## Security-sensitive boundaries

This API is a trusted application boundary for sensitive workforce and
operational data. Requests cross explicit authentication and authorization
boundaries, and tenant context constrains access to tenant-owned state. Selected
sensitive fields are encrypted before persistence, while audit/activity records
and application security events support integrity checks and defensive
monitoring.

The browser/PWA boundary uses maintained session and CSRF protections. Native and
other API clients use the maintained token boundary. Exact authentication,
authorization, encryption, and audit behavior belongs to the linked
documentation and public contract rather than to this README.

## Technology

- PHP 8.4
- Laravel 13
- PostgreSQL 18
- Pest 4

## Quick start

Local development requires PHP 8.4, Composer 2, and PostgreSQL 18. Clone the
repository, create the local environment file, and configure its PostgreSQL
connection before running setup:

```bash
git clone https://github.com/SecPal/api.git
cd api
cp .env.example .env
composer setup
composer dev
```

Run the maintained test entry point with:

```bash
composer test
```

See [Contributing](CONTRIBUTING.md) for the complete local workflow, hooks,
validation, and pull-request requirements.

## Documentation

| Intent                                                   | Authority                                                                                           |
| -------------------------------------------------------- | --------------------------------------------------------------------------------------------------- |
| Public API contract and reference                        | [SecPal/contracts](https://github.com/SecPal/contracts)                                             |
| Authentication                                           | [Authentication API documentation](docs/api/authentication.md)                                      |
| Authorization and RBAC                                   | [RBAC architecture](docs/rbac-architecture.md) and [RBAC API reference](docs/api/rbac-endpoints.md) |
| Encryption implementation patterns                       | [Encryption patterns](docs/guides/encryption-patterns.md)                                           |
| Activity integrity and operations                        | [Activity logging admin guide](docs/ACTIVITY_LOGGING_ADMIN_GUIDE.md)                                |
| Application security-event output                        | [Security-event contract](docs/security-events.md)                                                  |
| BewachV and BWR domain implementation                    | [BewachV compliance documentation](docs/BEWACHV_COMPLIANCE.md)                                      |
| Local development and contribution                       | [Contributing](CONTRIBUTING.md)                                                                     |
| API image and application runtime contract               | [API container](docs/containers.md)                                                                 |
| Self-hosting, host, edge, database, backup, and recovery | [SecPal/deployment](https://github.com/SecPal/deployment)                                           |

## Related repositories

- [SecPal/contracts](https://github.com/SecPal/contracts) — public OpenAPI
  contract.
- [SecPal/frontend](https://github.com/SecPal/frontend) — browser and PWA client.
- [SecPal/android](https://github.com/SecPal/android) — Android client and native
  integration.
- [SecPal/deployment](https://github.com/SecPal/deployment) — integration,
  self-hosting, and deployment contracts.

## Contributing

Read [CONTRIBUTING.md](CONTRIBUTING.md) and the
[Code of Conduct](CODE_OF_CONDUCT.md) before contributing.

## Security

Do not report vulnerabilities in public issues. Use the private reporting path
described in [SECURITY.md](SECURITY.md).

## License

Repository-owned API code is licensed under `AGPL-3.0-or-later` where indicated.
File-level SPDX and [REUSE](REUSE.toml) metadata is authoritative; see
[LICENSE](LICENSE) for the license text.

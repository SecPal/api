<!--
SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
SPDX-License-Identifier: CC0-1.0
-->

# API production boundary

Use [SecPal/deployment](https://github.com/SecPal/deployment) for the supported
self-hosting contract and the Managed reference. Its
[API image consumption contract][consumption] owns release admission, activation
and supported recovery of the paired artifact. The [API container](containers.md)
document describes the PHP-FPM/CLI and Nginx application image pair, its socket,
storage, lifecycle, and build evidence. Nginx inside the pair serves the API
behind the deployment-owned public edge.

## Application inputs

- Provide the API and frontend origins explicitly. Configure exact trusted
  proxies and allowed browser origins. For cookie-based browser authentication,
  apply the stateful-domain and session settings from the
  [authentication guide](api/authentication.md). Native clients use their
  documented bearer-token flow.
- Provide PostgreSQL 18. Use `DB_SSLMODE=verify-full` and
  `DB_SSLROOTCERT=/run/secrets/postgresql-ca.crt` with the deployment-owned CA
  mounted at that path. PostgreSQL backs the application database, shared cache,
  sessions, queues, and locks. Use the deployment's runtime role for application
  work and separate migration authority for schema changes.
- Mount the [production secret bootstrap](containers.md#runtime-architecture)
  for PHP. Provide the existing authorized application key and required
  encryption material when restoring existing state. Follow the deployment
  owner for recovery and key rotation.
- Provide the private storage mount and writable paths described in the
  container contract. Keep private uploads and secret inputs outside the
  public document root.
- If push is enabled, supply each public bootstrap channel's metadata
  separately from backend delivery credentials. The
  [Firebase bootstrap audit](guides/firebase-push-bootstrap-audit.md) describes
  the Android boundary and the separate Web Push public key.

### Passkey configuration

Public browser and native passkey challenges are discoverable-only. Send no
email or other account selector. Set the relying-party ID and allowed origins
for the actual deployment. Passkey enrollment defaults to a required resident
credential. Keep that setting for discoverable sign-in. An existing
non-discoverable credential requires a supported sign-in flow and enrollment of
a discoverable credential.

## Health and lifecycle

Check `/health/live` for Laravel process liveness and `/health/ready` for
application readiness, including the database and tenant key. Use the probes
for each workload role from the container contract. Activation, routing,
database and private state verification, rollback, and recovery follow
[SecPal/deployment](https://github.com/SecPal/deployment).

[consumption]: https://github.com/SecPal/deployment/blob/main/docs/api-image-consumption.md

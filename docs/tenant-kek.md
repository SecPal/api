<!--
SPDX-FileCopyrightText: 2026 SecPal Contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Tenant KEK application interface

The Tenant KEK wraps the separate DEK and blind-index key for each functional
tenant. The external Secret Authority supplies the exact Tenant KEK reference
and a private read-only mount. The existing production bootstrap supplies
`KEK_PATH` as `/run/secpal/secrets/api/tenant-kek`. Supply these metadata values
in the runtime configuration of PHP-FPM, API CLI, workers and the scheduler.

| Setting                  | Value                                                  |
| ------------------------ | ------------------------------------------------------ |
| `TENANT_KEK_ID`          | The assigned stable lowercase UUID of the Tenant KEK   |
| `TENANT_KEK_GENERATION`  | The positive decimal generation number                 |
| `TENANT_KEK_FINGERPRINT` | Lowercase SHA-256 of the exact 32-byte random key file |

Use an owner-only regular file. The existing production bootstrap requires
mode `0600`. The native units map the file to the runtime EUID, `10001`, using
the existing rootless user namespace and SELinux mount policy.
Keep Tenant KEK inputs separate from the
Global Identity Root, Deployment Root KEK, `APP_KEY` and backup keys.

## Clean bootstrap

Supply the external mount and metadata, run the normal PostgreSQL migrations
and invoke `php artisan tenant:setup` in the authorized setup job. This
command atomically records the provisioned generation and creates the first
tenant and its wrapped keys. The standard seeder uses the same provisioning
service. Setup can be repeated with the same committed reference. Supply Global
Identity bootstrap and development fixture inputs separately where required.

## Rewrap and restart

Invoke `php artisan keys:rotate-kek OPERATION_UUID` in the maintenance context.
That context binds the deployment-owned `TenantKekRotationAuthority`. The
authority verifies the approved operation, required recovery retention and
access to both exact generations on separate read-only mounts. It supplies
both references and mount paths. Ordinary runtime has no maintenance authority.

The database commits the new generation and all authenticated tenant envelopes
together. A process with an earlier reference reports unready after the
transaction commits. Restart runtime roles with the exact committed reference
and its read-only mount. If commit acknowledgement is lost, reconcile the same
approved operation. A committed operation confirms the target without rewriting
envelopes. An interrupted transaction can be repeated with both exact inputs.

Keep externally held generations for every retained recovery set. A database
snapshot must be restored alongside the exact key reference and material it
records. The application tracks committed generations and rejects reuse of
previous Tenant KEK material. Custody, Activation, retirement authorization,
and production recovery qualification follow the existing deployment
contracts in SecPal/deployment.

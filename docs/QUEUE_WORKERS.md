<!--
SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
SPDX-License-Identifier: CC0-1.0
-->

# API queue workers

SecPal uses PostgreSQL 18 for durable jobs, batches and failure records.
The [queue configuration](../config/queue.php) selects the PostgreSQL-backed
`database` connection and dispatches jobs after the database transaction
commits. The synchronous connection is available for bounded local and test
use.

## Queues

| Queue                 | Responsibility                                                                                                             |
| --------------------- | -------------------------------------------------------------------------------------------------------------------------- |
| `activity-hash-chain` | Processes the activity hash chain. Keep one consumer for this queue in the single-installation runtime.                    |
| `merkle`              | Builds per-tenant batches of completed activity hashes.                                                                    |
| `opentimestamp`       | Submits batch digests and upgrades proofs, as described in the [OpenTimestamps integration](OPENTIMESTAMP_INTEGRATION.md). |
| `default`             | General background jobs under the default queue configuration.                                                             |

The [deployment repository](https://github.com/SecPal/deployment) owns runtime
placement, scheduling, supervision, health checks and activation. Its
[API image-consumption contract](https://github.com/SecPal/deployment/blob/main/docs/api-image-consumption.md)
defines the PHP image used for workers and the scheduler.

## Local development and diagnosis

Use the workers already assigned to your Herdr preview. In a separate local
environment with its own PostgreSQL database, you can start a worker for the
default queue names if one is not already running:

```bash
php artisan queue:work --queue=activity-hash-chain,merkle,opentimestamp,default
```

To diagnose asynchronous work, inspect the scheduler and worker health in the
owning runtime, compare queue wait time and failed jobs, and use the
corresponding worker logs. `php artisan queue:failed` lists failed jobs.
Before any retry, identify the responsible job and correct its cause.

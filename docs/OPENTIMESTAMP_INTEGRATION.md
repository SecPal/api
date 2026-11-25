<!--
SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
SPDX-License-Identifier: CC0-1.0
-->

# OpenTimestamps integration

SecPal records a hash chain and Merkle inclusion data for supported activity
logs. Calendar submission and proof upgrade can store a Bitcoin attestation
for a batch digest. The application verifies that proof separately when it is
checked. Internal integrity checks remain available whether or not the external
proof can be verified. A verified
external proof is evidence that the committed digest existed by the attested
block. It does not establish who performed an action or guarantee legal
admissibility.

## From activity entry to external proof

1. [BuildMerkleTreeBatch](../app/Jobs/BuildMerkleTreeBatch.php) batches unprocessed
   activity entries with event hashes, grouped by tenant. It includes configured
   log names across retention categories and stores `merkle_batch_id`,
   `merkle_root` and `merkle_proof` on each entry.
2. [SubmitMerkleRootToOpenTimestamp](../app/Jobs/SubmitMerkleRootToOpenTimestamp.php)
   submits only the SHA-256 Merkle root to public calendars. The helper uses the
   OpenTimestamps core library's default calendar list and combines every
   successful calendar response. One successful response is enough to record a
   pending proof in `ots_proof` and set `ots_submitted_at`. When submission
   fails, the job retries and the internal Merkle data remains available.
3. [UpgradeOpenTimestampProofs](../app/Jobs/UpgradeOpenTimestampProofs.php)
   processes pending entries oldest first. It records `ots_confirmed_at` when a
   Bitcoin attestation is saved. The upgrade can remain pending or fail without
   invalidating the internal integrity data.
4. [Activity verification](../app/Models/Activity.php) treats internal chain
   integrity, Merkle inclusion, and external timestamp verification as separate
   results. A recorded confirmation means the proof was upgraded. A current
   successful verification also requires the checks described below.

## Verification and failure handling

[OpenTimestampService](../app/Services/OpenTimestampService.php) delegates to
the focused Python helpers for [submission](../scripts/ots-stamp-hash.py),
[upgrade](../scripts/ots-upgrade.py) and [verification](../scripts/ots-verify.py).
The PHP service accepts a 64-character hexadecimal SHA-256 digest and uses
binary proof bytes. Proof storage uses Base64.

The upgrade helper limits its network requests to approved public HTTPS
calendar destinations. Verification requires agreement from at least two
distinct HTTPS Bitcoin header API origins on the attested height's block hash.
It also validates the raw header hash and the attested commitment against the
block's Merkle root. Insufficient or conflicting quorum, invalid proofs, and
resource-limit failures cannot produce a verified result. The remaining origins
can still verify a proof when a provider is unavailable and quorum is met.

The service caches successful verification for a bounded period. The cache
binds the result to the exact digest, proof, provider configuration and TTL, and
authenticates the verification time with the application key. Once the positive
result expires, verification checks the active chain again. Pending or failed
verification does not create a positive cache entry.

## Runtime and configuration

The [immutable PHP-FPM image](../docker/php-fpm/Containerfile) contains the
[hash-pinned OpenTimestamps core](../docker/python/opentimestamps-requirements.txt).
It does not bundle `opentimestamps-client`, GitPython, gitdb, or smmap. For
image consumption and supervision of the scheduler and workers, see the
[deployment image-consumption contract](https://github.com/SecPal/deployment/blob/main/docs/api-image-consumption.md).

| Setting or operation                 | Current behavior                                                                                                                                                                                |
| ------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `MERKLE_SCHEDULE_FREQUENCY`          | Schedules Merkle batching every minute in local development by default and hourly otherwise. Set `minute` for the every-minute schedule.                                                        |
| `OTS_BITCOIN_HEADER_API_BASES`       | Comma-separated HTTPS, Esplora-compatible API bases. At least two distinct origins are required. Configure independently operated providers, with a third to tolerate one unavailable provider. |
| `OTS_VERIFICATION_CACHE_TTL_SECONDS` | Positive verification expires after one hour by default, with a one-day maximum.                                                                                                                |
| Proof submission and upgrade         | Use the `opentimestamp` queue. The scheduler dispatches upgrades hourly for entries pending for at least one hour, up to 100 per run.                                                           |
| `php artisan ots:check`              | Checks the installed Python runtime and helper files, and submits a test digest to the public calendars. The scheduled `ots:monitor` runs this check every six hours and reports failures.      |

The batching and upgrade schedules are defined in [routes/console.php](../routes/console.php).
Calendar or verifier failure affects the corresponding proof or monitoring
result. Package-index availability is separate from API readiness. Production
dependency updates use reviewed image builds; `ots:update` is an explicit
non-production command and is never scheduled.

## Local verification

In a development shell that has the API's existing PHP dependencies, install
the same locked core used by the image and run the relevant tests:

```bash
python3 -m pip install --only-binary=:all: --require-hashes \
  -r docker/python/opentimestamps-requirements.txt
php artisan test --filter=OpenTimestamp
```

The [administrator](ACTIVITY_LOGGING_ADMIN_GUIDE.md),
[user](ACTIVITY_LOGGING_USER_GUIDE.md) and [legal](ACTIVITY_LOGGING_LEGAL_GUIDE.md)
guides describe how to interpret audit evidence for their respective audiences.

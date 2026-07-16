<!--
SPDX-FileCopyrightText: 2025-2026 SecPal Contributors
SPDX-License-Identifier: CC0-1.0
-->

# OpenTimestamp Integration

SecPal integrates with [OpenTimestamps](https://opentimestamps.org/) to provide blockchain-anchored, cryptographically verifiable audit trail timestamps. This document describes the architecture, security considerations, and operational details of the integration.

## Overview

OpenTimestamps (OTS) creates tamper-proof timestamps by anchoring document digests to the Bitcoin blockchain. SecPal uses OTS to timestamp Level 3 audit logs, providing immutable proof that specific data existed at a particular point in time.

### Key Features

- **Blockchain Anchoring**: Merkle roots are anchored to Bitcoin blocks
- **Independent Verification**: Proofs are checked against a quorum of Bitcoin header APIs
- **Privacy-Preserving**: Only SHA256 digests are submitted to public calendars
- **Performance Optimized**: Bounded caching for verified proofs with periodic active-chain revalidation
- **Fail-Closed**: Falls back to unverified state rather than false positives

## Architecture

### Components

1. **OpenTimestampService** (`app/Services/OpenTimestampService.php`)

   - Handles submission, upgrade, and verification of OTS proofs
   - Uses the OpenTimestamps Python library for submission and bounded verification, plus `ots upgrade` for proof upgrades
   - Implements proof- and provider-bound caching for successful verification decisions
   - Merges every successful calendar submission into one pending proof

2. **ProcessExecutor** (`app/Contracts/ProcessExecutor.php`)

   - Abstraction for executing external commands with explicit process environments
   - Enables testable, mocked process interactions

3. **Jobs**

   - `SubmitMerkleRootToOpenTimestamp`: Submits batch merkle roots to calendars
   - `UpgradeOpenTimestampProofs`: Polls for Bitcoin-anchored proofs

4. **Runtime installation guidance**
   - Documents how to install `opentimestamps-client` in local shells, containers, and production environments

### Calendar Submission and Proof Merging

`scripts/ots-stamp-hash.py` obtains `DEFAULT_AGGREGATORS` from the installed OpenTimestamps Python library and submits to each calendar **sequentially**. Each successful response is merged into the same in-memory timestamp before it is serialized, so the stored pending proof carries every available calendar attestation.

The intended success threshold is **one successful calendar response**. This keeps submission available when some calendars are unavailable while retaining the redundancy contributed by any additional successful responses. Submission fails only when every calendar request fails.

The library owns both the calendar list and the OTS merge operation; SecPal does not select a first response or expose a PHP `mergeProofs()` method.

### Data Flow

```text
1. Audit Logs Created
   ↓
2. BuildMerkleTreeBatch Job (Level 3 logs)
   ↓
3. SubmitMerkleRootToOpenTimestamp Job
   ↓ (Pending Proof stored)
4. UpgradeOpenTimestampProofs Job (runs periodically)
   ↓ (Bitcoin-anchored proof stored)
5. verify() method (with caching)
   ↓
6. Verified Proof (cached for a bounded TTL)
```

## Security Considerations

### Why Bounded External Verification?

**Context**: Issue #412 identified critical vulnerabilities in a previous hybrid verification approach that combined local PHP crypto with HTTP calendar endpoints. The vulnerabilities included:

- Proof forgery through calendar stub manipulation
- Timing-based attacks via upgrade endpoint control
- Man-in-the-middle attacks on unverified calendar responses

**Solution**: Verification uses the official OpenTimestamps Python library plus independent Bitcoin header APIs. The APIs must agree on the block hash for the attested height, the raw header must hash to that block, and the attested commitment must match the header's Merkle root:

```php
// Delegates proof parsing and Bitcoin-header verification to the bounded Python verifier.
$result = $processExecutor->execute(
    ['python3', base_path('scripts/ots-verify.py'), $proofFile, $digest],
    null,
    10,
    ['OTS_BITCOIN_HEADER_API_BASES' => config('services.opentimestamps.bitcoin_header_api_bases')],
);

// ❌ INSECURE: Custom crypto + HTTP calendars (Issue #412 - REMOVED)
// $response = Http::post($calendarUrl . '/verify', ['proof' => $proof]);
```

### Caching Strategy

The proof bytes are immutable once Bitcoin-anchored, but the active-chain observation must be refreshed so a reorganization cannot leave a stale positive decision trusted forever. Therefore:

- ✅ **Cache successful verifications temporarily**: The `v4` key binds the digest to hashes of the exact proof and configured provider set
- ✅ **Authenticate cached decisions**: The application key authenticates the cache context and verification time, so database writes cannot forge or extend a positive result
- ✅ **Version verifier decisions**: A verifier security change uses a new namespace so legacy positive results cannot bypass new checks
- ✅ **Invalidate on context changes**: Replacing the proof, provider configuration, or cache TTL requires fresh verification
- ✅ **Revalidate active-chain state**: Positive decisions expire after `OTS_VERIFICATION_CACHE_TTL_SECONDS` (maximum one day)
- ❌ **Do NOT cache failures**: Pending proofs may upgrade to confirmed later

### Threat Model

| Threat                    | Mitigation                                                                                              |
| ------------------------- | ------------------------------------------------------------------------------------------------------- |
| Proof forgery             | The OTS commitment must match the fetched header's Merkle root                                          |
| Header API compromise     | Exactly one hash may reach quorum; conflicting quorums fail closed, and the raw header hash is verified |
| Man-in-the-middle attacks | HTTPS-only origins, pre-follow redirect checks, API consensus, and raw-header hashing fail closed       |
| Timing attacks            | Fair attestation slices plus dynamic height/header phases preserve later verification work              |
| Partial provider failure  | Truncated reads and other provider-local transport failures do not block healthy APIs                   |
| Provider resource abuse   | Provider responses, proof files, and attestation traversal have strict bounds                           |
| Cache poisoning           | Versioned keys and application-key authenticated timestamps reject forged or extended positive entries  |

## Installation & Setup

### Local Development

Install the `opentimestamps-client` CLI in the same shell environment where you run `php artisan`:

```bash
python3 -m pip install --user opentimestamps-client
# Note: ~/.local/bin must be on your PATH for the `ots` command to be found.
# Add to your shell profile if needed: export PATH="$HOME/.local/bin:$PATH"

# Verify installation
ots --version
# Expected output: v0.7.2 (or newer)
```

If you use a containerized local environment, install the package into that container image and rebuild/restart the container using your normal runtime workflow.

### Production

Install the OpenTimestamps Python client on your server:

```bash
# Debian/Ubuntu
apt-get update && apt-get install -y python3-pip
pip3 install --break-system-packages opentimestamps-client

# Alpine
apk add --no-cache python3 py3-pip
pip3 install opentimestamps-client

# Verify installation
ots --version
```

**Security Note**: Using `--break-system-packages` is safe in Docker containers and necessary for Python 3.11+ (PEP 668). For system-wide installations, consider using a virtual environment.

## Configuration

### Environment Variables

```env
# OpenTimestamp Configuration

# Calendar submission uses the installed OpenTimestamps Python library's
# DEFAULT_AGGREGATORS. SecPal does not read OTS_CALENDAR_URLS or
# OTS_MIN_CALENDAR_RESPONSES. It submits sequentially, merges all successful
# responses, and requires one successful response.

# Bitcoin header API bases used during verification (comma-separated).
# At least two canonical HTTPS origins are required so one configured provider cannot
# substitute a self-consistent header that is not part of the Bitcoin chain.
# DNS-equivalent trailing-dot hostnames are treated as the same origin.
# Configure at least three independently operated, Esplora-compatible origins in
# production so one unavailable provider does not prevent the remaining two from agreeing.
OTS_BITCOIN_HEADER_API_BASES=https://blockstream.info/api,https://mempool.space/api

# Positive verification cache TTL in seconds (default: 3600, maximum: 86400).
# Expiry forces periodic revalidation against the active Bitcoin chain.
OTS_VERIFICATION_CACHE_TTL_SECONDS=3600

# Note: The Python verifier process timeout is hardcoded at 10 seconds in OpenTimestampService
# and is not configurable via environment variable. Calendar submission has a hardcoded
# 15-second process timeout in OpenTimestampService; the Python library owns individual
# calendar requests.
```

### Queue Configuration

OpenTimestamp jobs run on the `opentimestamp` queue:

```bash
# Run dedicated OpenTimestamp queue worker
php artisan queue:work --queue=opentimestamp --tries=3 --timeout=60
```

## Usage

### Programmatic API

```php
use App\Services\OpenTimestampService;

$service = app(OpenTimestampService::class);

// 1. Submit digest to calendars (returns pending proof)
$digest = hash('sha256', 'audit log data');
$pendingProof = $service->submit($digest);
// Base64-encoded OTS proof (calendar attestations)

// 2. Upgrade pending proof to Bitcoin-anchored (after ~1 hour)
$confirmedProof = $service->upgrade($pendingProof);
// Base64-encoded OTS proof with Bitcoin attestation, or null if pending

// 3. Verify proof (checks Bitcoin blockchain)
$isValid = $service->verify($confirmedProof, $digest);
// true if proof is Bitcoin-anchored and digest matches, false otherwise
```

### Jobs

**SubmitMerkleRootToOpenTimestamp** (automatic after Merkle tree build):

- **Purpose**: Submit Merkle roots to OpenTimestamp calendar servers
- **Trigger**: Dispatched by BuildMerkleTreeBatch job
- **Queue**: `opentimestamp`
- **Retry Logic**: 3 attempts with exponential backoff (1s, 2s, 4s)
- **Timeout**: 30 seconds per attempt
- **Failure Behavior**: Re-throws exception to trigger queue retry
- **Result**: Stores pending proof (calendar attestations) in Activity logs

**UpgradeOpenTimestampProofs** (scheduled hourly):

- **Purpose**: Upgrade pending proofs to Bitcoin-confirmed proofs
- **Trigger**: Scheduled via `routes/console.php` (hourly)
- **Queue**: `opentimestamp`
- **Batch Processing**:
  - Processes maximum 100 proofs per run (prevents long-running jobs)
  - Skips recently submitted proofs (<1 hour old) to reduce calendar load
  - Processes oldest proofs first (FIFO fairness)
- **Retry Logic**: 1 attempt (no retry - runs again hourly)
- **Timeout**: 600 seconds (10 minutes for batch)
- **Failure Behavior**: Logs warning, continues processing remaining proofs
- **Result**: Updates confirmed proofs with Bitcoin attestation, sets ots_confirmed_at

**Monitoring Metrics** (logged at completion):

- `processed`: Number of proofs processed in this run
- `upgraded`: Number successfully upgraded to Bitcoin-confirmed
- `still_pending`: Number still pending (Bitcoin not confirmed yet)
- `failed`: Number of upgrade errors
- `success_rate`: Percentage of successful upgrades

Example manual dispatch (normally automatic):

```php
use App\Jobs\SubmitMerkleRootToOpenTimestamp;
use App\Jobs\UpgradeOpenTimestampProofs;

// Submit merkle root (after BuildMerkleTreeBatch job)
SubmitMerkleRootToOpenTimestamp::dispatch($tenantId, $batchId, $merkleRoot);

// Upgrade pending proofs (normally scheduled hourly)
UpgradeOpenTimestampProofs::dispatch();
```

### Caching

Successful verifications use a bounded `v4` cache key that includes the digest plus hashes of the exact proof, configured provider set, and TTL policy. Its value contains an application-key authenticated verification timestamp, which is checked independently of the cache store's expiry. A proof, provider, or TTL change causes immediate fresh verification, forged or artificially extended entries are ignored, and TTL expiry rechecks the proof against the active Bitcoin chain. `OTS_VERIFICATION_CACHE_TTL_SECONDS` defaults to one hour and cannot exceed one day.

```php
// Clear all OTS verification cache
Cache::flush(); // ⚠️ Clears ALL cache, use with caution
```

## Troubleshooting

### Verification Runtime Not Found

**Symptom**: `verify()` returns `false`, logs show that Python is unavailable or the OpenTimestamps module cannot be imported

**Solution**:

```bash
# Local shell / container
python3 -m pip install --user --upgrade opentimestamps-client
# Ensure python3 can import the installed opentimestamps package.

# Production
sudo -H python3 -m pip install --upgrade opentimestamps-client
python3 -c 'import opentimestamps'
pip3 list | grep opentimestamps  # Should show opentimestamps-client
```

### Verification Always Returns False

**Symptom**: `verify()` returns `false` for valid proofs

**Possible Causes**:

1. **Pending Proof (Most Common)**

   - Proof not yet Bitcoin-anchored (~1 hour after submission)
   - Wait and retry `upgrade()`, then `verify()`

2. **Verifier Timeout**

   - Network latency or insufficient quorum across Bitcoin header APIs
   - Check all configured HTTPS providers; requests are capped at two seconds, and height lookups leave two request budgets for header retrieval and one fallback within the fixed shared deadline

3. **Digest Mismatch**

   - Ensure digest is SHA256 hex string (64 characters)
   - Case-insensitive (normalized to lowercase)

4. **Invalid Proof Format**
   - `OpenTimestampService::verify()` expects decoded binary OTS proof bytes
   - Proof must be from official `ots` CLI or SecPal `submit()`

### Calendar Submission Fails

**Symptom**: `submit()` throws `RuntimeException: Failed to submit timestamp`

**Solution**:

1. **Network Connectivity**

   ```bash
   # Test calendar reachability
   curl -I https://alice.btc.calendar.opentimestamps.org
   ```

2. **Firewall Rules**

   - Ensure outbound HTTPS (443) allowed to calendar servers

3. **All Calendar Servers Unavailable**
   - Check calendar server status: <https://opentimestamps.org/>
   - The runtime accepts one successful response and automatically retains all
     additional successful calendar attestations; no application threshold is configurable.

### Performance Issues

**Symptom**: `verify()` takes >5 seconds, p95 latency high

**Solutions**:

1. **Check Caching**

   ```php
   // Recently verified proofs should hit the bounded cache (<10ms)
   Log::info('Cache hit', ['digest' => $digest]);
   ```

2. **Database Query Optimization**

   - Index on `activity_log.opentimestamp_merkle_root`
   - Index on `activity_log.opentimestamp_proof_confirmed`

3. **Network Latency**
   - Use geographically closer Bitcoin nodes
   - Configure responsive, independent Bitcoin header API origins

## Testing

### Unit Tests

```bash
# All OpenTimestamp tests
php artisan test --filter=OpenTimestamp

# Specific test files
php artisan test tests/Unit/Services/OpenTimestampServiceTest.php
php artisan test tests/Feature/OpenTimestampServiceIntegrationTest.php
```

### Integration Tests with the Real Python Runtime

```bash
# Requires python3 and opentimestamps-client
php artisan test tests/Feature/OpenTimestampServiceIntegrationTest.php
```

### Manual Verification

```bash
# 1. Create test proof
echo "test data" | sha256sum | awk '{print $1}' | xxd -r -p | ots stamp - > test.ots

# 2. Verify immediately (should fail - not yet anchored)
echo "test data" | sha256sum | awk '{print $1}' | xxd -r -p | ots verify test.ots -
# Expected: Pending attestation

# 3. Wait 1 hour, upgrade proof
ots upgrade test.ots

# 4. Verify again (should succeed)
echo "test data" | sha256sum | awk '{print $1}' | xxd -r -p | ots verify test.ots -
# Expected: Success! Bitcoin block 12345 attests...
```

## Monitoring & Observability

### Key Metrics

- **Submission Success Rate**: `SubmitMerkleRootToOpenTimestamp` job success ratio
- **Upgrade Success Rate**: `UpgradeOpenTimestampProofs` job success ratio
- **Verification Latency**: P50, P95, P99 for `verify()` calls
- **Cache Hit Rate**: Ratio of cached vs. Python-process verifications

### Logs

```php
// Enable debug logging for OTS operations
Log::debug('OpenTimestamp: Submitting digest', ['digest' => $digest]);
Log::debug('OpenTimestamp: Cache hit for verified proof', ['digest' => $digest]);
Log::warning('OpenTimestamp: python3 not installed', ['digest_hint' => substr($digest, 0, 12)]);
```

Search logs for `OpenTimestamp:` prefix.

## References

- **OpenTimestamps Website**: <https://opentimestamps.org/>
- **OpenTimestamps Client**: <https://github.com/opentimestamps/opentimestamps-client>
- **SecPal Issue #412**: Hybrid verification vulnerabilities (removed)
- **SecPal Issue #415**: Secure external verification (implemented)
- **SecPal Issue #385**: Level 3 Audit Trail (parent epic)
- **Bitcoin Whitepaper**: <https://bitcoin.org/bitcoin.pdf>

## License

This documentation is licensed under CC0-1.0.

The OpenTimestamp client is licensed under LGPL-3.0.

SecPal integration code is licensed under AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution.

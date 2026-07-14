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
- **Performance Optimized**: Caching layer for verified proofs (immutable once confirmed)
- **Fail-Closed**: Falls back to unverified state rather than false positives

## Architecture

### Components

1. **OpenTimestampService** (`app/Services/OpenTimestampService.php`)
   - Handles submission, upgrade, and verification of OTS proofs
   - Uses the OpenTimestamps Python library for submission and bounded verification, plus `ots upgrade` for proof upgrades
   - Implements proof- and provider-bound caching for successful verification decisions
   - **Proof Merging**: Combines attestations from multiple calendar servers for redundancy

2. **ProcessExecutor** (`app/Contracts/ProcessExecutor.php`)
   - Abstraction for executing external commands with explicit process environments
   - Enables testable, mocked process interactions

3. **Jobs**
   - `SubmitMerkleRootToOpenTimestamp`: Submits batch merkle roots to calendars
   - `UpgradeOpenTimestampProofs`: Polls for Bitcoin-anchored proofs

4. **Runtime installation guidance**
   - Documents how to install `opentimestamps-client` in local shells, containers, and production environments

### Proof Selection from Multiple Calendars

When submitting a timestamp to multiple calendar servers, SecPal stores the first valid calendar response:

- **Problem**: If only one calendar server is contacted, submission fails if that server is unavailable
- **Solution**: Submit to 3 calendar servers in parallel (alice, bob, finney)
- **Benefit**: Higher success rate (requires minimum 2 of 3 calendars to respond)
- **Current limitation**: Only the first calendar's proof is stored (not merged)

> **Note on Proof Merging**
> True OTS proof merging (combining attestations from multiple calendars into a single proof) requires implementing a full OTS binary format parser with fork operation support (OpCode 0xFF). This is tracked in Issue #410 (Full OTS Parser) and Issue #411 (Proof Merging).
>
> The current implementation prioritizes reliability and OTS compliance by storing a valid proof from one calendar rather than attempting to create an invalid merged structure.

**Implementation** (Issue #411 - Updated after review):

- `submit()` sends digest to 3 calendar servers in parallel
- Each calendar returns a complete OTS proof with its attestation
- `mergeProofs()` selects the first valid proof to store
- The stored proof is structurally valid and verifiable by OTS CLI tools

**Example**:

```php
// 3 calendars respond with individual OTS proofs
$aliceProof = Http::post('alice.btc.../timestamp/abc123'); // Valid OTS proof
$bobProof = Http::post('bob.btc.../timestamp/abc123');     // Valid OTS proof
$finneyProof = Http::post('finney.../timestamp/abc123');   // Valid OTS proof

// submit() returns first proof (alice's)
$storedProof = $service->submit('abc123...');
// $storedProof is a valid OTS proof verifiable by: ots verify
```

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
6. Verified Proof (cached forever)
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

Verified proofs are **immutable** - once a proof is Bitcoin-anchored and verified, it will always be valid (assuming blockchain integrity). Therefore:

- ✅ **Cache successful verifications**: The `v3` key binds the digest to hashes of the exact proof and configured provider set
- ✅ **Version verifier decisions**: A verifier security change uses a new namespace so legacy positive results cannot bypass new checks
- ✅ **Invalidate on context changes**: Replacing either the proof or provider configuration requires fresh verification
- ❌ **Do NOT cache failures**: Pending proofs may upgrade to confirmed later

### Threat Model

| Threat                    | Mitigation                                                                                                   |
| ------------------------- | ------------------------------------------------------------------------------------------------------------ |
| Proof forgery             | The OTS commitment must match the fetched header's Merkle root                                               |
| Header API compromise     | Exactly one hash may reach quorum; conflicting quorums fail closed, and the raw header hash is verified      |
| Man-in-the-middle attacks | HTTPS-only origins, redirect checks, API consensus, and raw-header hashing fail closed                       |
| Timing attacks            | Two-second request caps and a reserved two-attempt header phase preserve progress within the shared deadline |
| Partial provider failure  | Truncated reads and other provider-local transport failures do not block healthy APIs                        |
| Provider resource abuse   | Block-hash and raw-header responses are read with strict byte bounds                                         |
| Cache poisoning           | Versioned keys bind decisions to the proof and provider configuration                                        |

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
# OpenTimestamp Configuration (config/opentimestamp.php)

# Calendar URLs (comma-separated)
# Default: alice.btc.calendar.opentimestamps.org,
#          bob.btc.calendar.opentimestamps.org,
#          finney.calendar.eternitywall.com
OTS_CALENDAR_URLS=https://alice.btc.calendar.opentimestamps.org,https://bob.btc.calendar.opentimestamps.org

# Minimum successful calendar responses required
# Default: 2 (security vs. availability trade-off)
OTS_MIN_CALENDAR_RESPONSES=2

# Bitcoin header API bases used during verification (comma-separated).
# At least two canonical HTTPS origins are required so one configured provider cannot
# substitute a self-consistent header that is not part of the Bitcoin chain.
OTS_BITCOIN_HEADER_API_BASES=https://blockstream.info/api,https://mempool.space/api

# Note: The Python verifier process timeout is hardcoded at 10 seconds in OpenTimestampService
# and is not configurable via environment variable.
# HTTP request timeout (OPENTIMESTAMP_TIMEOUT) is separate and defaults to 30s.
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

Successful verifications are cached forever using an internal `v3` key that includes the digest plus hashes of the exact proof and configured provider set. A proof or provider change therefore causes fresh verification.

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

**Symptom**: `submit()` throws `RuntimeException: only 0 of 3 calendars responded`

**Solution**:

1. **Network Connectivity**

   ```bash
   # Test calendar reachability
   curl -I https://alice.btc.calendar.opentimestamps.org
   ```

2. **Firewall Rules**
   - Ensure outbound HTTPS (443) allowed to calendar servers

3. **Calendar Server Down**
   - Check calendar server status: <https://opentimestamps.org/>
   - Lower `OTS_MIN_CALENDAR_RESPONSES` (not recommended for production)

### Performance Issues

**Symptom**: `verify()` takes >5 seconds, p95 latency high

**Solutions**:

1. **Check Caching**

   ```php
   // Verified proofs should hit cache (<10ms)
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

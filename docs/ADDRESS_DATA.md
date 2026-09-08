<!-- SPDX-FileCopyrightText: 2026 SecPal Contributors -->
<!-- SPDX-License-Identifier: CC0-1.0 -->

# German street reference data (OpenPLZ)

SecPal provides **German street autocomplete** using reviewed data from the
OpenPLZ API Data repository (`openpotato/openplzapi.data`). The dataset is not
committed to this repository. Production downloads and setup imports are both
disabled by default.

## Source and license

- **Upstream CSV:** `streets.updated.csv` under `src/de/osm/` in [openpotato/openplzapi.data](https://github.com/openpotato/openplzapi.data).
- **License:** Open Database License v1.0 (ODbL-1.0). A copy of the license text is stored under `LICENSES/ODbL-1.0.txt`.
- **Attribution (informal summary):** OpenPLZ API Data builds on OpenStreetMap data and related processing by OpenPotato. Preserve attribution required by ODbL when you publish results derived from this dataset.

**Important:** Have licensing reviewed by counsel before exposing autocomplete results to external users or third parties. ODbL has share-alike and attribution obligations that may affect how API responses are reused.

## Runtime behaviour

1. Data is stored in PostgreSQL tables `address_data_imports` and
   `address_streets`.
2. Every remote or local source requires an exact, reviewed SHA-256 before any
   CSV parsing or candidate import begins. The only accepted remote identity is
   an HTTPS `raw.githubusercontent.com` URL pinned to a full 40-character commit
   SHA. Branches, tags, `latest`, release aliases, and other floating identities
   are rejected.
3. Imports run atomically: rows are written under a new import id and the
   dataset becomes active only after source admission, CSV validation, row
   import, and the activation transaction all succeed.
4. A missing or mismatched digest, download timeout, HTTP failure, malformed or
   empty CSV, or database failure leaves the previous active dataset unchanged.
   Temporary downloads are removed. A matching digest may still be skipped when
   it is already active; `--force` bypasses only that deduplication.

## Configuration

See `config/address_data.php` and `.env.example`:

| Variable                         | Purpose                                                                                                                   |
| -------------------------------- | ------------------------------------------------------------------------------------------------------------------------- |
| `ADDRESS_DATA_SCHEDULE_ENABLED`  | Enables the weekly Monday 03:30 import. Defaults to `false`; when false, no address-data schedule or HTTP request exists. |
| `ADDRESS_DATA_SOURCE_URL`        | Reviewed, commit-pinned GitHub raw CSV URL used by remote imports. No default.                                            |
| `ADDRESS_DATA_EXPECTED_SHA256`   | Exact 64-character lowercase SHA-256 authorized for the configured remote URL or setup-local file. No default.            |
| `ADDRESS_DATA_DOWNLOAD_TIMEOUT`  | Bounded remote download timeout in seconds. Defaults to `600`; the optional HEAD check is capped at 30 seconds.           |
| `ADDRESS_DATA_IMPORT_ON_SETUP`   | Enables the if-empty import invoked by `composer setup`. Defaults to `false`.                                             |
| `ADDRESS_DATA_SETUP_SOURCE_PATH` | Optional operator-supplied local CSV for setup. It still requires `ADDRESS_DATA_EXPECTED_SHA256`.                         |

Downloaded files live under `storage/app/address-data/` (temporary artifacts are cleaned up automatically).

## Commands

```bash
php artisan addresses:import
php artisan addresses:import \
  --source=/path/to/streets.updated.csv \
  --expected-sha256=<64-lowercase-hex-sha256>
php artisan addresses:import --dry-run
php artisan addresses:import --if-empty --setup-only
php artisan addresses:check
```

Remote commands read the reviewed URL and digest from configuration. A local
command may supply its digest with `--expected-sha256`; otherwise it uses
`ADDRESS_DATA_EXPECTED_SHA256`. `--dry-run` and `--force` never bypass source or
digest admission. Local paths are trusted operator CLI/configuration inputs, not
automatically trusted content. SecPal copies a local file to a private temporary
snapshot, then hashes and parses that same snapshot so later path changes cannot
change the admitted bytes.

## Reviewing an OpenPLZ update

1. Select a specific upstream commit and obtain the exact CSV outside the
   production runtime.
2. Review the source revision and CSV, then calculate its SHA-256.
3. Record the commit-pinned raw URL and exact lowercase digest through the
   deployment or release configuration authority.
4. Enable the scheduler, run a manual import, or enable setup import only after
   that pair has been reviewed.

SecPal does not discover a newest revision, follow `main`, or update digests at
runtime. ETag and Last-Modified values are optional provenance only and never
authorize content.

## API

Authenticated JSON endpoints (Sanctum + `api-access` ability; **email verification not required**):

- `GET /v1/addresses/de/streets`
- `GET /v1/addresses/de/localities`
- `GET /v1/addresses/de/status`

If no activated import exists, responses use HTTP **503** with `code: address_data_unavailable`.

## Scheduler

When `ADDRESS_DATA_SCHEDULE_ENABLED=true`, `routes/console.php` registers
`addresses:import` weekly on Mondays at 03:30 with single-server and overlap
protection. When disabled, the event is not registered and the API makes zero
OpenPLZ address-data requests. Deployment-owned host/network controls are
outside this API contract.

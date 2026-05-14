<!-- SPDX-FileCopyrightText: 2026 SecPal Contributors -->
<!-- SPDX-License-Identifier: CC0-1.0 -->

# German street reference data (OpenPLZ)

SecPal ships **German street autocomplete** sourced from the OpenPLZ API Data repository (`openpotato/openplzapi.data`). The dataset is **not** committed to this repository; by default it is downloaded during **installation** (`composer setup`) when no dataset exists yet, and refreshed by scheduled jobs.

## Source and license

- **Upstream CSV:** `streets.updated.csv` under `src/de/osm/` in [openpotato/openplzapi.data](https://github.com/openpotato/openplzapi.data).
- **License:** Open Database License v1.0 (ODbL-1.0). A copy of the license text is stored under `LICENSES/ODbL-1.0.txt`.
- **Attribution (informal summary):** OpenPLZ API Data builds on OpenStreetMap data and related processing by OpenPotato. Preserve attribution required by ODbL when you publish results derived from this dataset.

**Important:** Have licensing reviewed by counsel before exposing autocomplete results to external users or third parties. ODbL has share-alike and attribution obligations that may affect how API responses are reused.

## Runtime behaviour

1. Data is stored in PostgreSQL tables `address_data_imports` and `address_streets`.
2. Imports run **atomically**: rows are written under a new import id; the dataset is only switched to “active” after a full successful parse.
3. Updates compute a **SHA-256** checksum; unchanged upstream files are skipped quickly without rewriting millions of rows.

## Configuration

See `config/address_data.php` and `.env.example`:

| Variable                        | Purpose                                                                                                                                                                      |
| ------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `ADDRESS_DATA_SOURCE_URL`       | CSV URL (defaults to OpenPLZ GitHub raw path).                                                                                                                               |
| `ADDRESS_DATA_UPDATE_FREQUENCY` | Documented cadence; scheduler defaults to weekly Monday 03:30.                                                                                                               |
| `ADDRESS_DATA_IMPORT_ON_SETUP`  | Default `true`: `composer setup` runs `addresses:import --if-empty --setup-only` after migrations (requires network). Set `false` for offline installs or CI without egress. |

Downloaded files live under `storage/app/address-data/` (temporary artifacts are cleaned up automatically).

## Commands

```bash
php artisan addresses:import
php artisan addresses:import --source=/path/to/streets.updated.csv
php artisan addresses:import --dry-run
php artisan addresses:import --if-empty --setup-only
php artisan addresses:check
```

## API

Authenticated JSON endpoints (Sanctum + `api-access` ability; **email verification not required**):

- `GET /v1/addresses/de/streets`
- `GET /v1/addresses/de/localities`
- `GET /v1/addresses/de/status`

If no activated import exists, responses use HTTP **503** with `code: address_data_unavailable`.

## Scheduler

`routes/console.php` registers `addresses:import` weekly (Mondays 03:30). Ensure `php artisan schedule:run` is executed from cron or a supervisor in production.

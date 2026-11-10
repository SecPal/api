<!--
SPDX-FileCopyrightText: 2026 SecPal Contributors
SPDX-License-Identifier: CC0-1.0
-->

# German street reference data (OpenPLZ)

SecPal imports German street reference data from OpenPLZ API Data. The CSV is
not bundled in the repository or image. Customer and employee records remain
separate from this shared reference database.

## Release-owned source

`config/address_data.php` owns the reviewed source URL and exact SHA-256. Neither
can be overridden by an environment variable. There is no periodic address-data
updater. A dataset change requires a reviewed application release.

The initial pin was verified on 2026-10-18:

- [Upstream commit](https://github.com/openpotato/openplzapi.data/commit/c26389b30573ab2738d0b14fd350f143aa0a1251).
- CSV: `src/de/osm/streets.updated.csv`, 55,216,541 bytes.
- SHA-256: `1066a96a6b3a16629a94c834fca49fe58a2bb01558e17242c53a12d68b290088`.
- Git blob: `063ba658bff264bc3e47e8bc723276bad6972848`.

The HTTPS raw download's `git hash-object` matched the blob identity read
independently from GitHub's commit-specific contents API. `sha256sum` and
`openssl dgst -sha256` agreed. The reviewed upstream README describes the
Germany OSM extraction, name shortening and ignore-list filtering; the LICENSE
at that commit is ODbL-1.0. Source identity and byte verification are technical
review evidence, not external legal approval or a claim that addresses are current.

## Installation and application updates

`composer setup` runs migrations followed by `addresses:import --no-interaction`.
`AddressDataSeeder` uses the same importer. Optional seeding is enabled by default;
`ADDRESS_DATA_IMPORT_ON_SETUP=false` disables seeding and `--setup-only` imports
(including in tests). It does not disable Composer setup or the explicit
production release command below. Composer disables its process timeout for
the import; the HTTP download retains its configured timeout.

After migrations on **every installation and application update**, the existing
deployment one-shot must run, and check the exit status of:

```bash
php artisan addresses:import --no-interaction
php artisan addresses:check
```

The importer compares the release's expected hash with the successful active
import before any HEAD or GET request. Matching datasets refresh their source label
and license/attribution notices while preserving acquisition provenance and
activation time. A different hash is imported even when a
dataset already exists. Failure returns a nonzero status; deployment must stop
promotion and retry the same command after correcting the cause. Do not append
`--if-empty`, which deliberately preserves any existing dataset for manual use.
Do not run downloads in image builds, request handlers, workers or the scheduler.

`SecPal/deployment` owns the production installation/update one-shot, network
policy and timeout budget. Managed hosting owns its private rollout automation.
API changes alone do not prove those callers have been integrated or legally
approved. See the [production integration issue](https://github.com/SecPal/deployment/issues/344).

For reviewed offline installation, mount the exact release CSV and run:

```bash
php artisan addresses:import --source=/mounted/streets.updated.csv --no-interaction
```

The configured release checksum still applies. Imports also accept
`ADDRESS_DATA_SETUP_SOURCE_PATH` as the default local input. Manual imports of another reviewed local CSV
require `--source` and its explicit `--expected-sha256`. `--force` reimports
matching bytes; `--dry-run` validates the actual CSV without persisting rows.
Neither bypasses digest admission. Remote URLs must contain a full commit SHA;
branches, tags, query strings and redirects to moving identities are not release
configuration.

## Activation, retry and rollback

PostgreSQL session advisory locking serializes download, staging, activation and
pruning across importer callers, independently of scheduler/cache settings.
A contending command fails before download and can be retried. A terminated
connection releases ownership. Do not put this command behind transaction-mode
connection pooling, which cannot preserve a session lock across statements.

A private snapshot is hashed before parsing. Rows are staged against an inactive
import. Malformed, empty or mismatching data cannot replace the active import.
Activation and pruning share one transaction; failure rolls back the old active
pointer, cleans candidate rows and leaves the prior dataset usable. Completed
imports invalidate the suggestion cache. Failed downloads remove their temporary
snapshot. A retry starts a new candidate; it does not activate partial rows.

At least one prior successful import and its street rows are retained; increase
`--keep-imports` to retain more. After rolling application configuration back to
an earlier pin, run the same command with that release's CSV or network access.
Only fully verified data activates. Retention is recovery support, not an
automatic pointer switch or a substitute for deployment backup/recovery policy.

`ADDRESS_DATA_DOWNLOAD_TIMEOUT` defaults to 600 seconds. Deployment must allow
additional time for parsing and database inserts. Status is available through
`addresses:check` and authenticated `/v1/addresses/de/status`. Autocomplete uses
`/v1/addresses/de/streets` and `/v1/addresses/de/localities`; unavailable reference
data returns HTTP 503 with `code: address_data_unavailable`.

## ODbL assessment and operator obligations

This is a technical compliance assessment, not professional legal clearance.
The authoritative terms are the [ODbL-1.0 legal text](https://opendatacommons.org/licenses/odbl/1-0/).
The [upstream license](https://github.com/openpotato/openplzapi.data/blob/c26389b30573ab2738d0b14fd350f143aa0a1251/LICENSE)
and [OSMF attribution guidelines](https://osmfoundation.org/wiki/Licence/Attribution_Guidelines)
apply separately from SecPal's application-code license.

- **Commercial use:** ODbL section 3 permits commercial use. Selling Managed
  service or Self-Hosted software does not remove attribution, share-alike or
  database-access obligations. SecPal's commercial software terms cannot
  relicense OpenPLZ/OSM data or impose incompatible restrictions on it.
- **Public autocomplete:** authentication does not make a Managed service used
  by unrelated customers internal to one organization. Display OpenPLZ API Data /
  OpenPotato credit and “© OpenStreetMap contributors”, with links to
  <https://www.openstreetmap.org/copyright> and ODbL, beside address suggestions
  or another suitably prominent location. JSON metadata alone is insufficient
  for a graphical client; [frontend#1934](https://github.com/SecPal/frontend/issues/1934)
  owns the visible client fix. The API preserves credit and license links in
  `meta.attribution`; clients must render them visibly, including empty results
  from an available dataset. Self-Hosted operators have the same obligation
  when publicly using the data.
- **Substantial extraction:** importing the full German CSV is substantial.
  Small individual results do not exempt the full reference database or repeated
  systematic extraction (sections 4.4 and 6.2). Rate limits and authentication
  protect service capacity; they do not establish a licensing exemption.
- **Derivative database:** conservatively treat the imported reference database
  and normalized search columns as a derivative. Public use requires ODbL
  share-alike and the section 4.6 offer, even if users only see produced results.
  Purely internal use has the section 4.5(c) distinction; an operator must assess
  its actual audience rather than infer it from login requirements.
- **Customer data:** `address_streets` contains only upstream reference fields
  and deterministic search values. It has no tenant/customer/employee foreign
  keys. Do not enrich it with private addresses or publish a whole application
  database backup to meet ODbL. Keeping separate tables supports a collective
  database assessment under section 4.5(a), but is not alone a legal guarantee.
  Selected results in customer records and systematic aggregation require
  assessment in light of the [OSMF geocoding guidance](https://osmfoundation.org/wiki/Licence/Community_Guidelines/Geocoding_-_Guideline);
  that guidance's precise geocoding definition must not be assumed to settle
  SecPal's address-only autocomplete use case.

## Machine-readable alterations offer

For the release-pinned dataset, SecPal offers the public upstream CSV and the
complete method of alterations under section 4.6(b):

1. Obtain the exact CSV from the versioned `source_url` and verify
   `expected_sha256` in `config/address_data.php`.
2. `AddressStreetCsvImporter` preserves the six CSV fields, strips postcode
   whitespace, represents empty optional fields as null and adds `DE`, import
   identity and timestamps. It adds no external geographic or customer data.
3. `AddressSearchNormalizer` derives lowercase, whitespace-normalized and German
   transliterated search values. `create_address_data_tables` defines the schema;
   `AddressDataImportService` defines activation and retention.

The machine-readable implementation files are freely available in the
[public source tree](https://github.com/SecPal/api) under `app/Services/AddressData/`,
`app/Support/AddressSearchNormalizer.php` and
`database/migrations/2026_05_10_140000_create_address_data_tables.php`.
For a deployed release use its exact corresponding source archive, not current
`main`; deployment's existing `/v1/release` source link must identify that
archive. The ODbL reference database remains ODbL; this offer does not change the
AGPL license of application code. Carry this notice, the ODbL license and access
to the exact alterations method in distributions and user-facing data notices.

Operators changing the data, normalization or source must provide the complete
actual alterations, including any additional contents, or offer the whole
reference-only derivative database in machine-readable form, free online.
Protect customer data by keeping it outside that offer. Do not assume a link to
an unmodified upstream file alone covers a modified deployed database.

## Remaining approval boundary

Before public Managed service or public Self-Hosted autocomplete is approved,
the operator and qualified IT/open-data counsel must confirm the actual
attribution placement, derivative/collective classification, sufficiency and
availability of the alterations offer, customer-record aggregation, and
compatibility of commercial terms. The existing
[legal-review owner](https://github.com/SecPal/.github/issues/46) remains open;
REUSE, an SPDX allowlist, this assessment and merged code are not legal sign-off.
No production deployment or customer-data mutation is authorized by this work.

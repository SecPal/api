<!--
SPDX-FileCopyrightText: 2026 SecPal Contributors
SPDX-License-Identifier: CC0-1.0
-->

# Third-party license audit

Audit date: 2026-07-16. This record covers repository-tracked material and
the dependency graph locked by `composer.lock`.

## Result

`reuse lint` passes. The license texts required by the SPDX identifiers used
in this repository are present in `LICENSES/`:

- `AGPL-3.0-or-later.txt`
- `CC-BY-4.0.txt`
- `CC0-1.0.txt`
- `MIT.txt`
- `ODbL-1.0.txt`

These are repository-file licenses, not a substitute for the license and
notice files shipped by package-manager dependencies.

## Composer inventory

`composer licenses --format=json` reported 163 installed dependencies:

| License expression                           | Packages |
| -------------------------------------------- | -------: |
| MIT                                          |      126 |
| BSD-3-Clause                                 |       29 |
| Apache-2.0                                   |        4 |
| BSD-2-Clause                                 |        2 |
| BSD-3-Clause OR GPL-2.0-only OR GPL-3.0-only |        2 |

Laravel Framework 13.20.0, Laravel Sanctum, and Laravel Tinker are MIT.
The direct packages `opis/json-schema`, `opis/string`, and `opis/uri` are
Apache-2.0. The complete, versioned inventory is reproducible from the lock
file with `composer licenses --format=json`.

All listed dependency licenses are permissive or allow a permissive choice.
Their copyright and license notices must remain in every distributed
`vendor/` tree or other bundled artifact. Apache-2.0 packages also require
preserving any upstream `NOTICE` material distributed with the package. The
repository does not track `vendor/`, so copying dependency license texts into
`LICENSES/` would not accurately represent their source or replace that
distribution obligation.

## Repository-tracked third-party material

- The Laravel configuration templates in `config/app.php`, `config/auth.php`,
  `config/cache.php`, `config/cors.php`, `config/database.php`,
  `config/filesystems.php`, `config/logging.php`, `config/mail.php`,
  `config/queue.php`, `config/services.php`, and `config/session.php`, plus
  the Laravel Sanctum template `config/sanctum.php`, retain the MIT license and
  `Taylor Otwell` copyright notice. Their SecPal-specific configuration values
  do not replace the third-party templates' MIT licensing with the
  repository-owned AGPL license.
- `config/permission.php` is an adapted published configuration from
  `spatie/laravel-permission`. Its SPDX metadata preserves the package's MIT
  license and `Spatie bvba <info@spatie.be>` copyright notice. SecPal changes
  do not replace this third-party derivative's MIT license with the
  repository-owned AGPL license.
- `tests/fixtures/address_data/sample_streets.csv` is ODbL-1.0 reference data.
  Its sidecar and `docs/ADDRESS_DATA.md` preserve the data license and the
  required OpenPLZ/OpenStreetMap/OpenPotato attribution context. Runtime
  imports are downloaded data, not repository-owned application code; a
  deployment that publishes derived data must meet ODbL attribution and
  share-alike obligations.
- `CODE_OF_CONDUCT.md` retains its CC-BY-4.0 Contributor Covenant notice.
- The repository tracks no `vendor/`, `node_modules/`, npm manifest, npm lock
  file, generated third-party JavaScript, or bundled OpenTimestamps client.
  The OpenTimestamps client is an operator-installed LGPL-3.0 dependency;
  deployments that bundle it must carry its upstream license, notices, and
  corresponding-source obligations separately.

The concise provenance record for committed third-party material is in
`THIRD-PARTY-NOTICES.md`. The remaining repository-file license metadata was
reviewed as project-owned material or explicitly documented data/notice
material. No other copied third-party source or replaced third-party copyright
notice was identified in tracked files.

## Release checklist

Before distributing a container, release archive, or other artifact:

1. Regenerate and review `composer licenses --format=json` from the exact
   lock file used for the build.
2. Keep each installed package's license and notice files with the bundled
   `vendor/` tree; do not replace them with SecPal notices.
3. Include the ODbL attribution and assess ODbL share-alike requirements when
   publishing address-data results or a substantial extract.
4. If the OpenTimestamps client is bundled, satisfy its LGPL-3.0 notice and
   corresponding-source obligations for that client.
5. Run `reuse lint` for repository-tracked material.

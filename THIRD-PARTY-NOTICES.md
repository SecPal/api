<!--
SPDX-FileCopyrightText: 2026 SecPal Contributors
SPDX-License-Identifier: CC0-1.0
-->

# Third-party notices

This record covers third-party material committed to this repository and
notice obligations for distributed dependencies. Repository-file licensing is
recorded in [REUSE.toml](REUSE.toml) and [LICENSES/](LICENSES/).

## Laravel configuration templates

`config/app.php`, `config/auth.php`, `config/cache.php`, `config/cors.php`,
`config/database.php`, `config/filesystems.php`, `config/logging.php`,
`config/mail.php`, `config/queue.php`, `config/services.php`, and
`config/session.php` originated from the Laravel application skeleton.
`config/sanctum.php` originated from Laravel Sanctum. They retain the MIT
license and upstream notice:

```text
Copyright (c) Taylor Otwell
```

The exact paths are explicit MIT exceptions in `REUSE.toml` and in the
license-compatibility policy. Local configuration values do not turn these
third-party templates into SecPal-attribution material.

## Spatie Laravel Permission configuration

`config/permission.php` is an adapted configuration published by
`spatie/laravel-permission`. It retains the MIT license and upstream notice:

```text
Copyright (c) Spatie bvba <info@spatie.be>
```

The complete MIT text is available at [LICENSES/MIT.txt](LICENSES/MIT.txt).

## Package-managed dependencies

Use the build's [composer.lock](composer.lock) and
`composer licenses --format=json` to review the installed Composer dependency
licenses. Artifacts that bundle a `vendor/` tree must preserve the packages'
license and notice files, including upstream Apache `NOTICE` files where
supplied. Run `reuse lint` for tracked material.

The API container installs the OpenTimestamps core and the Python dependencies
from the [hash-locked requirements](docker/python/opentimestamps-requirements.txt).
Keep their upstream license and notice files in distributed images and satisfy
the LGPL-3.0 obligations for the core, including corresponding source. The
[upstream core repository](https://github.com/opentimestamps/python-opentimestamps)
is the package's source location.

## OpenPLZ address reference data

OpenPLZ API Data / OpenPotato; © OpenStreetMap contributors
(<https://www.openstreetmap.org/copyright>). The German reference database is
licensed under [ODbL-1.0](https://opendatacommons.org/licenses/odbl/1-0/), separately
from SecPal application code. The complete license is in
[LICENSES/ODbL-1.0.txt](LICENSES/ODbL-1.0.txt).

The full CSV is downloaded only during an explicit installation/update or manual
import. Its immutable source and SHA-256 are in `config/address_data.php`.
The [address-data contract](docs/ADDRESS_DATA.md) records source verification,
operator obligations and the machine-readable alterations offer. The image
carries that contract at `/app/docs/ADDRESS_DATA.md`. Public clients must display
the attribution and license links; a software license audit alone does not
clear a deployment's ODbL obligations.

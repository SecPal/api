<!--
SPDX-FileCopyrightText: 2026 SecPal Contributors
SPDX-License-Identifier: CC0-1.0
-->

# Third-party notices

This record covers third-party material committed to this repository. It does
not relicense that material. The versioned Composer dependency inventory is in
`composer.lock` and the reproducible audit procedure is documented in
[`docs/THIRD_PARTY_LICENSE_AUDIT.md`](docs/THIRD_PARTY_LICENSE_AUDIT.md).

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

Composer dependencies are not copied source. Release artifacts that bundle a
`vendor/` tree must preserve each dependency's license and notice files. See
the release checklist in
[`docs/THIRD_PARTY_LICENSE_AUDIT.md`](docs/THIRD_PARTY_LICENSE_AUDIT.md).

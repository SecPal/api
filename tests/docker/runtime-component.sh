#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 SecPal Contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
set -euo pipefail
component=${1:?runtime component}
image=${2:?exact built image}
arch=${3:?native architecture}
case "$arch:$(uname -m)" in amd64:x86_64|arm64:aarch64) ;; *) exit 1 ;; esac
test "$(podman info --format '{{.Host.Security.Rootless}}')" = true
test "$(podman image inspect --format '{{.Architecture}}' "$image")" = "$arch"
test "$(podman image inspect --format '{{index .Config.Labels "secpal.dev/runtime-component"}}' "$image")" = "$component"
args=(--rm --read-only --cap-drop=all --security-opt=no-new-privileges --network=none)
podman run "${args[@]}" --entrypoint sh "$image" -eu -c '
    test "$(id -u)" -eq 10001
    test "$(id -g)" -eq 10001
    test ! -e /app/.env
    test ! -e /app/.git
    test -z "$(find /app -xdev -perm -0002 -print -quit)"
    for tool in git bash composer gcc make node pip pip3; do
        if command -v "$tool" >/dev/null; then exit 1; fi
    done
    for package in linux-headers musl-dev libc-dev py3-pip; do
        if apk info -e "$package" >/dev/null 2>&1; then exit 1; fi
    done
'
case "$component" in
  php-fpm)
    podman run "${args[@]}" "$image" php -r '
        if (PHP_VERSION !== "8.4.27" || PHP_ZTS || posix_geteuid() !== 10001) { exit(1); }
        foreach (["bcmath","curl","gettext","intl","mbstring","Zend OPcache","pcntl","pdo_pgsql","pgsql","sodium","xml","zip"] as $extension) {
            if (!extension_loaded($extension)) { exit(1); }
        }
        exit(Locale::getDisplayRegion("en_DE","de") === "Deutschland"
            && ini_get("upload_max_filesize") === "10M" && ini_get("post_max_size") === "12M" ? 0 : 1);
    '
    # Both existing PHP SQLite drivers must load the authenticated replacement library.
    # shellcheck disable=SC2016 # These are PHP variables.
    podman run "${args[@]}" "$image" php -r '
        $proof = json_decode(file_get_contents("/usr/share/secpal/sqlite-build.json"), true, flags: JSON_THROW_ON_ERROR);
        $db = new PDO("sqlite::memory:");
        exit(SQLite3::version()["versionString"] === $proof["version"]
            && $db->query("SELECT sqlite_source_id()")->fetchColumn() === $proof["source_id"] ? 0 : 1);
    '
    podman run -i "${args[@]}" "$image" python3 - < tests/docker/sqlite-runtime.py
    podman run "${args[@]}" "$image" python3 -c 'import opentimestamps'
    podman run "${args[@]}" "$image" php-fpm --test
    test "$(podman image inspect --format '{{.Config.StopSignal}}' "$image")" = SIGQUIT
    podman run "${args[@]}" "$image" sh -eu -c '
        test -r /app/LICENSES/ODbL-1.0.txt
        test -r /app/docs/ADDRESS_DATA.md
        test ! -d /app/vendor/pestphp
        test ! -d /app/vendor/phpunit
        test ! -e /usr/local/bin/frankenphp
        test ! -e /usr/local/bin/composer
        ! command -v curl
        test ! -e /usr/local/include/php
        test "$(stat -c "%U:%G %a" /usr/local/etc/php-fpm.d/api.conf)" = "root:root 644"
    '
    ;;
  nginx)
    podman run "${args[@]}" --mount type=tmpfs,destination=/tmp,tmpfs-mode=0700,chown=true "$image" -t
    podman run "${args[@]}" --entrypoint sh "$image" -eu -c '
        test ! -e /app/vendor
        test ! -e /app/config
        test ! -e /app/storage
        test ! -e /app/artisan
        test ! -e /usr/local/bin/php
        test ! -e /run/secpal/secrets
        test "$(stat -c "%U:%G %a" /etc/nginx/nginx.conf)" = "root:root 444"
    '
    ;;
  *) echo 'Unknown runtime component' >&2; exit 1 ;;
esac
printf 'Native component inventory passed: %s linux/%s\n' "$component" "$arch"

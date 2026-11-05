#!/bin/sh
# SPDX-FileCopyrightText: 2026 SecPal Contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
set -eu
export PACKAGER='SecPal Contributors <build@secpal.dev>'
export REPODEST=/tmp/sqlite-packages
export SRCDEST=/tmp/sqlite-distfiles
export SOURCE_DATE_EPOCH
test "$SOURCE_DATE_EPOCH" -ge 0
mkdir -p /out/keys "$REPODEST" "$SRCDEST"
symbols() {
    nm -D --defined-only "$1" > /tmp/sqlite-symbols.raw
    awk '$3 ~ /^sqlite3_/ {print $3}' /tmp/sqlite-symbols.raw > /tmp/sqlite-symbols.names
    sort -u /tmp/sqlite-symbols.names > "$2"
    test -s "$2"
}
symbols /usr/lib/libsqlite3.so.0 /tmp/sqlite-old-symbols
# SDK dependencies are already explicitly pinned; do not resolve new ones here.
apk info -e readline-dev=8.3.3-r1 gcc=15.2.0-r5 musl-dev=1.2.6-r3
# This public key is trusted only in the disposable SDK and the local APK mount.
apk info -vv > /tmp/sqlite-build-packages.unsorted
sort /tmp/sqlite-build-packages.unsorted > /tmp/sqlite-build-packages
abuild-keygen -a -n -q
cp /root/.abuild/*.rsa.pub /etc/apk/keys/
abuild -F -d -r
package=$(find "$REPODEST" -type f -name 'sqlite-libs-3.54.0-r0.apk')
test -f "$package"
cp "$package" /tmp/sqlite-first.apk
abuild -F clean
abuild -F -d -f -r
cmp /tmp/sqlite-first.apk "$package"
architecture=$(apk --print-arch)
mkdir -p "/out/$architecture"
cp "$package" "/out/$architecture/"
cp /root/.abuild/*.rsa.pub /out/keys/
apk index --output "/out/$architecture/APKINDEX.tar.gz" "/out/$architecture/sqlite-libs-3.54.0-r0.apk"
abuild-sign "/out/$architecture/APKINDEX.tar.gz"
apk add --no-network --keys-dir /out/keys --repositories-file /dev/null --repository /out sqlite-libs=3.54.0-r0
test "$(php -r 'echo SQLite3::version()["versionString"];')" = 3.54.0
readelf -d /usr/lib/libsqlite3.so.0 | grep -E '\(SONAME\).*\[libsqlite3.so.0\]'
symbols /usr/lib/libsqlite3.so.0 /tmp/sqlite-new-symbols
test -z "$(comm -23 /tmp/sqlite-old-symbols /tmp/sqlite-new-symbols)"
# shellcheck disable=SC2016 # These are PHP variables, not shell variables.
php -r '
    $library = realpath("/usr/lib/libsqlite3.so.0");
    $db = new SQLite3(":memory:");
    $proof = [
        "version" => SQLite3::version()["versionString"],
        "package_version" => "3.54.0-r0",
        "origin" => "sqlite",
        "packager" => "SecPal/api, not an Alpine vendor package",
        "architecture" => trim(shell_exec("apk --print-arch")),
        "recipe_sha256" => hash_file("sha256", "/build/sqlite/APKBUILD"),
        "source_url" => "https://www.sqlite.org/2026/sqlite-autoconf-3540000.tar.gz",
        "source_archive_sha512" => hash_file("sha512", "/tmp/sqlite-distfiles/sqlite-autoconf-3540000.tar.gz"),
        "amalgamation_sha3_256" => "8efd453d08a7cfc4c79de5923153036cdc4dcaa254d4f1e617be6a2a98828f9e",
        "source_id" => $db->querySingle("SELECT sqlite_source_id()"),
        "soname" => "libsqlite3.so.0",
        "library_sha256" => hash_file("sha256", $library),
        "package_sha256" => hash_file("sha256", "/out/".trim(shell_exec("apk --print-arch"))."/sqlite-libs-3.54.0-r0.apk"),
        "baseline_exports_sha256" => hash_file("sha256", "/tmp/sqlite-old-symbols"),
        "exports_sha256" => hash_file("sha256", "/tmp/sqlite-new-symbols"),
        "rebuild_identical" => true,
        "source_date_epoch" => (int) getenv("SOURCE_DATE_EPOCH"),
        "build_packages" => file("/tmp/sqlite-build-packages", FILE_IGNORE_NEW_LINES),
        "signing_key_sha256" => array_map(static fn ($p) => hash_file("sha256", $p), glob("/out/keys/*.pub")),
    ];
    file_put_contents("/out/provenance.json", json_encode($proof, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n");
'

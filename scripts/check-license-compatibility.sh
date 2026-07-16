#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 SecPal Contributors
# SPDX-License-Identifier: MIT

set -euo pipefail

SPDX_PATH="${1:-reuse.spdx}"

if [[ ! -f "$SPDX_PATH" ]]; then
  echo "SPDX document not found: $SPDX_PATH" >&2
  exit 1
fi

compatible_licenses=(
  "AGPL-3.0-or-later"
  "GPL-3.0-or-later"
  "LGPL-3.0-or-later"
  "MIT"
  "BSD-2-Clause"
  "BSD-3-Clause"
  "Apache-2.0"
  "CC-BY-4.0"
  "CC0-1.0"
  "ISC"
  "OFL-1.1"
  "ODbL-1.0"
  "LicenseRef-SecPal-Attribution"
)

is_allowed_atom() {
  local atom="$1"
  local compatible

  for compatible in "${compatible_licenses[@]}"; do
    if [[ "$atom" == "$compatible" ]]; then
      return 0
    fi
  done

  return 1
}

has_license() {
  local expected="$1"
  shift
  local license

  for license in "$@"; do
    if [[ "$license" == "$expected" ]]; then
      return 0
    fi
  done

  return 1
}

is_strict_path() {
  local path="$1"

  path="${path#./}"

  if is_strict_exception "$path"; then
    return 1
  fi

  [[ "$path" == app/* ]] \
    || [[ "$path" == bootstrap/* ]] \
    || [[ "$path" == config/* ]] \
    || [[ "$path" == database/* ]] \
    || [[ "$path" == lang/* ]] \
    || [[ "$path" == public/* ]] \
    || [[ "$path" == resources/* ]] \
    || [[ "$path" == routes/* ]] \
    || [[ "$path" == tests/* ]] \
    || [[ "$path" == artisan ]]
}

is_strict_exception() {
  local path="$1"

  strict_exception_license "$path" > /dev/null
}

strict_exception_license() {
  local path="$1"

  case "$path" in
    config/app.php|config/auth.php|config/cache.php|config/cors.php|config/database.php|config/filesystems.php|config/logging.php|config/mail.php|config/permission.php|config/queue.php|config/sanctum.php|config/services.php|config/session.php)
      echo 'MIT'
      return 0
      ;;
    bootstrap/cache/.gitignore|database/.gitignore|public/.htaccess|public/robots.txt)
      echo 'CC0-1.0'
      return 0
      ;;
    tests/fixtures/address_data/sample_streets.csv)
      echo 'ODbL-1.0'
      return 0
      ;;
  esac

  return 1
}

validate_current_file() {
  local path="$1"
  local concluded="$2"
  shift 2
  local licenses=("$@")

  if [[ -z "$path" ]]; then
    return 0
  fi

  path="${path#./}"

  local license

  for license in "${licenses[@]}"; do
    if ! is_allowed_atom "$license"; then
      echo "ERROR: incompatible license atom in $path: $license" >&2
      return 1
    fi
  done

  local exception_license

  if exception_license="$(strict_exception_license "$path")"; then
    if [[ "${#licenses[@]}" -ne 1 ]] \
      || ! has_license "$exception_license" "${licenses[@]}"; then
      echo "ERROR: documented exception must use exactly $exception_license in $path" >&2
      return 1
    fi

    if [[ "$concluded" != "NOASSERTION" && "$concluded" != "$exception_license" ]]; then
      echo "ERROR: documented exception must conclude exactly $exception_license in $path" >&2
      return 1
    fi

    return 0
  fi

  if ! is_strict_path "$path" \
    && ([[ "$concluded" == *'LicenseRef-SecPal-Attribution'* ]] \
      || has_license 'LicenseRef-SecPal-Attribution' "${licenses[@]}"); then
    echo "ERROR: attribution addendum is only permitted for SecPal-owned AGPL code and assets in $path" >&2
    return 1
  fi

  if ! is_strict_path "$path"; then
    return 0
  fi

  if [[ "$concluded" != "NOASSERTION" && "$concluded" == *" OR "* ]]; then
    echo "ERROR: incompatible license expression in $path: $concluded" >&2
    return 1
  fi

  if [[ "${#licenses[@]}" -ne 2 ]] \
    || ! printf '%s\n' "${licenses[@]}" | grep -Fxq 'AGPL-3.0-or-later' \
    || ! printf '%s\n' "${licenses[@]}" | grep -Fxq 'LicenseRef-SecPal-Attribution'; then
    echo "ERROR: strict-path files must use exactly AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution in $path" >&2
    return 1
  fi

  if [[ "$concluded" != "NOASSERTION" ]] \
    && [[ "$concluded" != 'AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution' ]] \
    && [[ "$concluded" != 'LicenseRef-SecPal-Attribution AND AGPL-3.0-or-later' ]]; then
    echo "ERROR: incompatible license expression in $path: $concluded" >&2
    return 1
  fi

  return 0
}

current_path=''
current_concluded='NOASSERTION'
declare -a current_licenses=()

while IFS= read -r line || [[ -n "$line" ]]; do
  if [[ -z "$line" ]]; then
    validate_current_file "$current_path" "$current_concluded" "${current_licenses[@]}"
    current_path=''
    current_concluded='NOASSERTION'
    current_licenses=()
    continue
  fi

  case "$line" in
    FileName:*)
      current_path="${line#FileName: }"
      ;;
    LicenseConcluded:*)
      current_concluded="${line#LicenseConcluded: }"
      ;;
    LicenseInfoInFile:*)
      current_licenses+=("${line#LicenseInfoInFile: }")
      ;;
  esac
done < "$SPDX_PATH"

validate_current_file "$current_path" "$current_concluded" "${current_licenses[@]}"

echo 'All licenses are compatible with AGPL-3.0-or-later'

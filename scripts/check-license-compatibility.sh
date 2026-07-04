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

is_strict_path() {
  local path="$1"

  path="${path#./}"

  [[ "$path" == app/* ]] \
    || [[ "$path" == bootstrap/* ]] \
    || [[ "$path" == config/* ]] \
    || [[ "$path" == database/* ]] \
    || [[ "$path" == public/* ]] \
    || [[ "$path" == routes/* ]] \
    || [[ "$path" == tests/* ]] \
    || [[ "$path" == artisan ]]
}

validate_current_file() {
  local path="$1"
  local concluded="$2"
  shift 2
  local licenses=("$@")

  if [[ -z "$path" ]]; then
    return 0
  fi

  local license

  for license in "${licenses[@]}"; do
    if ! is_allowed_atom "$license"; then
      echo "ERROR: incompatible license atom in $path: $license" >&2
      return 1
    fi
  done

  if ! is_strict_path "$path"; then
    return 0
  fi

  if [[ "$concluded" != "NOASSERTION" && "$concluded" == *" OR "* ]]; then
    echo "ERROR: incompatible license expression in $path: $concluded" >&2
    return 1
  fi

  if printf '%s\n' "${licenses[@]}" | grep -Fxq 'LicenseRef-SecPal-Attribution'; then
    if [[ "${#licenses[@]}" -ne 2 ]] \
      || ! printf '%s\n' "${licenses[@]}" | grep -Fxq 'AGPL-3.0-or-later'; then
      echo "ERROR: strict-path attribution licensing must be exactly AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution in $path" >&2
      return 1
    fi
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

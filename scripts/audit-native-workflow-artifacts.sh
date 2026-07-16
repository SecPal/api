#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 SecPal Contributors
# SPDX-License-Identifier: MIT

set -euo pipefail

if ! command -v rg >/dev/null 2>&1; then
  echo "ripgrep is required for native workflow artifact auditing." >&2
  exit 2
fi

if [ "$#" -eq 0 ]; then
  set -- "$(git rev-parse --show-toplevel)"
fi

legacy_name="$(printf '%s%s' d dev)"
legacy_dir="$(printf '.%s' "$legacy_name")"
pattern="(^|[^[:alnum:]_])(${legacy_name}|\\${legacy_dir})([^[:alnum:]_]|$)"
has_findings=0

for repo in "$@"; do
  if [ ! -d "$repo" ]; then
    echo "Native workflow artifact audit target is not a directory: $repo" >&2
    exit 2
  fi

  set +e
  content_findings="$(
    rg --hidden --line-number --ignore-case "$pattern" "$repo" \
      -g '!CHANGELOG.md' \
      -g '!.git' \
      -g '!vendor' \
      -g '!node_modules' \
      -g '!storage' \
      -g '!bootstrap/cache' \
      -g '!build' \
      -g '!dist' \
      -g '!coverage' \
      -g '!package-lock.json' \
      -g '!composer.lock' \
      -g '!*.tsbuildinfo' \
      2>&1
  )"
  content_status=$?
  path_candidates="$(
    find "$repo" \
      \( -type d \( -name .git -o -name vendor -o -name node_modules -o -name storage -o -name build -o -name dist -o -name coverage \) -prune \) -o \
      \( -type f ! -name CHANGELOG.md ! -name package-lock.json ! -name composer.lock ! -name '*.tsbuildinfo' -print \) -o \
      \( -type d -print \)
  )"
  find_status=$?
  path_findings="$(printf '%s\n' "$path_candidates" | rg --line-number --ignore-case "$pattern")"
  path_status=$?
  set -e

  if [ "$content_status" -gt 1 ] || [ "$find_status" -ne 0 ] || [ "$path_status" -gt 1 ]; then
    echo "Native workflow artifact audit could not search $repo." >&2
    exit 2
  fi

  findings="${content_findings}${content_findings:+$'\n'}${path_findings}"

  if [ -n "$findings" ]; then
    has_findings=1
    echo "Active legacy local-container references remain in $repo:" >&2
    echo "$findings" >&2
  fi
done

if [ "$has_findings" -ne 0 ]; then
  exit 1
fi

echo "Native workflow artifact audit passed for $# repository path(s)."

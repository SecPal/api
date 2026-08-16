#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 SecPal Contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

set -euo pipefail

repo_root="$(cd "$(dirname "$0")/.." && pwd)"
fixture="$(mktemp -d "${TMPDIR:-/tmp}/api-pr-size-advisory.XXXXXX")"
trap 'rm -rf -- "$fixture"' EXIT

mkdir -p "$fixture/scripts" "$fixture/bin"
cp "$repo_root/scripts/preflight.sh" "$fixture/scripts/preflight.sh"

for command in npx npm reuse; do
  printf '#!/usr/bin/env bash\nexit 0\n' >"$fixture/bin/$command"
  chmod +x "$fixture/bin/$command"
done

(
  cd "$fixture"
  git init --quiet --initial-branch=main
  git config user.name "SecPal Test"
  git config user.email "test@secpal.dev"
  git config commit.gpgSign false
  : >seed.txt
  git add .
  git commit --quiet -m "test: seed fixture"
  git remote add origin "$fixture"
  git update-ref refs/remotes/origin/main HEAD
  git symbolic-ref refs/remotes/origin/HEAD refs/remotes/origin/main
  git checkout --quiet -b test-branch
  awk 'BEGIN { for (line = 1; line <= 601; line++) print "line " line }' >large.txt
  git add large.txt
  git commit --quiet -m "test: exceed advisory threshold"
)

set +e
(cd "$fixture" && PATH="$fixture/bin:/usr/bin:/bin" bash scripts/preflight.sh) \
  >"$fixture/stdout" 2>"$fixture/stderr"
status=$?
set -e

test "$status" -eq 0
grep -Fq "PR size: 601 changed lines (601 insertions, 0 deletions; advisory threshold: 600)" \
  "$fixture/stderr"
grep -Fq "WARNING: PR size advisory threshold exceeded." "$fixture/stderr"

mkdir -p "$fixture/LICENSES"
printf '^LICENSES/.*\\.txt$\n' >"$fixture/.preflight-exclude"
awk 'BEGIN { for (line = 1; line <= 601; line++) print "license " line }' \
  >"$fixture/LICENSES/license.txt"
(
  cd "$fixture"
  git add .preflight-exclude LICENSES/license.txt
  git commit --quiet -m "test: exclude anchored license path"
)
set +e
(cd "$fixture" && PATH="$fixture/bin:/usr/bin:/bin" bash scripts/preflight.sh) \
  >"$fixture/excluded-stdout" 2>"$fixture/excluded-stderr"
excluded_status=$?
set -e
test "$excluded_status" -eq 0
grep -Fq "PR size: 602 changed lines (602 insertions, 0 deletions; advisory threshold: 600)" \
  "$fixture/excluded-stderr"

printf '[\n' >"$fixture/.preflight-exclude"
(
  cd "$fixture"
  git add .preflight-exclude
  git commit --quiet -m "test: add invalid exclusion"
)
set +e
(cd "$fixture" && PATH="$fixture/bin:/usr/bin:/bin" bash scripts/preflight.sh) \
  >"$fixture/invalid-stdout" 2>"$fixture/invalid-stderr"
invalid_status=$?
set -e
test "$invalid_status" -eq 0
grep -Fq "contains invalid regex pattern(s)" "$fixture/invalid-stderr"
grep -Fq "WARNING: PR size advisory threshold exceeded." "$fixture/invalid-stderr"

if grep -Fq ".preflight-allow-large-pr" "$repo_root/scripts/preflight.sh" ||
  grep -Fq "Maximum allowed: 600" "$repo_root/scripts/preflight.sh" ||
  grep -Fq "PR TOO LARGE" "$repo_root/scripts/preflight.sh"; then
  echo "Obsolete hard-size policy remains active" >&2
  exit 1
fi

if ! grep -Eq \
  '^    uses: SecPal/\.github/\.github/workflows/reusable-pr-size\.yml@[0-9a-f]{40}$' \
  "$repo_root/.github/workflows/pr-size.yml"; then
  echo "Hosted PR-size workflow must use a full immutable commit SHA" >&2
  exit 1
fi

grep -Fqx 'LICENSES/.*\.txt' "$repo_root/.preflight-exclude"

echo "tests/pr-size-advisory.sh: advisory PR-size reporting verified."

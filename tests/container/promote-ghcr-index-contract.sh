#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 SecPal Contributors
# SPDX-License-Identifier: AGPL-3.0-or-later AND LicenseRef-SecPal-Attribution

set -euo pipefail

root=$(git rev-parse --show-toplevel)
script="$root/scripts/promote-ghcr-index.sh"
fake_curl="$root/tests/fixtures/fake-ghcr-curl.sh"
tmp_dir=$(mktemp -d)
trap 'rm -rf "$tmp_dir"' EXIT

manifest_file="$tmp_dir/index.json"
other_manifest_file="$tmp_dir/other-index.json"
printf '%s' '{"schemaVersion":2,"mediaType":"application/vnd.oci.image.index.v1+json","manifests":[]}' > "$manifest_file"
printf '%s' '{"schemaVersion":2,"mediaType":"application/vnd.oci.image.index.v1+json","manifests":[{}]}' > "$other_manifest_file"
expected_digest="sha256:$(sha256sum "$manifest_file" | awk '{print $1}')"
commit_sha=0123456789abcdef0123456789abcdef01234567
target_tag="sha-${commit_sha}"
candidate_tag="candidate-${commit_sha}-123456789-1"

assert_no_extended_match() {
  local pattern=$1
  local file=$2

  if grep -Eq "$pattern" "$file"; then
    printf 'Unexpected curl operation in %s\n' "$file" >&2
    exit 1
  fi
}

assert_no_final_put() {
  local file=$1

  if grep -q "^PUT https://ghcr.io/v2/secpal/api/manifests/${target_tag}$" "$file"; then
    printf 'Unexpected final tag write in %s\n' "$file" >&2
    exit 1
  fi
}

run_case() {
  local name=$1
  local scenario=$2
  local expected_status=$3
  local host=${4:-ghcr.io}
  local repository=${5:-secpal/api}
  local target=${6:-$target_tag}
  local source_digest=${7:-$expected_digest}
  local candidate=${8:-$candidate_tag}
  local case_dir="$tmp_dir/$name"
  local status

  mkdir -p "$case_dir/bin" "$case_dir/state"
  ln -s "$fake_curl" "$case_dir/bin/curl"

  set +e
  PATH="$case_dir/bin:$PATH" \
    FAKE_SCENARIO="$scenario" \
    FAKE_CURL_LOG="$case_dir/curl.log" \
    FAKE_STATE_DIR="$case_dir/state" \
    FAKE_MANIFEST_FILE="$manifest_file" \
    FAKE_OTHER_MANIFEST_FILE="$other_manifest_file" \
    FAKE_EXPECTED_DIGEST="$expected_digest" \
    FAKE_TOKEN=registry-secret-token \
    GHCR_USERNAME=workflow-user \
    GHCR_TOKEN=workflow-token \
    "$script" "$host" "$repository" "$target" "$source_digest" \
      "$candidate" \
      > "$case_dir/stdout" 2> "$case_dir/stderr"
  status=$?
  set -e

  if [ "$expected_status" = success ]; then
    test "$status" -eq 0
  else
    test "$status" -ne 0
  fi

  if grep -Fq 'registry-secret-token' "$case_dir/stdout" "$case_dir/stderr" \
    || grep -Fq 'workflow-token' "$case_dir/stdout" "$case_dir/stderr"; then
    printf 'Registry credentials leaked in scenario %s\n' "$name" >&2
    exit 1
  fi
}

run_case success success success
grep -q "^PUT https://ghcr.io/v2/secpal/api/manifests/${candidate_tag}$" "$tmp_dir/success/curl.log"
test "$(grep -c '^PUT https://ghcr.io/v2/secpal/api/manifests/' "$tmp_dir/success/curl.log")" -eq 2
assert_no_extended_match '^(DELETE|PATCH|POST) ' "$tmp_dir/success/curl.log"

run_case precondition-ignored precondition-ignored failure
test "$(grep -c '^PUT https://ghcr.io/v2/secpal/api/manifests/' \
  "$tmp_dir/precondition-ignored/curl.log")" -eq 1
if grep -q "^PUT https://ghcr.io/v2/secpal/api/manifests/${target_tag}$" \
  "$tmp_dir/precondition-ignored/curl.log"; then
  printf 'Final tag write occurred after a failed conditional-write probe\n' >&2
  exit 1
fi

run_case candidate-moved candidate-moved failure
test "$(grep -c '^PUT https://ghcr.io/v2/secpal/api/manifests/' \
  "$tmp_dir/candidate-moved/curl.log")" -eq 1
if grep -q "^PUT https://ghcr.io/v2/secpal/api/manifests/${target_tag}$" \
  "$tmp_dir/candidate-moved/curl.log"; then
  printf 'Final tag write occurred after the candidate digest changed\n' >&2
  exit 1
fi

run_case lowercase-headers lowercase-headers success
run_case source-content-type-parameters source-content-type-parameters success

for scenario in source-header-mismatch source-body-mismatch source-content-type; do
  run_case "$scenario" "$scenario" failure
  assert_no_extended_match '^PUT ' "$tmp_dir/$scenario/curl.log"
done

run_case target-same target-same success
assert_no_final_put "$tmp_dir/target-same/curl.log"

run_case target-different target-different failure
assert_no_final_put "$tmp_dir/target-different/curl.log"

for status in 401 403 429 500 503; do
  run_case "target-$status" "target-$status" failure
  assert_no_final_put "$tmp_dir/target-$status/curl.log"
done

run_case put-digest-mismatch put-digest-mismatch failure
run_case target-race-same target-race-same success
run_case target-race-different target-race-different failure

run_case wrong-host success failure docker.io
test ! -s "$tmp_dir/wrong-host/curl.log"
run_case wrong-repository success failure ghcr.io other/api
test ! -s "$tmp_dir/wrong-repository/curl.log"
run_case wrong-target success failure ghcr.io secpal/api latest
test ! -s "$tmp_dir/wrong-target/curl.log"
run_case wrong-digest success failure ghcr.io secpal/api "$target_tag" sha256:1234
test ! -s "$tmp_dir/wrong-digest/curl.log"
run_case wrong-candidate success failure ghcr.io secpal/api "$target_tag" \
  "$expected_digest" candidate-invalid
test ! -s "$tmp_dir/wrong-candidate/curl.log"
other_commit_sha=abcdef0123456789abcdef0123456789abcdef01
run_case wrong-candidate-sha success failure ghcr.io secpal/api "$target_tag" \
  "$expected_digest" "candidate-${other_commit_sha}-123456789-1"
test ! -s "$tmp_dir/wrong-candidate-sha/curl.log"

printf 'GHCR index promotion contract passed\n'

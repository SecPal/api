#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 SecPal Contributors
# SPDX-License-Identifier: MIT

set -euo pipefail

fail() {
  printf 'GHCR index promotion failed: %s\n' "$1" >&2
  exit 1
}

header_value() {
  local header_name=$1
  local header_file=$2

  awk -v name="$header_name" '
    index(tolower($0), tolower(name) ":") == 1 {
      sub("^[^:]+:[[:space:]]*", "")
      sub("\\r$", "")
      value = $0
    }
    END { print value }
  ' "$header_file"
}

validate_index_response() {
  local body_file=$1
  local header_file=$2
  local expected_digest=$3
  local response_digest
  local content_type
  local body_digest

  response_digest=$(header_value Docker-Content-Digest "$header_file")
  test "$response_digest" = "$expected_digest" \
    || fail 'registry digest header does not match the selected digest'

  content_type=$(header_value Content-Type "$header_file")
  content_type=${content_type%%;*}
  test "$content_type" = application/vnd.oci.image.index.v1+json \
    || fail 'registry response is not an OCI image index'

  body_digest="sha256:$(sha256sum "$body_file" | awk '{print $1}')"
  test "$body_digest" = "$expected_digest" \
    || fail 'registry response bytes do not match the selected digest'

  jq -e '.mediaType == "application/vnd.oci.image.index.v1+json"' "$body_file" > /dev/null \
    || fail 'manifest mediaType is not an OCI image index'
}

test "$#" -eq 5 \
  || fail 'expected host, repository, target tag, source digest, and candidate tag'

host=$1
repository=$2
target_tag=$3
source_digest=$4
candidate_tag=$5

test "$host" = ghcr.io || fail 'host must be ghcr.io'
test "$repository" = secpal/api || fail 'repository must be secpal/api'
[[ "$target_tag" =~ ^sha-[0-9a-f]{40}$ ]] || fail 'target must be a full lowercase SHA tag'
[[ "$source_digest" =~ ^sha256:[0-9a-f]{64}$ ]] || fail 'source must be a lowercase SHA-256 digest'
[[ "$candidate_tag" =~ ^candidate-${target_tag#sha-}-[0-9]+-[0-9]+$ ]] \
  || fail 'candidate must include the target SHA, run ID, and run attempt'
test -n "${GHCR_USERNAME:-}" || fail 'GHCR_USERNAME is required'
test -n "${GHCR_TOKEN:-}" || fail 'GHCR_TOKEN is required'

tmp_dir=$(mktemp -d)
trap 'rm -rf "$tmp_dir"' EXIT

source_body="$tmp_dir/source.json"
source_headers="$tmp_dir/source.headers"
target_body="$tmp_dir/target.json"
target_headers="$tmp_dir/target.headers"
put_headers="$tmp_dir/put.headers"
probe_body="$tmp_dir/probe.json"
probe_headers="$tmp_dir/probe.headers"
registry_url="https://${host}/v2/${repository}/manifests"
manifest_accept=application/vnd.oci.image.index.v1+json

registry_token=$(curl --fail --silent --show-error \
  --user "${GHCR_USERNAME}:${GHCR_TOKEN}" \
  --get \
  --data-urlencode "scope=repository:${repository}:pull,push" \
  --data-urlencode "service=${host}" \
  "https://${host}/token" | jq -er '.token')

source_status=$(curl --silent --show-error \
  --output "$source_body" \
  --dump-header "$source_headers" \
  --write-out '%{http_code}' \
  --header "Authorization: Bearer ${registry_token}" \
  --header "Accept: ${manifest_accept}" \
  "${registry_url}/${source_digest}")
test "$source_status" = 200 || fail "source manifest returned HTTP ${source_status}"
validate_index_response "$source_body" "$source_headers" "$source_digest"
source_content_type=$(header_value Content-Type "$source_headers")

conditional_put() {
  local reference=$1
  local headers_file=$2

  curl --silent --show-error \
    --output /dev/null \
    --dump-header "$headers_file" \
    --write-out '%{http_code}' \
    --request PUT \
    --header "Authorization: Bearer ${registry_token}" \
    --header "Content-Type: ${source_content_type}" \
    --header 'If-None-Match: *' \
    --data-binary "@${source_body}" \
    "${registry_url}/${reference}"
}

# Prove that this GHCR endpoint enforces create-only manifest writes before the
# final tag is touched. Re-sending the same bytes to the already existing,
# run-unique candidate is harmless even if the precondition is ignored.
probe_status=$(conditional_put "$candidate_tag" "$probe_headers")
test "$probe_status" = 412 \
  || fail "registry did not enforce the conditional-write probe (HTTP ${probe_status})"

probe_status=$(curl --silent --show-error \
  --output "$probe_body" \
  --dump-header "$probe_headers" \
  --write-out '%{http_code}' \
  --header "Authorization: Bearer ${registry_token}" \
  --header "Accept: ${manifest_accept}" \
  "${registry_url}/${candidate_tag}")
test "$probe_status" = 200 || fail "candidate verification returned HTTP ${probe_status}"
validate_index_response "$probe_body" "$probe_headers" "$source_digest"

read_target() {
  curl --silent --show-error \
    --output "$target_body" \
    --dump-header "$target_headers" \
    --write-out '%{http_code}' \
    --header "Authorization: Bearer ${registry_token}" \
    --header "Accept: ${manifest_accept}" \
    "${registry_url}/${target_tag}"
}

target_status=$(read_target)
case "$target_status" in
  200)
    validate_index_response "$target_body" "$target_headers" "$source_digest"
    printf 'Final SHA tag already references %s\n' "$source_digest"
    exit 0
    ;;
  404)
    ;;
  *)
    fail "target manifest lookup returned HTTP ${target_status}"
    ;;
esac

put_status=$(conditional_put "$target_tag" "$put_headers")

case "$put_status" in
  201)
    put_digest=$(header_value Docker-Content-Digest "$put_headers")
    test "$put_digest" = "$source_digest" \
      || fail 'promotion response digest does not match the selected digest'
    ;;
  409|412)
    ;;
  *)
    fail "promotion returned HTTP ${put_status}"
    ;;
esac

target_status=$(read_target)
test "$target_status" = 200 || fail "final manifest verification returned HTTP ${target_status}"
validate_index_response "$target_body" "$target_headers" "$source_digest"
printf 'Promoted final SHA tag to %s\n' "$source_digest"

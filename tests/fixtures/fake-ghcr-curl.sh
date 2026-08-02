#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 SecPal Contributors
# SPDX-License-Identifier: MIT

set -euo pipefail

method=GET
output_file=
header_file=
write_out=
data_file=
url=
request_content_type=
request_precondition=

while [ "$#" -gt 0 ]; do
  case "$1" in
    --output|-o)
      output_file=$2
      shift 2
      ;;
    --dump-header|-D)
      header_file=$2
      shift 2
      ;;
    --write-out|-w)
      write_out=$2
      shift 2
      ;;
    --request|-X)
      method=$2
      shift 2
      ;;
    --data-binary)
      data_file=${2#@}
      shift 2
      ;;
    --get)
      method=GET
      shift
      ;;
    --header)
      case "$2" in
        Content-Type:*) request_content_type=$2 ;;
        If-None-Match:*) request_precondition=$2 ;;
      esac
      shift 2
      ;;
    --user|--data-urlencode)
      shift 2
      ;;
    --fail|--silent|--show-error|--location)
      shift
      ;;
    https://*)
      url=$1
      shift
      ;;
    *)
      printf 'Unexpected fake curl argument: %s\n' "$1" >&2
      exit 64
      ;;
  esac
done

test -n "$url"
printf '%s %s\n' "$method" "$url" >> "$FAKE_CURL_LOG"

status=200
content_type=application/vnd.oci.image.index.v1+json
digest=$FAKE_EXPECTED_DIGEST
body_file=$FAKE_MANIFEST_FILE

target_count_file="$FAKE_STATE_DIR/target-count"
target_count=0
if [ -f "$target_count_file" ]; then
  target_count=$(cat "$target_count_file")
fi

case "$url" in
  https://ghcr.io/token)
    body_file=
    ;;
  */manifests/sha256:*)
    case "$FAKE_SCENARIO" in
      source-header-mismatch)
        digest="sha256:$(printf '1%.0s' {1..64})"
        ;;
      source-body-mismatch)
        body_file="$FAKE_OTHER_MANIFEST_FILE"
        ;;
      source-content-type)
        content_type=application/vnd.oci.image.manifest.v1+json
        ;;
      source-content-type-parameters)
        content_type='application/vnd.oci.image.index.v1+json; charset=utf-8'
        ;;
    esac
    ;;
  */manifests/sha-*)
    if [ "$method" = PUT ]; then
      test -n "$data_file"
      cmp "$data_file" "$FAKE_MANIFEST_FILE"
      test "$request_precondition" = 'If-None-Match: *'
      if [ "$FAKE_SCENARIO" = source-content-type-parameters ]; then
        test "$request_content_type" = \
          'Content-Type: application/vnd.oci.image.index.v1+json; charset=utf-8'
      else
        test "$request_content_type" = \
          'Content-Type: application/vnd.oci.image.index.v1+json'
      fi
      case "$FAKE_SCENARIO" in
        put-digest-mismatch)
          digest="sha256:$(printf '2%.0s' {1..64})"
          ;;
        target-race-same|target-race-different)
          status=412
          digest=
          ;;
        *)
          status=201
          ;;
      esac
    else
      target_count=$((target_count + 1))
      printf '%s\n' "$target_count" > "$target_count_file"
      case "$FAKE_SCENARIO" in
        target-same)
          ;;
        target-different)
          digest="sha256:$(printf '3%.0s' {1..64})"
          ;;
        target-401|target-403|target-429|target-500|target-503)
          status=${FAKE_SCENARIO#target-}
          digest=
          ;;
        target-race-same)
          if [ "$target_count" -eq 1 ]; then
            status=404
            digest=
          fi
          ;;
        target-race-different)
          if [ "$target_count" -eq 1 ]; then
            status=404
            digest=
          else
            digest="sha256:$(printf '4%.0s' {1..64})"
          fi
          ;;
        *)
          if [ "$target_count" -eq 1 ]; then
            status=404
            digest=
          fi
          ;;
      esac
    fi
    ;;
  *)
    printf 'Unexpected fake curl URL: %s\n' "$url" >&2
    exit 64
    ;;
esac

if [ -n "$header_file" ]; then
  {
    printf 'HTTP/1.1 %s Fake\r\n' "$status"
    if [ -n "$content_type" ]; then
      if [ "$FAKE_SCENARIO" = lowercase-headers ]; then
        printf 'content-type: %s\r\n' "$content_type"
      else
        printf 'Content-Type: %s\r\n' "$content_type"
      fi
    fi
    if [ -n "$digest" ]; then
      if [ "$FAKE_SCENARIO" = lowercase-headers ]; then
        printf 'docker-content-digest: %s\r\n' "$digest"
      else
        printf 'Docker-Content-Digest: %s\r\n' "$digest"
      fi
    fi
    printf '\r\n'
  } > "$header_file"
fi

if [ -n "$output_file" ]; then
  if [ "$output_file" != /dev/null ]; then
    if [ -n "$body_file" ] && [ "$status" = 200 ]; then
      cp "$body_file" "$output_file"
    else
      : > "$output_file"
    fi
  fi
elif [ "$url" = https://ghcr.io/token ]; then
  printf '{"token":"%s"}' "$FAKE_TOKEN"
fi

if [ -n "$write_out" ]; then
  printf '%s' "$status"
fi

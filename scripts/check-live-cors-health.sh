#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 SecPal
# SPDX-License-Identifier: MIT

set -euo pipefail

API_URL="${API_URL:-https://api.secpal.dev}"
SPA_ORIGIN="${SPA_ORIGIN:-https://app.secpal.dev}"
HEALTH_URL="${API_URL%/}/health"

get_header_value() {
    local headers="$1"
    local header_name="$2"

    printf '%s\n' "$headers" | awk -v target="$header_name" '
        BEGIN {
            FS = ": "
        }
        tolower($1) == tolower(target) {
            gsub(/\r/, "", $2)
            print $2
            exit
        }
    '
}

get_status_code() {
    local headers="$1"

    printf '%s\n' "$headers" | awk 'NR == 1 { print $2; exit }'
}

assert_equals() {
    local actual="$1"
    local expected="$2"
    local message="$3"

    if [[ "$actual" != "$expected" ]]; then
        echo "ERROR: ${message}"
        echo "  expected: ${expected}"
        echo "  actual:   ${actual}"
        exit 1
    fi
}

assert_contains() {
    local actual="$1"
    local expected_fragment="$2"
    local message="$3"

    if [[ "$actual" != *"$expected_fragment"* ]]; then
        echo "ERROR: ${message}"
        echo "  expected fragment: ${expected_fragment}"
        echo "  actual:            ${actual}"
        exit 1
    fi
}

echo "Checking live CORS behavior for ${HEALTH_URL} with Origin ${SPA_ORIGIN}"

get_headers=$(curl --silent --show-error --location --retry 2 --retry-delay 2 \
    --dump-header - --output /dev/null \
    --header "Origin: ${SPA_ORIGIN}" \
    "${HEALTH_URL}")

get_status=$(get_status_code "$get_headers")
get_allow_origin=$(get_header_value "$get_headers" "Access-Control-Allow-Origin")
get_allow_credentials=$(get_header_value "$get_headers" "Access-Control-Allow-Credentials")

assert_equals "$get_status" "200" "GET /health returned an unexpected status code"
assert_equals "$get_allow_origin" "$SPA_ORIGIN" "GET /health returned an unexpected Access-Control-Allow-Origin header"
assert_equals "$get_allow_credentials" "true" "GET /health returned an unexpected Access-Control-Allow-Credentials header"

echo "GET /health CORS headers look correct"

options_headers=$(curl --silent --show-error --location --retry 2 --retry-delay 2 \
    --request OPTIONS \
    --dump-header - --output /dev/null \
    --header "Origin: ${SPA_ORIGIN}" \
    --header "Access-Control-Request-Method: GET" \
    "${HEALTH_URL}")

options_status=$(get_status_code "$options_headers")
options_allow_origin=$(get_header_value "$options_headers" "Access-Control-Allow-Origin")
options_allow_credentials=$(get_header_value "$options_headers" "Access-Control-Allow-Credentials")
options_allow_methods=$(get_header_value "$options_headers" "Access-Control-Allow-Methods")

if [[ "$options_status" != "200" && "$options_status" != "204" ]]; then
    echo "ERROR: OPTIONS /health returned an unexpected status code"
    echo "  expected: 200 or 204"
    echo "  actual:   ${options_status}"
    exit 1
fi

assert_equals "$options_allow_origin" "$SPA_ORIGIN" "OPTIONS /health returned an unexpected Access-Control-Allow-Origin header"
assert_equals "$options_allow_credentials" "true" "OPTIONS /health returned an unexpected Access-Control-Allow-Credentials header"
assert_contains "$options_allow_methods" "GET" "OPTIONS /health did not advertise GET in Access-Control-Allow-Methods"

echo "OPTIONS /health CORS headers look correct"
echo "Live CORS smoke test passed"

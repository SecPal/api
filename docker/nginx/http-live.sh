#!/bin/sh
# SPDX-FileCopyrightText: 2026 SecPal Contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
set -eu

port=${SECPAL_HTTP_PORT:-8080}
timeout=${SECPAL_HEALTH_TIMEOUT:-3}
response=$(curl --fail --silent --show-error --max-time "$timeout" \
    --noproxy '*' --proto '=http' --write-out '\n%{http_code}' \
    "http://127.0.0.1:${port}/health/live")
case "$response" in
    *'"status":"alive"'*"
200") exit 0 ;;
    *) exit 1 ;;
esac

#!/bin/sh
# SPDX-FileCopyrightText: 2026 SecPal Contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
set -eu

port=${SECPAL_HTTP_PORT:-8080}
timeout=${SECPAL_HEALTH_TIMEOUT:-3}
response=$(timeout "$timeout" wget -q -S -T "$timeout" -Y off -O - \
    "http://127.0.0.1:${port}/health/live" 2>&1) || {
    printf '%s\n' "$response" >&2
    exit 1
}
case "$response" in
    "  HTTP/1.0 200 "*'"status":"alive"'*|"  HTTP/1.1 200 "*'"status":"alive"'*) exit 0 ;;
    *) exit 1 ;;
esac

#!/bin/sh
# SPDX-FileCopyrightText: 2026 SecPal Contributors
# SPDX-License-Identifier: MIT

set -eu

port=${SECPAL_HTTP_PORT:-8080}
timeout=${SECPAL_HEALTH_TIMEOUT:-3}

# shellcheck disable=SC2016
exec php -r '
$context = stream_context_create(["http" => ["ignore_errors" => true, "timeout" => (float) $argv[2]]]);
$body = @file_get_contents($argv[1], false, $context);
$status = $http_response_header[0] ?? "";
exit($body !== false && str_contains($status, " 200 ") && str_contains($body, "\"status\":\"alive\"") ? 0 : 1);
' "http://127.0.0.1:${port}/health/live" "$timeout"

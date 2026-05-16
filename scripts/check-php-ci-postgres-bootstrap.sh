#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 SecPal Contributors
# SPDX-License-Identifier: MIT

set -euo pipefail

ROOT_DIR="$(git rev-parse --show-toplevel)"
PHP_CI="$ROOT_DIR/.github/workflows/php-ci.yml"

require_line() {
  local needle="$1"
  if ! grep -Fqx "$needle" "$PHP_CI"; then
    echo "Missing expected php-ci.yml line: $needle" >&2
    exit 1
  fi
}

reject_line() {
  local needle="$1"
  if grep -Fqx "$needle" "$PHP_CI"; then
    echo "Unexpected outdated php-ci.yml line still present: $needle" >&2
    exit 1
  fi
}

require_line '          POSTGRES_DB: testing'
require_line '          POSTGRES_USER: testing'
require_line '          POSTGRES_PASSWORD: testing'
require_line '          --health-cmd="pg_isready -U testing"'
require_line '          PGPASSWORD: testing'
require_line "            psql -h localhost -U testing -d testing -c \"CREATE DATABASE testing_test_\$i;\" || true"
require_line '          DB_USERNAME: testing'
require_line '          DB_PASSWORD: testing'

reject_line '          POSTGRES_USER: db'
reject_line '          POSTGRES_PASSWORD: db'
reject_line '          PGPASSWORD: db'
reject_line "            psql -h localhost -U db -d testing -c \"CREATE DATABASE testing_test_\$i;\" || true"
reject_line '          DB_USERNAME: db'
reject_line '          DB_PASSWORD: db'

echo 'php-ci Postgres bootstrap matches the testing/testing_test_* convention'

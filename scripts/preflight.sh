#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 SecPal Contributors
# SPDX-License-Identifier: MIT

set -euo pipefail

ROOT_DIR="$(git rev-parse --show-toplevel)"
cd "$ROOT_DIR"

# Check if pushing from a protected branch
CURRENT_BRANCH=$(git symbolic-ref --short HEAD 2>/dev/null || echo "detached")
PROTECTED_BRANCHES=("main" "master" "production")

for branch in "${PROTECTED_BRANCHES[@]}"; do
  if [ "$CURRENT_BRANCH" = "$branch" ]; then
    echo ""
    echo "❌ BLOCKED: Direct push from protected branch '$branch' is not allowed!"
    echo ""
    echo "Protected branches should only be updated via pull requests."
    echo "Please create a feature branch and submit a PR instead:"
    echo ""
    echo "  git checkout -b feat/your-feature-name"
    echo "  git commit -am 'Your changes'"
    echo "  git push -u origin feat/your-feature-name"
    echo ""
    echo "EMERGENCY EXCEPTION: If you must bypass this check:"
    echo "  1. Document the reason for the bypass"
    echo "  2. Create an issue to track the technical debt"
    echo "  3. Fix the underlying issue within 24 hours"
    echo "  4. Use: git push --no-verify"
    echo ""
    exit 1
  fi
done

# Auto-detect default branch (fallback to main)
# Use symbolic-ref instead of remote show to avoid network hang
BASE="$(git symbolic-ref refs/remotes/origin/HEAD 2>/dev/null | sed 's@^refs/remotes/origin/@@')"
[ -z "${BASE:-}" ] && BASE="main"

echo "Using base branch: $BASE"

# Fetch base branch for PR size check (failure is handled later)
git fetch origin "$BASE" 2>/dev/null || true

# 0) Formatting & Compliance
FORMAT_EXIT=0
if command -v npx >/dev/null 2>&1; then
  npx --yes prettier --check '**/*.{md,yml,yaml,json,ts,tsx,js,jsx}' || FORMAT_EXIT=1
  npx --yes --package markdownlint-cli@0.49.0 markdownlint --config .markdownlint.json --dot '**/*.md' --ignore-path .gitignore --ignore node_modules --ignore vendor --ignore storage --ignore build --ignore .git || FORMAT_EXIT=1
fi
# Workflow linting (part of documented gates)
# NOTE: actionlint is disabled in local preflight due to known hanging issues
# It runs in CI via .github/workflows/actionlint.yml instead
if [ -d .github/workflows ]; then
  if command -v actionlint >/dev/null 2>&1; then
    echo "ℹ️  Skipping actionlint in local preflight (runs in CI)" >&2
    # Uncomment below to enable (may hang):
    # timeout 30 actionlint || FORMAT_EXIT=1
  else
    echo "Warning: .github/workflows found but actionlint not installed - skipping workflow lint" >&2
  fi
fi
if [ -f scripts/check-php-ci-postgres-bootstrap.sh ]; then
  bash scripts/check-php-ci-postgres-bootstrap.sh || FORMAT_EXIT=1
fi
if command -v reuse >/dev/null 2>&1; then
  reuse lint || FORMAT_EXIT=1
fi
if [ "$FORMAT_EXIT" -ne 0 ]; then
  echo "Formatting/compliance checks failed. Fix issues above." >&2
  exit 1
fi

# Domain Policy Check (CRITICAL: ZERO TOLERANCE)
if [ -f scripts/check-domains.sh ]; then
  bash scripts/check-domains.sh || {
    echo "" >&2
    echo "❌ Domain Policy Violation detected!" >&2
    echo "Fix the violations above before committing." >&2
    exit 1
  }
fi

# 1) PHP / Laravel
if [ -f composer.json ]; then
  if ! command -v composer >/dev/null 2>&1; then
    echo "Warning: composer.json found but composer not installed - skipping PHP checks" >&2
  else
    # OPTIMIZATION: Skip install if vendor is up-to-date with composer.lock (massive time saver)
    # Force install via: PREFLIGHT_FORCE_INSTALL=1 git push
    NEEDS_INSTALL=0
    if [ "${PREFLIGHT_FORCE_INSTALL:-0}" = "1" ] || [ ! -d vendor ]; then
      NEEDS_INSTALL=1
    elif [ -f composer.lock ] && [ composer.lock -nt vendor ]; then
      # composer.lock modified after vendor/ - reinstall needed
      NEEDS_INSTALL=1
    elif [ ! -f composer.lock ] && [ -f composer.json ] && [ composer.json -nt vendor ]; then
      # No lock file but composer.json newer than vendor/ - reinstall needed
      NEEDS_INSTALL=1
    fi

    if [ "$NEEDS_INSTALL" -eq 1 ]; then
      composer install --no-interaction --no-progress --prefer-dist --optimize-autoloader
    else
      echo "ℹ️  Skipping composer install (dependencies up-to-date, force via PREFLIGHT_FORCE_INSTALL=1)" >&2
    fi

    # Run Laravel Pint code style check if available (blocking: aligns with gates)
    # Workflow: check → fix if needed → verify (per SELF_REVIEW_CHECKLIST.md)
    if [ -x ./vendor/bin/pint ]; then
      echo "→ Checking code style (pint --test --dirty)..."
      if ! ./vendor/bin/pint --test --dirty; then
        echo "→ Auto-fixing code style issues (pint --dirty)..."
        ./vendor/bin/pint --dirty
        echo "→ Verifying fix matches CI requirements (pint --test --dirty)..."
        ./vendor/bin/pint --test --dirty
      fi
    fi
    # Run PHPStan (use configured level from phpstan.neon if exists, else max)
    if [ -x ./vendor/bin/phpstan ]; then
      if [ -f phpstan.neon ] || [ -f phpstan.neon.dist ]; then
        php -d memory_limit=512M ./vendor/bin/phpstan analyse
      else
        php -d memory_limit=512M ./vendor/bin/phpstan analyse --level=max
      fi
    fi
    # Run tests (Laravel Artisan → Pest → PHPUnit)
    # OPTIMIZATION: Tests are SKIPPED by default in pre-push hook for speed
    # Enable via: PREFLIGHT_RUN_TESTS=1 git push
    # Tests always run in CI, so local skip is safe
    if [ "${PREFLIGHT_RUN_TESTS:-0}" = "1" ]; then
      echo "→ Running tests (enabled via PREFLIGHT_RUN_TESTS=1)..."
      TEST_EXIT=0
      if [ -f artisan ]; then
        php artisan test --parallel --exclude-group=serial || TEST_EXIT=$?
        php artisan test --group=serial || TEST_EXIT=$?
      elif [ -x ./vendor/bin/pest ]; then
        ./vendor/bin/pest --parallel || TEST_EXIT=$?
      elif [ -x ./vendor/bin/phpunit ]; then
        ./vendor/bin/phpunit || TEST_EXIT=$?
      fi

      if [ "$TEST_EXIT" -ne 0 ]; then
        echo "" >&2
        echo "❌ Tests failed. Please fix the failing tests before pushing." >&2
        echo "" >&2
        exit "$TEST_EXIT"
      fi
    else
      echo "ℹ️  Skipping tests in pre-push hook (enable via PREFLIGHT_RUN_TESTS=1)" >&2
      echo "   ⚠️  Enable for behavior, security, or state-lifecycle changes" >&2
      echo "   Tests will run in CI pipeline" >&2
    fi
  fi
fi

# 2) Node / React
if [ -f pnpm-lock.yaml ] && command -v pnpm >/dev/null 2>&1; then
  pnpm install --frozen-lockfile
  # Check if scripts exist before running (pnpm run <script> exits 0 with --if-present)
  pnpm run --if-present lint
  pnpm run --if-present typecheck
  pnpm run --if-present test
elif [ -f package-lock.json ] && command -v npm >/dev/null 2>&1; then
  npm ci
  npm run --if-present lint
  npm run --if-present typecheck
  npm run --if-present test
elif [ -f yarn.lock ] && command -v yarn >/dev/null 2>&1; then
  yarn install --frozen-lockfile
  # Yarn doesn't have --if-present, check package.json using jq or Node.js
  if command -v jq >/dev/null 2>&1; then
    jq -e '.scripts.lint' package.json >/dev/null 2>&1 && yarn lint
    jq -e '.scripts.typecheck' package.json >/dev/null 2>&1 && yarn typecheck
    jq -e '.scripts.test' package.json >/dev/null 2>&1 && yarn test
  elif command -v node >/dev/null 2>&1; then
    node -e "process.exit(require('./package.json').scripts?.lint ? 0 : 1)" && yarn lint
    node -e "process.exit(require('./package.json').scripts?.typecheck ? 0 : 1)" && yarn typecheck
    node -e "process.exit(require('./package.json').scripts?.test ? 0 : 1)" && yarn test
  else
    echo "Warning: jq and node not found - attempting to run yarn scripts (failures will be ignored)" >&2
    yarn lint 2>/dev/null || true
    yarn typecheck 2>/dev/null || true
    yarn test 2>/dev/null || true
  fi
fi

# 3) OpenAPI (Spectral)
if [ -f docs/openapi.yaml ] && command -v npx >/dev/null 2>&1; then
  npx --yes @stoplight/spectral-cli lint docs/openapi.yaml
fi

# 4) Check PR size locally (against BASE)
if ! git rev-parse -q --verify "origin/$BASE" >/dev/null 2>&1; then
  echo "Warning: Cannot verify base branch origin/$BASE - skipping PR size check." >&2
  echo "Tip: Run 'git fetch origin $BASE' to enable PR size checking." >&2
else
  MERGE_BASE=$(git merge-base "origin/$BASE" HEAD 2>/dev/null)
  if [ -z "$MERGE_BASE" ]; then
    echo "Warning: Cannot determine merge base with origin/$BASE. Skipping PR size check." >&2
  else
    # Get raw diff output
    RAW_DIFF_OUTPUT=$(git diff --numstat "$MERGE_BASE"..HEAD 2>/dev/null)
    DIFF_OUTPUT="$RAW_DIFF_OUTPUT"

    # Load exclude patterns from .preflight-exclude if it exists
    if [ -f "$ROOT_DIR/.preflight-exclude" ]; then
      # Extract non-comment, non-empty lines as grep-compatible regex patterns
      # Strip CR for Windows/CRLF compatibility
      EXCLUDE_PATTERNS=$(grep -vE '^[[:space:]]*(#|$)' "$ROOT_DIR/.preflight-exclude" | tr -d '\r' || true)

      if [ -n "$EXCLUDE_PATTERNS" ]; then
        # Build regex alternation for efficient filtering (patterns are used as-is)
        EXCLUDE_REGEX=$(echo "$EXCLUDE_PATTERNS" | tr '\n' '|' | sed 's/|$//')

        # Validate regex and warn about dangerous patterns
        # grep exit codes: 0=match, 1=no match, 2=error (invalid regex)
        set +e  # Temporarily disable exit-on-error to capture grep's exit code
        echo "" | grep -qE -- "$EXCLUDE_REGEX" 2>/dev/null
        GREP_EXIT=$?
        set -e  # Re-enable exit-on-error
        if [ $GREP_EXIT -ne 2 ]; then
          # Pattern is valid (exit 0 or 1), check if it matches everything
          # Test against diverse filenames to detect overly broad patterns
          # Include various cases: lowercase, uppercase, numbers, special chars, hidden files
          if echo "test-file.txt" | grep -qE -- "$EXCLUDE_REGEX" && \
             echo "another-file.js" | grep -qE -- "$EXCLUDE_REGEX" && \
             echo "random.md" | grep -qE -- "$EXCLUDE_REGEX" && \
             echo "README.md" | grep -qE -- "$EXCLUDE_REGEX" && \
             echo "package.json" | grep -qE -- "$EXCLUDE_REGEX" && \
             echo ".hidden" | grep -qE -- "$EXCLUDE_REGEX" && \
             echo "File123.py" | grep -qE -- "$EXCLUDE_REGEX" && \
             echo "UPPERCASE" | grep -qE -- "$EXCLUDE_REGEX"; then
            echo "⚠️  WARNING: .preflight-exclude contains pattern that matches EVERYTHING (e.g., '.*')" >&2
            echo "This will exclude all files from PR size calculation!" >&2
          fi

          DIFF_OUTPUT=$(
            while IFS=$'\t' read -r insertions deletions path; do
              if ! printf '%s\n' "$path" | grep -qE -- "$EXCLUDE_REGEX"; then
                printf '%s\t%s\t%s\n' "$insertions" "$deletions" "$path"
              fi
            done <<< "$DIFF_OUTPUT"
          )
        else
          # Invalid regex - grep failed even on empty input
          echo "⚠️  WARNING: .preflight-exclude contains invalid regex pattern(s)" >&2
          echo "The pattern will be ignored. Please check your .preflight-exclude file." >&2
          echo "Common issues: unbalanced brackets [, unmatched (, trailing backslash \\" >&2
        fi

      fi
    fi

    PR_SIZE_ADVISORY_THRESHOLD=600

    # Report when all files were excluded, then continue with zero counts.
    if [ -n "$RAW_DIFF_OUTPUT" ] && [ -z "$DIFF_OUTPUT" ]; then
      echo "⚠️  All changed files are excluded (lock files, license files, etc.)" >&2
    fi

    # Use --numstat for locale-independent parsing.
    INSERTIONS=$(echo "$DIFF_OUTPUT" | awk '{ins+=$1} END {print ins+0}')
    DELETIONS=$(echo "$DIFF_OUTPUT" | awk '{del+=$2} END {print del+0}')
    CHANGED=$((INSERTIONS + DELETIONS))
    SIZE_REPORT="PR size: $CHANGED changed lines ($INSERTIONS insertions, $DELETIONS deletions; advisory threshold: $PR_SIZE_ADVISORY_THRESHOLD)"

    if [ "$CHANGED" -gt "$PR_SIZE_ADVISORY_THRESHOLD" ]; then
      echo "$SIZE_REPORT" >&2
      echo "WARNING: PR size advisory threshold exceeded." >&2
      echo "Keep this pull request focused on one logical topic and make the review plan explicit." >&2
    else
      echo "Preflight OK · $SIZE_REPORT"
    fi
  fi
fi

# All checks passed
exit 0

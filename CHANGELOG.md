<!--
SPDX-FileCopyrightText: 2026 SecPal
SPDX-License-Identifier: CC0-1.0
-->

# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- converted all findings from the 2026-03-31 security audit to GitHub Issues (#834–#847) tracked under Epic #848

### Removed

- removed `SECURITY_AUDIT_API_VALIDATION.md` from the repository root after converting its findings to tracked GitHub Issues
- removed stale and historical documentation: one-time PR artefacts (`PR_DESCRIPTION_DRAFT.md`), DDEV-era retrospectives and production-test reports (`ISSUE50_RETROSPECTIVE.md`, `ISSUE74_RETROSPECTIVE.md`, `PRODUCTION_TEST_PASSWORD_RESET.md`, `PRODUCTION_TEST_PHASE2_EMAIL.md`, `PR_REVIEW_ISSUE50.md`), superseded workflow guides (`EPIC_WORKFLOW.md`, `EPIC_IMPLEMENTATION_SUMMARY.md`, `SELF_REVIEW_CHECKLIST.md`), and the PHPStan workaround note (`ISSUE_PHPSTAN_SANCTUM_TYPES.md`) and obsolete reminder prompt file (`COPILOT_REMINDER_PATTERNS.md`)

### Fixed

- switched role and permission management writes to validated request payloads so those controllers no longer read raw input after form-request validation
- reduced the public health surface by removing the `/health` version field and by limiting `/health/ready` responses to the readiness status plus timestamp instead of exposing database, key-management, scheduler, and queue-worker details
- standardized API V1 delete endpoints on `response()->noContent()` so successful `204` responses are implemented consistently across employee, document, qualification, assignment, customer, site, organizational-unit, and cost-center deletes
- serialized employee number generation per tenant inside the employee create transaction, locking the tenant row plus the current-year employee number lookup so concurrent `POST /v1/employees` requests cannot derive duplicate `employee_number` values; verified the existing database uniqueness on `employee_number` remains in place as defense in depth

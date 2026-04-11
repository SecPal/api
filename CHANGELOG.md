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

- restricted regulated employee identifiers in `EmployeeResource` behind a new `employees.read_sensitive` permission, seeded a dedicated `HR` role for that access, and stopped non-HR viewers with ordinary employee read access from receiving decrypted tax, social-security, permit, health-insurance, ID-document, and Sachkunde identifier fields

- verified employee email uniqueness enforcement with regression coverage against the unique plaintext `employees.email` column used by `StoreEmployeeRequest`

- added a dedicated `health` rate limiter for `/health`, `/health/live`, and `/health/ready` so unauthenticated health probes now return `429` after repeated abuse from the same IP and route bucket

- switched role and permission management writes to validated request payloads so those controllers no longer read raw input after form-request validation
- reduced the public health surface by removing the `/health` version field and by limiting `/health/ready` responses to the readiness status plus timestamp instead of exposing database, key-management, scheduler, and queue-worker details
- standardized API V1 delete endpoints on `response()->noContent()` so successful `204` responses are implemented consistently across employee, document, qualification, assignment, customer, site, organizational-unit, and cost-center deletes
- switched onboarding submission rejection to persist the validated `reason` payload instead of re-reading raw request input after inline validation
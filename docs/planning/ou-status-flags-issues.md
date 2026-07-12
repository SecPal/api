<!--
SPDX-FileCopyrightText: 2026 SecPal Contributors
SPDX-License-Identifier: CC0-1.0
-->

# Organizational Unit Status Flags Issue Split

US-001 prepared the repository-crossing organizational-unit status flag work as one epic with three implementation sub-issues.

## Epic

- SecPal/api#1260: Add independent organizational-unit status flags.

The epic defines two independent booleans:

- `is_legal_entity`: whether the organizational unit is a legal entity.
- `is_establishment`: whether the organizational unit is an establishment.

Neither flag is derived from the other, from hierarchy, from soft deletion, or from the organizational-unit type.

## Sub-Issues

- SecPal/contracts#338: define the public request and response contract.
- SecPal/api#1259: persist, validate, expose, and test the flags in the API.
- SecPal/frontend#1361: consume, display, edit, cache, and test the flags in the frontend.

## Branch And PR Boundaries

- Contracts branch: `add-ou-status-flags-contracts`, from current `main`.
- API branch: `add-ou-status-flags-api`, from current `main`.
- Frontend branch: `add-ou-status-flags-frontend`, from current `main`.

Each repository owns one topic branch and one PR. Contract, API, and frontend changes must stay in their respective repositories and must not be mixed into a cross-repository PR.

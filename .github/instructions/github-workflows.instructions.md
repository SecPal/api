---
# SPDX-FileCopyrightText: 2026 SecPal Contributors
# SPDX-License-Identifier: CC0-1.0
name: GitHub Workflow Rules
description: Applies workflow and Dependabot rules to GitHub automation files in this repo.
applyTo: ".github/workflows/**/*.yml,.github/workflows/**/*.yaml,.github/dependabot.yml,.github/dependabot.yaml"
---

# GitHub Actions And Workflow Rules

Applies when editing GitHub Actions workflows and Dependabot configuration in this repository.

- Always set `timeout-minutes` on every job that runs steps. Jobs that directly call a reusable workflow with `uses` are the required exception: GitHub Actions does not permit `timeout-minutes` on them, so omit it rather than adding invalid syntax.
- Set explicit `permissions` on every workflow and start with the least privilege needed.
- Pin third-party actions to immutable versions. GitHub-maintained `actions/*` may use supported major tags in this org.
- Use reusable workflows from the organization templates when they fit the task.
- Use `continue-on-error: true` only for intentional polling or wait steps, never for build or test steps.
- Reference secrets via `${{ secrets.NAME }}` and vars via `${{ vars.NAME }}`. Never hardcode or echo secrets.
- Run `yamllint` on workflow changes before finalizing.

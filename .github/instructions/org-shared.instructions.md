---
# SPDX-FileCopyrightText: 2026 SecPal Contributors
# SPDX-License-Identifier: CC0-1.0
name: API Runtime Overlay
description: Preserves API-specific runtime constraints and native pull-request checks.
applyTo: "**"
---

# API Runtime Overlay

This file auto-applies to all files in this repository.

- `AGENTS.md` is the authoritative runtime baseline for this repo.
  `.github/copilot-instructions.md` is only a compatibility mirror.
- Preserve API-specific Laravel, tenant-isolation, authorization, persistence,
  encrypted-data, lifecycle, and framework-wiring constraints from `AGENTS.md`.
- Never bypass hooks or force-push.
- GitHub communication stays in English and uses file and line references instead of large verbatim code quotes.
- Do not add AI self-references, generated-by text, tool promotion, or AI
  attribution unless the task explicitly requires documenting AI tooling.
- Keep changes repo-local, minimal, and consistent with Laravel, Pest, and native PHP runtime conventions.
- Apply the SecPal domain policy from the repository baseline.

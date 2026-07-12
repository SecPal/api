---
# SPDX-FileCopyrightText: 2026 SecPal Contributors
# SPDX-License-Identifier: CC0-1.0
name: API Runtime Overlay
description: Reinforces strict SecPal governance for all files in this repo.
applyTo: "**"
---

# API Runtime Overlay

This file auto-applies to all files in this repo so strict SecPal governance stays always present at runtime.

- `AGENTS.md` is the authoritative runtime baseline for this repo.
  `.github/copilot-instructions.md` is only a compatibility mirror.
- Non-negotiable: TDD first, quality first, 1 topic = 1 PR = 1 branch,
  immediate GitHub issue creation for every real out-of-scope finding, and no
  bypass.
- If work needs more than one PR, or probably will, create an EPIC with linked
  sub-issues before implementation.
- Design discipline is always-on: DRY, KISS, YAGNI, SOLID, and fail fast.
- GitHub communication stays in English and uses file and line references instead of large verbatim code quotes.
- Do not add AI self-references, generated-by text, tool promotion, or AI
  attribution unless the task explicitly requires documenting AI tooling.
- Keep changes repo-local, minimal, and consistent with Laravel, Pest, and native PHP runtime conventions.
- Apply the SecPal domain policy and immediate warning and issue triage rules from the repo baseline.

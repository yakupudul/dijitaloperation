# Legacy DOP Autopilot (v1) — archived

This directory holds the retired custom GitHub Actions orchestration state machine.

## What lived here

* OpenAI Architect task selection (`architect.py`)
* Self-healing recovery taxonomy + `dop-recover-task` supervisor
* Cursor Implementer / Fixer orchestration prompts
* Full `dop-autopilot.yml` workflow with `repository_dispatch` loops

## Why archived

Autopilot **v2** moves planning, implementation, and repair to **Cursor Automations**.

GitHub Actions (`.github/workflows/dop-pr-gate.yml`) only runs deterministic gates:

* PHPUnit / Pint / secret scan / infra path protection
* OpenAI Reviewer + SHA-locked evidence
* verified squash merge
* `docs/PROJECT_STATUS.md` update

There is **no** `dop-next-task` / `dop-recover-task` chain in the active path.

## Rollback

The archived workflow copy is at `workflows/dop-autopilot.yml`.
Do **not** restore `repository_dispatch` triggers unless intentionally rolling back v2.

The active stub `.github/workflows/dop-autopilot.yml` is `workflow_dispatch`-only and exits without running product work.

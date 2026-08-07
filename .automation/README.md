# DOP Development Autopilot v2

Product planning / implementation / repair is owned by **Cursor Automations**.

Create / enable Cursor Automations: see [`.automation/supervisor/README.md`](supervisor/README.md)

* Supervisor prompt: [`supervisor/DOP_SUPERVISOR.md`](supervisor/DOP_SUPERVISOR.md)
* PR Repair prompt: [`supervisor/DOP_PR_REPAIR.md`](supervisor/DOP_PR_REPAIR.md)

GitHub Actions only provides deterministic gates.

## Active workflow

**DOP PR Gate** (`.github/workflows/dop-pr-gate.yml`)

1. Checkout PR head  
2. Bootstrap test env  
3. Composer validate + PHPUnit + Pint  
4. Secret / credential scan  
5. Product branch infra path protection (automation PRs only)  
6. OpenAI Reviewer (structured verdict + SHA-locked evidence)  
7. Squash merge when all gates pass  
8. Update `docs/PROJECT_STATUS.md`  

**Does not** select tasks, run Architect, implement recovery loops, or emit legacy continuation/recovery repository events.

## PR contract (Cursor Automations)

* Branch: `dop/<task-id>`
* PR body includes `<!-- DOP_AUTOMATION_PR -->`
* Structured metadata: Task ID, roadmap stage, title, product spec paths, ADRs, acceptance criteria, tests

## Reviewer

Verdicts: `APPROVED` | `FIX_REQUIRED` | `HUMAN_REQUIRED`

* `APPROVED` + matching HEAD SHA + gates → merge  
* `FIX_REQUIRED` / `HUMAN_REQUIRED` → **failed CI check** with readable issues (Cursor Automation repairs)  

## Legacy Autopilot (retired)

* Stub: `.github/workflows/dop-autopilot.yml` — `workflow_dispatch` only; refuses to run  
* Archive: `.automation/legacy/` (Architect, recovery, old full workflow)

## Project status

Canonical human-readable progress: [`docs/PROJECT_STATUS.md`](../docs/PROJECT_STATUS.md)

Terminology: `RUNNING` | `RECOVERING` | `HARD_BLOCKED` | `ROADMAP_COMPLETE`

Workflow run numbers are never treated as roadmap progress.

## Secrets / vars

* `OPENAI_API_KEY` (required for Reviewer)
* Optional: `OPENAI_REVIEWER_MODEL`, `OPENAI_REASONING_EFFORT`

## Local tests

```bash
python -m unittest discover -s .automation/tests -v
```

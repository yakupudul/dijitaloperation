# DOP Cursor Automations (Autopilot v2)

These are **not** Cursor Hooks.

Cloud agents in this repository **cannot** create Automations via public API/CLI.
Create them in the Cursor UI (or via `/automate` in a local Agents Window session):
[https://cursor.com/automations/new](https://cursor.com/automations/new)

GitHub must be connected: [Integrations](https://cursor.com/dashboard/integrations).

---

## 1. DOP Supervisor

**Prompt:** [`DOP_SUPERVISOR.md`](./DOP_SUPERVISOR.md)

| Setting | Value |
| --- | --- |
| Name | `DOP Supervisor` |
| Repository | `yakupudul/dijitaloperation` |
| Triggers | **Pull request merged** + **Scheduled every hour** (`0 * * * *`) |
| Role | Choose/implement next serial roadmap task; open/repair product PRs; never merge |
| Tools | Open pull request ON; Memories optional; do not merge/approve |

Paste the full contents of `DOP_SUPERVISOR.md` into Instructions.

---

## 2. DOP PR Repair

**Prompt:** [`DOP_PR_REPAIR.md`](./DOP_PR_REPAIR.md)

| Setting | Value |
| --- | --- |
| Name | `DOP PR Repair` |
| Repository | `yakupudul/dijitaloperation` |
| Trigger | GitHub **Workflow Run Completed** when **conclusion is FAILURE** |
| Role | Repair failed DOP product PRs in place; never merge / never next-task |
| Tools | Comment on PR optional; Memories optional; **do not** enable approve/merge |

### UI setup steps

1. Open [https://cursor.com/automations/new](https://cursor.com/automations/new)
2. **Name:** `DOP PR Repair`
3. **Repository:** `yakupudul/dijitaloperation`
4. **Trigger:** Source control → **Workflow run completed**
   * Filter / only run when conclusion is **FAILURE** (if the UI exposes a conclusion filter)
   * Prefer scoping to workflow **DOP PR Gate** when the UI allows a workflow name filter
5. **Instructions:** paste the full contents of [`DOP_PR_REPAIR.md`](./DOP_PR_REPAIR.md)
6. Tools:
   * Open pull request — usually unnecessary (repair existing branch); leave default unless needed
   * Comment on pull request — optional
   * Approvals / merge — **OFF**
   * Memories — optional
7. Save and **enable**

### Scope reminder

Only product PRs with `<!-- DOP_AUTOMATION_PR -->` or `dop/` branch prefix (plus legacy `#44` until merged/superseded).

---

## Related Autopilot v2 pieces

| Piece | Role |
| --- | --- |
| **DOP Supervisor** | Roadmap continuation after merge + hourly watchdog |
| **DOP PR Repair** | Fix failed DOP PR Gate / Reviewer CI signals |
| `.github/workflows/dop-pr-gate.yml` | PHPUnit, Pint, secrets, infra, Reviewer, verified squash merge, status |
| `docs/PROJECT_STATUS.md` | Human-readable progress |
| Legacy `.github/workflows/dop-autopilot.yml` | Retired stub — must stay inactive |

## Verify after creation

* Both automations appear enabled at [https://cursor.com/automations](https://cursor.com/automations)
* A failed **DOP PR Gate** run wakes **DOP PR Repair**
* Repair pushes to the same branch and does **not** merge
* No legacy `dop-next-task` / `dop-recover-task` dispatches

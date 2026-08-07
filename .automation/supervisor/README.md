# DOP Supervisor (Cursor Automation)

This is **not** a Cursor Hook.

Hooks cannot listen to “PR merged into `main`” or run hourly. **DOP Supervisor** must be created as a [Cursor Automation](https://cursor.com/docs/cloud-agent/automations.md).

Cloud agents in this repository **cannot** create Automations via public API/CLI. Create it in the Cursor UI (or via `/automate` in a local Agents Window session).

## Create in UI

1. Open [https://cursor.com/automations/new](https://cursor.com/automations/new)
2. **Name:** `DOP Supervisor`
3. **Repository:** `yakupudul/dijitaloperation` (required; also attach repo for the schedule trigger)
4. **Triggers** (both):
   * Source control → **Pull request merged** (repo above). In the prompt, only continue when merge target is `main`.
   * Scheduled → **Every hour** (or cron `0 * * * *`)
5. **Instructions:** paste the full contents of [`DOP_SUPERVISOR.md`](./DOP_SUPERVISOR.md)
6. **Tools (recommended):**
   * Open pull request — **ON**
   * Memories — **ON** (optional but useful)
   * Comment on pull request — optional
   * Do **not** enable merge-to-main behavior; product merges stay with GitHub DOP PR Gate + OpenAI Reviewer
7. **Model:** team default / strongest available coding model is fine
8. Save and **enable**

GitHub must be connected: [Integrations](https://cursor.com/dashboard/integrations).

## Related Autopilot v2 pieces

| Piece | Role |
| --- | --- |
| Cursor Automation **DOP Supervisor** | Choose/implement next serial roadmap task; open/repair product PRs |
| `.github/workflows/dop-pr-gate.yml` | PHPUnit, Pint, secrets, infra gate, OpenAI Reviewer, verified squash merge, status |
| `docs/PROJECT_STATUS.md` | Human-readable progress (not product requirements) |
| Legacy `.github/workflows/dop-autopilot.yml` | Retired stub — must stay inactive |

## Current handoff

Until `website-diagnosis-ssl-check` is merged:

* Inspect legacy PR `#44` first
* Adopt/repair if safe, else reimplement on `dop/website-diagnosis-ssl-check`
* Do not advance past stage 11 SSL

## Verify after creation

* Automation appears at [https://cursor.com/automations](https://cursor.com/automations) as **DOP Supervisor** (enabled)
* A test hourly run or a merge-to-main run shows up under cloud agents with source `automations`
* It does **not** dispatch legacy `dop-next-task` / `dop-recover-task`

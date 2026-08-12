# Meta Operator Rescue — STEP 0 Repository Truth

| Fact | Value |
|------|-------|
| main SHA | `f6818f068f5a19210ef2a480da9a5ecdcfe0c58b` |
| Open Meta PR | https://github.com/yakupudul/dijitaloperation/pull/122 |
| Branch | `cursor/meta-ads-expert-workspace-ea01` |
| Head (pre-rescue) | `5f2b2ddcdc12240f6abd804bea0928aeec79b3a0` |
| Async on main | **YES** (#121) |
| Ambiguity | **NONE** — single Meta PR contains historical import, tables, Refresh, Overview/Campaigns |
| Decision | **CONTINUE PR #122** as rescue rewrite (do not create competing PR) |

## Contained on this branch

- Meta historical tables + query/import services
- Async Meta history import jobs
- Refresh data / gap enrich
- Current Filament Meta Overview/Campaigns (to be replaced by TailAdmin operator UI)

## TailAdmin

- Official repo: https://github.com/TailAdmin/tailadmin-laravel
- License: MIT (Copyright 2025 TailAdmin)
- Inspected: layouts (app, sidebar, header), components/ui (badge, button, alert, modal), ecommerce metrics/charts, tables, Alpine theme/sidebar stores, ApexCharts dependency

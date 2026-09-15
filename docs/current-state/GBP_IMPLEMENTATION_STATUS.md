# GBP implementation status

This file intentionally stays small. Canonical dataset design lives in `docs/current-state/GBP_DATASET_PLAN.md`.

Implementation principles:
- reuse the existing Data Pool and collection engine;
- distinguish durable numeric performance facts from short-lived provider content;
- keep external local-rank/competitor providers outside the GBP provider namespace;
- preserve `external_resource_id` and existing identity/provenance requirements on every typed fact;
- do not fabricate unsupported metrics (for example deprecated media/post insight KPIs).


## 2026-09-14 — GBP discovered-resource automation

Owner reports Google GBP access approved and 65 discovered locations. Supplied connector HTML
shows available locations with not_collected and only Manage binding. Discovery is not collection
proof. Source confirms ResourceAutomationService required GBP binding, then routed it through
CollectionLifecycle rather than the existing GBP Run/typed-table collector.

GBP now uses the existing resource automation scheduler and durable ResourceCollectionJob, with
one of ten GBP datasets per worker turn. Run metadata checkpoints completed dataset outcomes;
resource_automations.gbp_run_id references the existing GBP Run, separately from CollectionRun IDs.
This intentionally retains GBP's existing Run collector; it does NOT claim a completed migration
to the shared Collection Engine/warehouse control plane. No new scheduler, credentials or provider
store. Nullable asset ownership in existing GBP tables and runs allows unbound collection with
non-null external_resource_id provenance. Existing exact active binding is validated and retained;
no automatic customer/brand/asset creation. Old binding-only attention states are re-admitted.
Explicitly paused automations remain paused. New locations retain existing default daily cadence.

The dedicated root GBP connector embeds existing paginated TR/EN automation controls: pause, daily/
three-day interval, run now, error status, last full success, next due time, processed dataset count
and recent GBP Run outcomes/row counts/errors. Discovery count is labelled locations. No claim that
all APIs are authorized just because location discovery succeeds. Initial windows reuse 180 days
of performance and 12 complete keyword months (configurable); each refresh currently rereads these
windows. Missing/partial endpoints do not become current. 429/5xx and local pacing failures retry the affected dataset up to three attempts with increasing delay and jitter. Other partial cycles retry at normal cadence,
manual retry is available. Per-step wall-time budget and request pacing bound work; pagination caps
are partial, not complete. Within-dataset page continuation and delta-only refresh remain future
improvements. Interrupted work reuses saved completed dataset outcomes and idempotent typed writes.
No provider writes, AI analysis, competitor/rank-grid calls, or new paid providers.

Verification: source/diff review only. Per owner no tests, build or live provider calls. PHP and
vendor/Pint are unavailable. Not deployed or runtime proven; actual persisted rows, API-specific
403/429 errors, queue/scheduler operation and browser rendering require operator deployment/UAT.
Official quota guidance: https://developers.google.com/my-business/content/limits

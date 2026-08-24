# GBP implementation status

This file intentionally stays small. Canonical dataset design lives in `docs/current-state/GBP_DATASET_PLAN.md`.

Implementation principles:
- reuse the existing Data Pool and collection engine;
- distinguish durable numeric performance facts from short-lived provider content;
- keep external local-rank/competitor providers outside the GBP provider namespace;
- preserve `external_resource_id` and existing identity/provenance requirements on every typed fact;
- do not fabricate unsupported metrics (for example deprecated media/post insight KPIs).

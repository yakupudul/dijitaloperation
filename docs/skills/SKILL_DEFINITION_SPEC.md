# Skill Definition Spec (Prompt 49)

> **Status:** CANONICAL human-readable contract for MoxDOP Skills  
> **Authority:** `docs/implementation/MOXDOP_SKILL_NORMALIZATION.md` · `docs/product/AGENT_SKILL_ARCHITECTURE.md` · Prompt 48 research  
> **Storage:** Module Markdown `resources/skills/<slug>/SKILL.md` + YAML front matter — **no** `skills` DB table, **no** SkillV2  
> **Execution:** Prompt 50 (this spec does not define runtime LLM behaviour)

Every production Skill Definition MUST express the analytical contract below. Machine validation is enforced by `App\Support\Skills\SkillDefinitionValidator`. Global claim inheritance is enforced by `SkillGlobalClaimPolicy`.

---

## 1. Identity and versioning

| Field | Location | Required | Rules |
| --- | --- | --- | --- |
| `name` | YAML | YES | Human display title; not stable identity |
| `slug` | YAML | YES | kebab-case; unique within module; no third-party product branding |
| `module` | YAML | YES | Owning module id (`website`, `google-ads`, `meta-ads`, `google-business-profile`, …) |
| `version` | YAML | YES | Semver string (e.g. `1.1.0`); bump on material contract change |
| Stable key | derived | YES | `module.slug` |
| Signature | derived | YES | `module.slug@version` |
| `definition_status` | YAML | YES | `active` \| `draft` \| `experimental` \| `needs_review` \| `deprecated` |

---

## 2. Purpose

| Field | Location | Required | Rules |
| --- | --- | --- | --- |
| `purpose` | YAML | YES | One-sentence (or short) capability statement: what analytical job the Skill performs |

**Must:**

- Describe interpretation / assessment / framing of Evidence
- Stay inside the Skill’s domain boundary

**Must not:**

- Promise business outcomes (rankings, leads, revenue, conversions as goals)
- Introduce composite SEO/GEO/health/AI-visibility scores
- Claim provider writes or autonomous domain object creation

---

## 3. Required Evidence

| Field | Location | Required | Rules |
| --- | --- | --- | --- |
| `required_evidence` | YAML list | YES for most `active` Skills | Structured `SkillEvidenceRequirement` entries |

Each entry:

| Subfield | Meaning |
| --- | --- |
| `key` | Evidence Definition ID or observational Evidence.type / alias |
| `kind` | `evidence_definition` \| `evidence_type` |
| `role` | `PRIMARY_FACT` \| `COMPARISON_BASELINE` \| `SCOPE_CONTEXT` \| `MEASUREMENT_CONTEXT` \| `MARKET_CONTEXT` \| `OPTIONAL_ENRICHMENT` |
| `purpose` | Why this Evidence is needed |
| `missing_behavior` | **`ABSTAIN`** for required entries |
| `integrity_required` | Prefer `true` for primary facts |
| `completeness_required` | Optional boolean |
| `freshness_policy` | Optional policy label |
| `expands_conclusions` | Should be false/omitted on required |

**Semantics:** If any required Evidence is missing, stale, integrity-blocked, insufficient, or provider-limited → Skill **abstains**. Missing ≠ zero.

Keys must be known to `SkillEvidenceCatalog` (or satisfy alias/prefix rules). The same `key` must not appear in both required and optional lists.

---

## 4. Optional Evidence

| Field | Location | Required | Rules |
| --- | --- | --- | --- |
| `optional_evidence` | YAML list | NO | Enrichment only |

Each entry uses `missing_behavior: **CONTINUE**`. Absence must not be narrated as a pass/healthy state. `expands_conclusions: true` allowed when presence legitimately widens Allowed Conclusions.

---

## 5. When to Use

| Field | Location | Required | Rules |
| --- | --- | --- | --- |
| When to use | Markdown body `## When to use` (also exposed as `when_to_use`) | YES | Positive triggers: asset type, Evidence availability, operator question shape |

Use sibling Skill names to route overlapping questions (e.g. technical vs indexability vs metadata).

---

## 6. Do Not Use When

| Field | Location | Required | Rules |
| --- | --- | --- | --- |
| Do not use when | Markdown body `## Do not use when` | YES | Negative scope: missing Evidence, wrong domain, write requests, out-of-contract questions |

This section is load-bearing for abstention and Agent Skill selection.

---

## 7. Methodology

| Field | Location | Required | Rules |
| --- | --- | --- | --- |
| `methodology` | Body / parsed prose | YES | Ordered analytical workflow in natural language |
| `methodology_steps` | YAML list | OPTIONAL | Structured steps for validators / future runners |

Allowed step `type` values: `ABSTAIN_GATE`, `CHECK`, `COMPARE`, `CLASSIFY`, `SYNTHESIZE`, `SUMMARIZE`, `PRIORITIZE_WITHOUT_SCORE`, `VALIDATE`.

**Forbidden in methodology:** PHP/shell/eval payloads; composite score emission; instructions to mutate external platforms.

---

## 8. Allowed Conclusions

| Field | Location | Required | Rules |
| --- | --- | --- | --- |
| `allowed_conclusions` | YAML list | YES (non-empty) | Explicit conclusion classes the Skill may emit |

Anything not listed is out of contract. Prefer observation-first language; label candidates as candidates; label heuristics as heuristics.

---

## 9. Forbidden Claims

| Field | Location | Required | Rules |
| --- | --- | --- | --- |
| `forbidden_claims` | YAML list | YES | Skill-specific bans |
| Effective forbidden claims | derived | YES | `SkillGlobalClaimPolicy` ∪ skill-specific (skills may only **add**) |

Global policy always includes: no fabrication; missing≠zero; vendor≠first-party; no outcome guarantees; no ranking-internals-as-fact; no provider metric conflation; no invented Brand/Goal/Scope; no magic scores; no autonomous Finding/Task/Recommendation/provider writes; no unsupported causal SEO/GEO claims.

Domain Skills add provider-semantic bans (GSC position≠rank, GA4 key event≠business outcome, Ads conversion≠qualified lead, Meta action_type≠generic Result, etc.).

---

## 10. Success Signals

| Field | Location | Required | Rules |
| --- | --- | --- | --- |
| `success_signals` | YAML list | YES (non-empty) | Observable signs a run satisfied the contract |
| `failure_signals` | YAML list | OPTIONAL | Signs the run went wrong |
| `watch_metrics` | YAML list | OPTIONAL | Evidence-backed metrics to watch — not Skill scores |

Success = honest, Evidence-cited, operator-actionable output — not a numeric grade.

---

## 11. References

| Field | Location | Required | Rules |
| --- | --- | --- | --- |
| `reference_sources` | YAML list | YES (non-empty) | Primary documentation / API field references |

Prefer official provider docs and MoxDOP product specs. Include `verified_at` dates when practical. External skill repositories are research provenance, not primary References.

---

## 12. Provenance

| Field | Location | Required | Rules |
| --- | --- | --- | --- |
| `research_provenance` | YAML list | YES for Prompt 48 READY Skills; strongly preferred for all `active` | Traceability to Prompt 48 candidate IDs and/or `existing-canonical-pre-prompt-48` |

Provenance records methodology lineage. It does **not** authorize copying third-party prompt text.

---

## 13. Status

See `definition_status` in §1. Only `active` Skills are production-ready. `experimental` must remain abstention-first if ever shipped. Prompt 49 ships all 21 Skills as `active`; deferred Prompt 48 candidates remain unshipped rather than `experimental` stubs.

---

## 14. Fingerprint

| Field | Location | Required | Rules |
| --- | --- | --- | --- |
| Definition fingerprint | derived via `SkillDefinitionFingerprint` | YES | sha256 over canonicalized material fields |

Material fields include identity/version/status, purpose, when/do-not-use, evidence requirements, methodology (+ steps), allowed/forbidden claims, abstention rules, success signals, references, research provenance, downstream domains, output contract.

Fingerprint is for provenance and change detection — not a quality score.

---

## 15. Abstention

| Field | Location | Required | Rules |
| --- | --- | --- | --- |
| `abstention_rules` | YAML list | YES for `active` | Human-readable rules, typically keyed by reason codes |

Reason codes (evaluator): `missing_required_evidence`, `missing_required_context`, `required_evidence_stale`, `integrity_blocked`, `coverage_insufficient`, `provider_limited`, `methodology_not_applicable`, `unsupported_question`.

Prompt 49 defines the contract; Prompt 50 enforces abstention on live runs.

---

## 16. Downstream domains

| Field | Location | Required | Rules |
| --- | --- | --- | --- |
| `downstream_domains` | YAML list | OPTIONAL | Labels such as `ANALYSIS_ONLY`, `FINDING_CANDIDATE`, `RECOMMENDATION_CANDIDATE` |

Labels never create Findings, Recommendations, Tasks, Opportunities, Activity, or Notifications by themselves.

---

## 17. Supporting fields

| Field | Required | Notes |
| --- | --- | --- |
| `required_context` | OPTIONAL | Context flags (e.g. brand context present) |
| `required_capabilities` / `optional_capabilities` | OPTIONAL | Metadata only in V1 — never trigger external access |
| `dependencies` | OPTIONAL | Other Skill stable keys / analytical deps |
| `rules` | OPTIONAL | Extra hard rules prose |
| `output_contract` | YES for `active` | Non-scored output shape |

---

## 18. File shape

```text
app-modules/<module>/resources/skills/<slug>/SKILL.md
```

```markdown
---
name: ...
slug: ...
version: ...
module: ...
purpose: ...
definition_status: active
required_evidence:
  - key: ...
    kind: evidence_type
    role: PRIMARY_FACT
    purpose: ...
    missing_behavior: ABSTAIN
optional_evidence: []
allowed_conclusions: [...]
forbidden_claims: [...]
abstention_rules: [...]
success_signals: [...]
reference_sources: [...]
research_provenance: [...]
downstream_domains: [...]
methodology_steps: [...]
---

## When to use
...

## Do not use when
...

## Methodology
...

## Output contract
...
```

Loader limits: UTF-8, max 64 KiB, no path traversal, no executable payloads.

---

## 19. Non-goals of this spec

- LLM prompts as executable programs
- Skill DB schema
- Eval harness / scorer
- Capability Router
- Provider write tools
- Inventing Skills not justified by Prompt 48 dispositions or pre-existing canonical module Skills

See also: [`NORMALIZED_SKILL_CATALOG.md`](./NORMALIZED_SKILL_CATALOG.md).

---
name: Local Presence Audit
slug: local-presence-audit
version: 1.1.0
module: google-business-profile
definition_status: active
purpose: Review GBP profile consistency against Brand Context and Website Evidence without inventing a composite local visibility metric.
required_evidence:
  - key: gbp_location_profile
    kind: evidence_type
    role: PRIMARY_FACT
    purpose: Observed GBP identity, contact, hours, and website fields for consistency review.
    missing_behavior: ABSTAIN
    integrity_required: true
    completeness_required: false
    expands_conclusions: false
optional_evidence: []
required_capabilities:
  - google-business-profile.read
optional_capabilities: []
downstream_domains:
  - ANALYSIS_ONLY
  - FINDING_CANDIDATE
abstention_rules:
  - Abstain when gbp_location_profile Evidence is missing — do not invent NAP mismatches.
  - Abstain from Maps ranking causation or ranking guarantees from incomplete samples.
  - Abstain from automatic GBP profile writes or review replies.
research_provenance:
  - existing-canonical-pre-prompt-48
reference_sources:
  - Official Google Business Profile APIs (read)
---

## When to use

Use when GBP location profile Evidence is available for the target Digital Asset.

## Do not use when

- Profile Evidence is missing — do not invent NAP mismatches.
- You would claim Maps ranking causation from incomplete samples.
- You would write or publish to GBP from MoxDOP.

## Methodology

1. Compare observed GBP identity/contact/hours/website to Brand Context and Website Evidence.
2. Label Match / Partial / Conflict / Needs review / Unavailable explicitly.
3. Treat local visibility samples as observational — never a fake 5×5 rank matrix.
4. Prefer Brand Public Discovery for Brand Context updates; do not silently overwrite.

## Allowed conclusions

- Consistency conflicts grounded in Evidence.
- Attention candidates with clear required human input.

## Forbidden claims

- Local SEO score, market share, revenue, or causal ranking explanations.
- Ranking guarantees or promised Maps positions.
- Automatic GBP profile writes, publishes, or review replies.
- Fabricated NAP or hours not present in official GBP Evidence.
- Task creation or Recommendation auto-approval.

## Abstention

- Abstain when profile Evidence is missing or insufficient for the compared field.
- Prefer Unavailable / Needs review over invented mismatches or ranking stories.

## Output contract

Observation, why it matters, recommended action, Evidence IDs, caveats, success/failure signals, watch metrics, priority, confidence.

## Success signals

- Consistency labels cite official GBP profile Evidence and optional Brand/Website context only.
- Guidance never implies ranking guarantees or provider writes.

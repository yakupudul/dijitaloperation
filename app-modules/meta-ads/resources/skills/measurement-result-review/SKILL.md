---
name: Measurement Result Review
slug: measurement-result-review
version: 1.1.0
module: meta-ads
definition_status: active
purpose: Interpret available Meta actions/results and measurement context without pretending MoxDOP has pixel/CAPI event validation or CRM access.
required_evidence:
  - key: meta_ads_campaign_performance
    kind: evidence_type
    role: MEASUREMENT_CONTEXT
    purpose: Campaign insights actions/results and comparable outcome fields for measurement review (not pixel/CAPI validation).
    missing_behavior: ABSTAIN
    integrity_required: true
    completeness_required: false
    expands_conclusions: false
optional_evidence: []
required_capabilities:
  - meta-ads.read
optional_capabilities: []
downstream_domains:
  - ANALYSIS_ONLY
  - FINDING_CANDIDATE
abstention_rules:
  - Abstain when meta_ads_campaign_performance Evidence is missing — do not invent measurement problems.
  - Abstain from claiming pixel/CAPI firing, CRM accuracy, or offline conversion quality without Evidence.
  - Abstain from mutating pixels, events, or conversion definitions.
research_provenance:
  - existing-canonical-pre-prompt-48
reference_sources:
  - Agency Agents Tracking & Measurement Specialist (methodology reference only — no runtime)
  - Official Meta Marketing API insights action/result breakdown fields
---

## When to use

Use when `meta_ads_campaign_performance` Evidence includes actions/results or comparable outcome fields.

## Do not use when

- Campaign performance Evidence is missing — do not invent measurement problems.
- You would claim pixel/CAPI firing, CRM accuracy, or offline conversion quality without Evidence.

## Required context

- brand_context (optional)
- meta_ads_account_summary (optional enrichment when actions are account-level)

## Methodology

1. Distinguish REPORTED platform actions/results from VERIFIED business outcomes.
2. Review action types, result counts, and attribution windows only as collected in Evidence.
3. Respect that account-level actions may appear in summary Evidence while campaign Evidence drives this Skill's eligibility.
4. Prefer bounded language: measurement configuration risk, result data unavailable, evidence insufficient to verify tracking.
5. Never collect or request access tokens, pixel secrets, or lead form personal data.
6. Never collapse action types into a generic Result; preserve attribution awareness.

## Rules

- Do not claim "tracking is broken" solely because MoxDOP cannot observe browser/server events.
- Do not mutate pixels, events, or conversion definitions.
- Platform actions/results ≠ CRM qualified leads, sales, or profit.
- No Task creation.

## Allowed conclusions

- Measurement/result interpretation gaps grounded in Evidence.
- Explicit uncertainty about event validation and business outcome linkage.

## Forbidden claims

- Pixel/CAPI correctness, CRM match rates, or lead quality without Evidence.
- Automatic event setup or conversion definition changes.
- Qualified-lead or profit claims from Meta results alone.
- Generic Result without action_type mapping.
- Attribution certainty beyond Evidence-supplied windows.
- Magic measurement scores.
- Provider writes or Task creation.

## Abstention

- Abstain when campaign performance Evidence is missing.
- Prefer “evidence insufficient to verify tracking” over invented pixel/CAPI certainty.

## Output contract

Observation, why it matters, recommended action, Evidence IDs, caveats, success/failure signals, watch metrics, priority, confidence.

## Success signals

- Action/result language preserves action_type and attribution windows from Evidence.
- Uncertainty about pixel/CAPI/CRM validation is explicit when unobserved.

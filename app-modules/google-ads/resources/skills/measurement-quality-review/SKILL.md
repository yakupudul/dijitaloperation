---
name: Measurement Quality Review
slug: measurement-quality-review
version: 1.1.0
module: google-ads
definition_status: active
purpose: Interpret available conversion/measurement configuration Evidence without pretending MoxDOP has browser/GTM access.
required_evidence:
  - key: google_ads_conversion_actions
    kind: evidence_type
    role: MEASUREMENT_CONTEXT
    purpose: Conversion action configuration fields (enabled, primary_for_goal, include_in_conversions_metric) — not browser validation.
    missing_behavior: ABSTAIN
    integrity_required: true
    completeness_required: false
    expands_conclusions: false
optional_evidence: []
required_capabilities:
  - google-ads.read
optional_capabilities: []
downstream_domains:
  - ANALYSIS_ONLY
  - FINDING_CANDIDATE
abstention_rules:
  - Abstain when google_ads_conversion_actions Evidence is missing — do not invent tracking problems.
  - Abstain from claiming browser tags fire correctly from API configuration alone.
  - Abstain from mutating conversion actions or collecting tag snippets/secrets.
research_provenance:
  - existing-canonical-pre-prompt-48
reference_sources:
  - Agency Agents Tracking & Measurement Specialist (methodology reference only — no runtime)
  - Official Google Ads API conversion_action resource
---

## When to use

Use when `google_ads_conversion_actions` Evidence is available.

## Do not use when

- Measurement Evidence is missing — do not invent tracking problems.
- You would claim browser tags fire correctly from API configuration alone.

## Required context

- brand_context (optional)

## Methodology

1. Distinguish CONFIGURATION Evidence from REAL EVENT VALIDATION.
2. Review enabled / primary_for_goal / include_in_conversions_metric fields only as collected.
3. Respect that custom campaign goals may use non-primary actions.
4. Prefer bounded language: measurement configuration risk, conversion data unavailable, evidence insufficient to verify tracking.
5. Never collect or request tag snippets/secrets.
6. Platform conversions ≠ qualified leads without an explicit business-outcome mapping; keep currency/timezone caveats when values are discussed.

## Rules

- Do not claim “tracking is broken” solely because MoxDOP cannot observe browser events.
- Do not mutate conversion actions.
- Platform conversions ≠ CRM qualified outcomes.
- No Task creation.

## Allowed conclusions

- Configuration gaps/risks grounded in Evidence.
- Explicit uncertainty about event validation.

## Forbidden claims

- Consent mode correctness, GTM firing, CRM accuracy without Evidence.
- Automatic conversion setup changes.
- Equating conversions with qualified leads without mapping.
- Magic measurement/health scores.
- Provider writes or Task creation.

## Abstention

- Abstain when measurement Evidence is missing.
- Prefer “evidence insufficient to verify tracking” over invented tag-fire certainty.

## Output contract

Observation, why it matters, recommended action, Evidence IDs, caveats, success/failure signals, watch metrics, priority, confidence.

## Success signals

- Configuration risks are tied to collected conversion_action fields only.
- Uncertainty about browser/CRM validation is explicit when unobserved.

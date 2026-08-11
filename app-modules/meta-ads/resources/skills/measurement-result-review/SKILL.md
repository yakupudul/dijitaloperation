---
name: Measurement Result Review
slug: measurement-result-review
version: 1.0.0
module: meta-ads
purpose: Interpret available Meta actions/results and measurement context without pretending MoxDOP has pixel/CAPI event validation or CRM access.
required_evidence:
  - meta_ads_campaign_performance
required_capabilities:
  - meta-ads.read
optional_capabilities: []
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

## Rules

- Do not claim "tracking is broken" solely because MoxDOP cannot observe browser/server events.
- Do not mutate pixels, events, or conversion definitions.
- Platform actions/results ≠ CRM qualified leads, sales, or profit.

## Allowed conclusions

- Measurement/result interpretation gaps grounded in Evidence.
- Explicit uncertainty about event validation and business outcome linkage.

## Forbidden claims

- Pixel/CAPI correctness, CRM match rates, or lead quality without Evidence.
- Automatic event setup or conversion definition changes.
- Qualified-lead or profit claims from Meta results alone.

## Output contract

Observation, why it matters, recommended action, Evidence IDs, caveats, success/failure signals, watch metrics, priority, confidence.

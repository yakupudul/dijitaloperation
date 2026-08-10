---
name: Measurement Quality Review
slug: measurement-quality-review
version: 1.0.0
module: google-ads
purpose: Interpret available conversion/measurement configuration Evidence without pretending MoxDOP has browser/GTM access.
required_evidence:
  - google_ads_conversion_actions
required_capabilities:
  - google-ads.read
optional_capabilities: []
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

## Rules

- Do not claim “tracking is broken” solely because MoxDOP cannot observe browser events.
- Do not mutate conversion actions.
- Platform conversions ≠ CRM qualified outcomes.

## Allowed conclusions

- Configuration gaps/risks grounded in Evidence.
- Explicit uncertainty about event validation.

## Forbidden claims

- Consent mode correctness, GTM firing, CRM accuracy without Evidence.
- Automatic conversion setup changes.

## Output contract

Observation, why it matters, recommended action, Evidence IDs, caveats, success/failure signals, watch metrics, priority, confidence.

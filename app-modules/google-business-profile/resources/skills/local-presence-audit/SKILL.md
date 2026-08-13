---
name: Local Presence Audit
slug: local-presence-audit
version: 1.0.0
module: google-business-profile
purpose: Review GBP profile consistency against Brand Context and Website Evidence without inventing a local SEO score.
required_evidence:
  - gbp_location_profile
required_capabilities:
  - google-business-profile.read
optional_capabilities: []
reference_sources:
  - Official Google Business Profile APIs (read)
---

## When to use

Use when GBP location profile Evidence is available for the target Digital Asset.

## Do not use when

- Profile Evidence is missing — do not invent NAP mismatches.
- You would claim Maps ranking causation from incomplete samples.

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
- Automatic GBP profile writes or review replies.

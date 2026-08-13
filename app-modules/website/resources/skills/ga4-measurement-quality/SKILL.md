---
name: GA4 Measurement Quality
slug: ga4-measurement-quality
version: 1.0.0
module: website
purpose: Interpret Google Analytics Evidence as measurement quality and Business Action mapping — not a GA UI clone.
required_evidence:
  - ga4_events
required_capabilities:
  - ga4.read
optional_capabilities: []
reference_sources:
  - Google Analytics Data API (read — transitional Website-scoped collectors may apply)
---

## When to use

Use when GA4 event/stream Evidence is available for the GA4 Digital Asset (or transitional Website binding).

## Do not use when

- Evidence is missing — label Not collected / Unavailable instead of inventing zeros.
- You would invent session replay or PII.

## Methodology

1. Separate Business Actions (Lead Form, WhatsApp, Phone, Appointment, Purchase) from raw event names.
2. Flag interruption / duplicate / naming / self-referral / UTM candidates only when Evidence supports them.
3. Never invent a universal Measurement Score.

## Allowed conclusions

- Measurement review candidates with Evidence IDs.
- Explicit uncertainty where streams/events are incomplete.

## Forbidden claims

- Browser tag firing certainty without Evidence.
- Fingerprinting or session replay.
- Provider writes.

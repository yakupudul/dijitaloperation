# Google Analytics (GA4)

## Purpose

Measurement Intelligence for a Brand’s Google Analytics property: trust signals, business-action mapping, acquisition hygiene, Website behavior, journeys, and operational Findings — while GA4 Evidence continues to enrich Website and Ads analysis.

## User value

Operators answer: Is measurement working? Which business actions are mapped? Where is acquisition unclean? Which landing pages matter? What should the agency investigate next? — without assembling a diagnosis manually inside the Google Analytics UI every time.

## Core concepts (ADR-042)

* **Digital Asset:** Google Analytics property (canonical type `ga4`, label Google Analytics / GA4)
* **Relationship:** *measures* Website (and future App streams); *provides measurement Evidence* to Google Ads / Meta Ads
* **Connection:** Google Analytics API / Google Integration (OAuth) — technical access only
* **Capability:** Measurement Intelligence (Demo workspace now; live collector expansion is out of band)

GA4 as Asset and GA4 as Evidence provider are **not** mutually exclusive. GA4 is **not** imprisoned as a Website-only Connection in the product model.

## MVP / Demo behavior

Workspace IA:

1. Overview
2. Measurement (Business Actions · Events · Data Streams · Data Quality)
3. Acquisition
4. Behavior
5. Journeys
6. Relationships
7. Operations (Findings → Recommendations → Tasks → Outcomes)

Business understanding first: map raw GA4 events to MoxDOP **business actions** (Lead Form, WhatsApp, Phone, …). Raw event names are secondary detail.

Demo Mode uses deterministic daily fixtures + shared Demo period filters. Custom date ranges recalculate aggregates.

## Important data / attributes

Property identity, measurement ID (non-secret), Data Streams, event catalog, business-action mappings, acquisition aggregates, landing behavior, journey patterns, Findings chain.

## Relationships

```text
Google Analytics (Asset)
  ├── measures → Website
  ├── provides measurement Evidence → Google Ads
  └── provides post-click Evidence → Meta Ads

Connection: Google Analytics API (Connected)
```

Sibling Brand Assets — no ownership under Website or Ads.

## Main screens / workflows

Brand Digital Estate → open GA4 Asset; global Digital Assets directory; cross-links from Website / Ads Measurement where relationships exist.

## Rules / invariants

* Read-only externally. No GA4 write (Key Events, streams, attribution, cross-domain, Consent Mode, GTM).
* Internal MoxDOP business-action mapping is allowed and does not edit the GA4 property.
* No fake scores (Measurement Score / Data Quality Score / …). Use states + Evidence.
* Missing ≠ zero (`Not mapped` / `Unavailable` / `Data unavailable`).
* No user-level PII, session replay, or GA4 UI clone.
* **Capability truth:** Existing real Website-scoped GA4 collection may remain unchanged; do not duplicate provider stores merely for Demo IA (ADR-042).

## Derived information

Period comparisons, acquisition hygiene candidates, interruption / duplicate-event review candidates, journey aggregates — deterministic Demo / derived interpretation with provenance.

## Later enhancements

Live Binding-first GA4 Asset collection migration; App stream Assets; AI-assisted mapping suggestions (advisory only — no automatic Findings).

## Explicit non-goals

Full GA4 clone; BI warehouse; external write; autonomous analytics agents; Consent Mode inference from aggregates.

## Acceptance intent

GA4 is openable as a first-class Asset workspace that turns Analytics into measurement intelligence, while remaining honest that live provider architecture may still be Website-scoped until an explicit migration task.

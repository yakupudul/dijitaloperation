# DataForSEO (Website usage)

## Purpose

Website SEO evidence powered by the **agency** DataForSEO Integration — not per-site credentials.

## Canonical credentials

See `docs/product/integrations/DATAFORSEO.md`.

Website collectors resolve:

```
DigitalAsset (website)
+ active DataForSEO Integration
+ Website SEO market configuration
```

Do **not** create `Website → CoreConnection → DataForSEO username/password` for normal new operation.

## Current product use (Light V1)

See `docs/product/website/SEO_INTELLIGENCE.md`.

- Ranked Keywords (organic)
- Keywords For Site opportunities
- Free Labs locations/languages directory for Search market UX
- Cost guard: fingerprint + TTL + paid-request lock

## User value

External keyword visibility and opportunity intelligence appear in Website → Performance (Organic Visibility), without turning MoxDOP into a full SEO suite.

## Core concepts

Paid allowlisted endpoints only. Normalized Evidence on Core Run/Evidence. Market-specific requests. Estimated metrics clearly labeled.

## Rules / invariants

- No write actions
- No endpoint tourism
- No paid calls from page render
- No DataForSEO in generic Refresh data
- No credentials in fingerprints / Runs / Evidence / UI
- Server constructs approved requests only

## Explicit non-goals

Rank tracking, backlinks, competitors, OnPage, SERP Live, AI/GEO, per-site DataForSEO credentials as normal UX.

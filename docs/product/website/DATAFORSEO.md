# DataForSEO (Website usage)

## Purpose

Future Website SEO evidence (SERP/keyword/backlink/etc.) powered by the **agency** DataForSEO Integration — not per-site credentials.

## Canonical credentials

See `docs/product/integrations/DATAFORSEO.md`.

Website collectors must resolve:

```
DigitalAsset (website)
+ active DataForSEO Integration
+ Website-specific request configuration
```

Do **not** create `Website → CoreConnection → DataForSEO username/password` for normal new operation.

## User value

Görünürlük ve içerik boşluğu fırsatları ekonomik biçimde Findings'e girer.

## Core concepts

Possible capabilities: SERP, keywords, competitors, backlinks, SERP features, content gaps, local results, brand visibility, supported AI/search visibility. Yüzlerce endpoint ≠ hepsini entegre et.

## MVP behavior

Önce diagnosis/use-case catalog; sonra gereken endpoint. Maliyet farkındalığı; gereksiz çağrı yok.

Paid calls must use:

* allowlisted DataForSEO client methods
* request fingerprint
* capability-specific Evidence TTL (`fresh_until`)
* Run cost/provenance metadata

## Important data / attributes

Request provenance, cost-aware run metadata, normalized evidence, request fingerprint.

## Relationships

Agency DataForSEO Integration → shared client → Website collectors → Run/Evidence/Findings.

## Main screens / workflows

Provider config: Settings → Integrations → DataForSEO.  
Website product collectors: later SEO Intelligence milestones.

## Rules / invariants

No write. No endpoint tourism. Catalog/use-case first. No blind paid POST retries.

## Explicit non-goals

Integrating every DataForSEO endpoint. Per-site DataForSEO credential JSON as normal UX.

## Acceptance intent

Yalnızca teşhis için gerekli DataForSEO verisi çekilir — after the agency Integration + cost-guard foundation.

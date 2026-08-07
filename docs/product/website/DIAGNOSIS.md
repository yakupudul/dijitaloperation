# Website Diagnosis

## Purpose

GA4/GSC/DataForSEO olmadan bile teknik ve temel on-page durumu teşhis etmek; connection'lar geldikçe kapsam/confidence artar.

## User value

Ajans erken teknik riskleri kanıtla görür.

## Core concepts

Diagnosis-first. Catalog-driven (ADR-031).  
**Implementation başlamadan önce** `docs/website/DIAGNOSIS_CATALOG.md` zorunlu.  
Capability adayları (checklist hard-code değil): reachability, HTTP/HTTPS, SSL, redirects, robots, sitemap, canonical, status codes, broken links, title/meta, headings, images, schema, mobile, performance, security headers, internal linking, basic a11y, crawl depth, indexability signals.

## MVP behavior

* Catalog her item için: id, category, purpose, required/optional evidence, detection rule, severity, confidence, finding output, recommendation logic, source dependency
* Catalog açık kaynak audit/crawl sistemleri + web standartları + resmi kaynaklardan türetilir
* Amaç bütün API field'larını çekmek değil; profesyonel teşhis listesi üretmek
* Deterministic rules önce; AI sonra

## Important data / attributes

Defined in DIAGNOSIS_CATALOG.md contracts — not invented ad hoc in code.

## Relationships

Website → Diagnosis Run → Evidence → Findings → Recommendations.

## Main screens / workflows

Start diagnosis; view run; triage findings.

## Rules / invariants

No catalog → no diagnosis implementation. No external write. No Result entity.

## Derived information

Severity/confidence from catalog rules + available evidence richness.

## Later enhancements

Connector-enriched checks; scheduled re-audit.

## Explicit non-goals

Guessed findings without evidence; full SEO suite before catalog.

## Acceptance intent

Catalog-first diagnosis üretimi; agent catalog olmadan diagnosis kodlamaz.

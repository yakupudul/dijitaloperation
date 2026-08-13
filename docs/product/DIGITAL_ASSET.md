# Digital Asset

## Purpose

Digital Asset, markaya bağlı olarak gerçekten yönetilen dijital şeydir.

## User value

Ekip 'neyi yönetiyoruz?' sorusuna net cevap verir; bağlantılar, ilişkiler ve teşhisler asset üzerinde toplanır.

## Core concepts

Keep these separate (ADR-042):

| Concept | Answers |
| -------- | -------- |
| **Digital Asset** | What managed digital system/property is this? |
| **Relationship** | What role does this Asset play relative to another Asset? |
| **Connection** | How does MoxDOP technically access the Asset/provider? |
| **Capability** | What analysis does MoxDOP perform using collected Evidence? |

**DIGITAL ASSET examples:** Website, Google Analytics (GA4), GBP, Google Ads account, Meta Ads, Instagram, YouTube, CRM, …

**CONNECTION examples:** WordPress, Google Analytics API / OAuth, PageSpeed, DataForSEO, Lighthouse, …

**Google Analytics principle:** GA4 is a first-class Digital Asset **and** may provide measurement / post-click Evidence to Website and Ads Assets. Evidence-provider role does **not** demote GA4 to “Website tool only,” and does **not** make GA4 a child of Website or Ads.

Canonical type key for Google Analytics: **`ga4`** (UI: Google Analytics / GA4). Do not introduce parallel type keys for the same concept.

**Search Console / DataForSEO:** remain Website-oriented Connections in current product scope unless explicitly elevated later.

## Visual identity

Digital Assets use a centralized visual catalog (`DigitalAssetVisualCatalog` + local SVG marks):

* Provider-owned systems → recognizable local product marks (Google Ads, Meta, GBP, GA4, …)
* Website → Brand / site identity first (Demo: Atlas brand mark fixture), then favicon / globe fallback — no remote logo CDN on every render
* Logo identifies the Asset; connection/health state uses separate badges

## MVP behavior

* Asset Brand'a bağlıdır; type + name + status
* Connections asset'e bağlanır (technical access)
* Relationships express roles (e.g. GA4 *measures* Website; GA4 *provides Evidence to* Google Ads / Meta Ads)
* Asset ekranı Runs / Findings / Recommendations / Tasks'e köprü olur
* Yeni asset türleri modüllerle eklenir
* Platforma özel şişkin Core kolonları yok

## Important data / attributes

name, type, brand_id, status, module ownership. last success/error/health zorunlu duplicate kolon değildir.

## Relationships

Brand → Digital Asset (siblings) → Connection; Asset ↔ Asset relationships (measures / provides Evidence); Asset → Runs → Evidence; Asset → Findings → Recommendations → Tasks.

## Main screens / workflows

Asset list (per brand + global directory), asset workspace overview, connections management entry, relationship views, operational tabs as features land.

## Rules / invariants

Connection asset değildir. Relationship ≠ ownership. Harici write yok. Core, her platform field'ını taşımak zorunda değildir.

**Capability truth:** Elevating GA4 in product/Demo registry does not by itself rewrite live Website-scoped GA4 collectors; avoid duplicate provider data stores (ADR-042).

## Derived information

Latest operational state, last successful run, connection health — Run/Connection verisinden UI'da derive edilebilir.

## Later enhancements

Cross-asset views, richer health widgets, asset-level SLAs, App stream Assets measured by the same GA4 Property.

## Explicit non-goals

Her API kaynağını Digital Asset yapmak; Result entity; SaaS multi-tenant asset isolation; GA4 write; full GA4 clone / BI warehouse.

## Acceptance intent

Ekip Brand altında typed Digital Asset kaydı oluşturup Connection bağlama ve (GA4 için) Measurement Intelligence workspace yolunu açabilir; Asset logoları tip bazında anında ayırt edilebilir.

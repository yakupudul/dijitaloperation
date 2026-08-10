# Meta Ads Digital Asset

## Purpose

Meta Ads account (Facebook/Meta advertising), Brand altında yönetilen bir Digital Asset türüdür. Ajansın Meta reklam hesabını read-only operasyona bağlar.

## Connection architecture (V1 — IMPLEMENTED)

Credentials are **not** stored on the Digital Asset.

```
Agency Meta Integration (provider=meta)
  → ExternalResource (Meta Ad Account, resource_type=meta_ads)
    → AssetBinding
      → Meta Ads Digital Asset
```

See `docs/product/meta-ads/META_ADS_INTEGRATION.md` for the connection milestone.

## User value

Ekip, müşteri markasının Meta Ads hesabını tek asset olarak görür; bağlanan Ad Account üzerinden ileride Evidence → Finding yoluna alır ve harici yazmadan öncelikli iç iş üretir.

## Core concepts

* **Digital Asset type:** `meta_ads` (UI: Meta Ads)
* **Meta Ads asset** = managed Meta advertising account property in MoxDOP
* **Integration** = agency Meta auth (not per customer)
* **External Resource** = discovered Meta Ad Account
* **Binding** = canonical AssetBinding (not a Meta-specific binding table)
* Pipeline (later): Binding read → Evidence → Findings → AI explanation → Recommendation drafts → manual internal Tasks
* Harici write yok (MASTER_SPEC §5)

## MVP behavior (connection V1)

* Brand altında Meta Ads Digital Asset oluşturulabilir
* Settings → Integrations → Meta: configure encrypted token, test connection, discover Ad Accounts
* Asset → Connections: bind one discovered Meta Ad Account
* Insights / Findings / Analyst **not** in V1

## Important data / attributes

Asset (non-secret): name, type=`meta_ads`, brand_id, status.

ExternalResource metadata (safe): account id, name, currency, timezone, account status, business id/name, discovery paths.

Integration credential: encrypted access token only (never shown after save).

## Relationships

Brand → Meta Ads Digital Asset → AssetBinding → ExternalResource → Meta Integration.

Legacy per-asset `meta_ads_api` CoreConnection probe helpers may still exist for older slices; new operator UX uses central Integration + Binding.

## Main screens / workflows

* Settings → Integrations → Meta
* Brand → Digital Assets → Meta Ads → Overview / Connections

## Rules / invariants

* No Meta Ads write actions
* Least-privilege read permissions (`ads_read`, `business_management`)
* Access tokens never appear in Evidence, logs, ExternalResource metadata, or UI after save (ADR-027 encrypted credentials)
* Do not invent spend/metrics/campaigns absent from Evidence
* Deterministic layer before AI when checks are rule-expressible
* No separate Result entity (ADR-036); Findings remain persistent asset-level (ADR-034)
* No SaaS/tenant/customer portal

## Derived information

Account health and later performance deltas are derived from Evidence + rules — not from a fake KPI store or Ads Manager clone. Connection V1 only surfaces Integration / Binding health.

## Later enhancements (NOT in connection V1)

* Account / campaign / ad-set / ad Evidence collectors
* Deterministic Findings
* Meta Ads Analyst + Skills + `meta_ads.ai_guidance`
* Cross-asset Website/Instagram ↔ Meta Ads packs

## Explicit non-goals (connection V1)

* Mutating Meta Ads entities or publishing creatives
* Insights collection
* Lead Ads personal data
* Full Ads Manager / BI warehouse
* Treating Meta as a Website connection
* Building Instagram organic module here

## Acceptance intent

Ajans, Brand altında typed Meta Ads Digital Asset’i agency Meta Integration üzerinden keşfedilen Ad Account’a bağlayabilir; token güvenli saklanır; write yoktur; intelligence bir sonraki milestone’dadır.

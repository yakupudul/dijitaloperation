# IMPLEMENTATION_ROADMAP

> Ana kaynak: `docs/MASTER_SPEC.md`  
> Product ayrıntı: `docs/product/*`  
> Hedef: hafif, hızlı, ekonomik MVP. Framework’ü yeniden yazma (ADR-033).

## Canonical ürün sırası

Architect yalnızca buradaki sıradaki **ilk tamamlanmamış** işi seçer ve onu küçük dikey task’lara bölebilir.  
`ROADMAP_COMPLETE` yalnızca bu canonical liste gerçekten bittiğinde döner.

> **Not (2026-08-09):** Aşağıdaki 1–23 madde **foundation / first product pass** listesidir ve Autopilot tarafından tamamlanmış sayılmıştır. Bu, ürünün “bitti” olduğu anlamına gelmez. Güncel ürün track’i için **Post-foundation product track** bölümüne bakın.

| # | Adım | Product blueprint |
|---|------|-------------------|
| 1 | Laravel / Filament bootstrap | — (tamamlandı: Core bootstrap) |
| 2 | Auth + users / roles / permissions | — (tamamlandı: Core bootstrap) |
| 3 | Customer | `docs/product/CUSTOMER.md` |
| 4 | Brand | `docs/product/BRAND.md` |
| 5 | Digital Asset | `docs/product/DIGITAL_ASSET.md` |
| 6 | Connection + encrypted credentials | `docs/product/CONNECTION.md` + `docs/product/DIGITAL_ASSET.md` (+ ADR-027) |
| 7 | Minimal Module Registry | `docs/product/MODULE_PLATFORM.md` |
| 8 | Run / Evidence / Finding / Recommendation / Task | `docs/product/ANALYSIS_PIPELINE.md` |
| 9 | Website module | `docs/product/website/WEBSITE.md` |
| 10 | Website Diagnosis Catalog (`docs/website/DIAGNOSIS_CATALOG.md`) | `docs/product/website/DIAGNOSIS.md` |
| 11 | Website Diagnosis implementation | `docs/product/website/DIAGNOSIS.md` |
| 12 | WordPress Connector | `docs/product/website/WORDPRESS.md` |
| 13 | Search Console Connector | `docs/product/website/SEARCH_CONSOLE.md` |
| 14 | GA4 Connector | `docs/product/website/GA4.md` |
| 15 | PageSpeed / Lighthouse Connector | `docs/product/website/PAGESPEED_LIGHTHOUSE.md` |
| 16 | DataForSEO Connector | `docs/product/website/DATAFORSEO.md` |
| 17 | Website AI Insights | `docs/product/website/AI_INSIGHTS.md` |
| 18 | Google Business Profile product spec + first module | `docs/product/google-business-profile/GOOGLE_BUSINESS_PROFILE.md` |
| 19 | Google Ads product spec + first module | `docs/product/google-ads/GOOGLE_ADS.md` |
| 20 | Meta Ads product spec + first module | `docs/product/meta-ads/META_ADS.md` |
| 21 | Instagram product spec + first module | `docs/product/instagram/INSTAGRAM.md` |
| 22 | Cross-asset / cross-channel analysis | `docs/product/cross-asset/CROSS_ASSET_ANALYSIS.md` (+ `docs/product/DASHBOARD.md` for later surfacing) |
| 23 | Action-oriented agency operations dashboard / first production hardening | `docs/product/DASHBOARD.md` |

**Sample Module:** bootstrap smoke test; ayrı ürün fazı değildir.

## Post-foundation product track (current)

Foundation 1–23 tamamlandıktan sonra devam eden **güncel** ürün sırası. Bu liste eski 23 adımı yeniden “açık” saymaz; üzerine ekler.

| Order | Milestone | Status | Notes / specs |
|------|-----------|--------|----------------|
| P0 | Google central Integration + bound collection + performance Findings | COMPLETED | ADR-039/040 path |
| P1 | Website Workspace Productization + Website Intelligence V2A | COMPLETED | Website workspace tabs |
| P2 | DataForSEO Central Integration + Cost Guard | COMPLETED | `docs/product/integrations/DATAFORSEO.md` |
| P3 | SEO Intelligence DataForSEO Light | COMPLETED | `docs/product/website/SEO_INTELLIGENCE.md` |
| P4 | Brand Intelligence Context | COMPLETED | `docs/product/BRAND_INTELLIGENCE.md` |
| P5 | AI Recommendation Intelligence V1 | COMPLETED / MERGED (PR #106, `094fe0a`) | `docs/product/website/AI_INSIGHTS.md`, ADR-041 |
| P6 | Integrations Workspace V2 | COMPLETED / MERGED (PR #107, `61bbfc8`) | `docs/product/integrations/WORKSPACE.md` |
| **N0** | **Module Boundary + Knowledge / Memory Architecture Audit V1** | COMPLETED (PR #109 / `ec31bde`) | `docs/current-state/MODULE_BOUNDARY_AUDIT_V1.md`, `docs/product/KNOWLEDGE_MEMORY_ARCHITECTURE.md` |
| **N0b** | **Capability + Discovery product direction docs V1** | COMPLETED (docs only; PR #111 / `b0bb285`) | Agent Reach tracked; `docs/product/DISCOVERY_INTELLIGENCE.md`; Capability / Adapter distinctions — **no runtime** |
| **N1** | **AI Provider Routing & Failover V1** | **IMPLEMENTED V1** | `docs/product/AI_CONTROL_PLANE.md` — OpenAI / Anthropic / Gemini + `website.ai_guidance` |
| **N2** | **Agent Profiles + Skill Library V1** | **IMPLEMENTED V1** | `docs/product/AGENT_SKILL_ARCHITECTURE.md` — Website SEO Analyst + curated Website Skills |
| **N2b** | **Google Ads Intelligence + Analyst V1** | **IMPLEMENTED V1** | `docs/product/google-ads/GOOGLE_ADS_INTELLIGENCE.md` — Google Ads Analyst + Skills + `google_ads.ai_guidance` |
| **N2c** | **Operational Recommendation → Task → Outcome Loop V1** | **IMPLEMENTED V1** | `docs/product/OPERATIONAL_OUTCOME_LOOP.md` — Task lifecycle + Finding-linked Outcome signals (no Result entity) |
| **N2d** | **Discovery Intelligence V1** | **IMPLEMENTED V1** | `docs/product/DISCOVERY_INTELLIGENCE.md` — Website public discovery + candidates + human review; optional DataForSEO competitors |
| **N2e** | **Meta Ads Central Integration + Resource Binding V1** | **IMPLEMENTED V1** | `docs/product/meta-ads/META_ADS_INTEGRATION.md` — Meta provider + Ad Account discovery + AssetBinding |
| **N2f** | **Meta Ads Intelligence + Analyst V1** | **IMPLEMENTED V1** | `docs/product/meta-ads/META_ADS_INTELLIGENCE.md` — Insights Evidence + Findings + Analyst/Skills + `meta_ads.ai_guidance` |
| **N2g** | **Async Operations + Activity Center** | **ACCEPTED (implementation) / merging** | Persistent UAT prepared+deferred; Cloud Meta async smoke accepted |
| **N2h** | **Meta Ads Expert Workspace** | **NEXT** | `docs/product/META_ADS_EXPERT_WORKSPACE.md` + `OPERATOR_WORKSPACE_DESIGN_STANDARD.md` — first reference-quality operator workspace |
| **N3** | **Operational Data Foundation / Historical Performance Store** | PLANNED (after Expert Workspace) | Do not start until Meta Expert Workspace merges / is accepted |
| **N4** | **Memory / Retrieval V1** | PLANNED (deferred) | Only when knowledge volume justifies; vector RAG deferred |

### Later candidates (UNCOMMITTED — after Meta Expert Workspace)

No fixed dates. Review operational value before committing. Do **not** auto-start:

- Persistent UAT host provisioning (deferred by operator until UI is useful)
- Professional Meta Expert Workspace is **NEXT**, not later
- Digital Operations / Cross-Asset Analyst
- GBP Reputation Intelligence (official APIs; scraper rejected)
- Capability Registry / Routing V1
- Playbook V1
- richer metric Outcome Signals
- GEO / AI Search Intelligence
- competitor / domain intelligence
- backlinks
- rank tracking

External research registry: `docs/research/EXTERNAL_INTELLIGENCE_ADOPTION_AUDIT.md` (Agent Reach = #9; Agency Agents = #10).  
Discovery Intelligence V1: **IMPLEMENTED** (`docs/product/DISCOVERY_INTELLIGENCE.md`).  
Meta Ads Intelligence + Analyst V1: **IMPLEMENTED** (`docs/product/meta-ads/META_ADS_INTELLIGENCE.md`).  
Async Operations + Activity Center: **ACCEPTED (implementation)**; persistent UAT **DEFERRED**.  
Meta Ads Expert Workspace: **NEXT**.  
AI Router + Agent/Skill V1: **IMPLEMENTED**. Outcome Loop V1: **IMPLEMENTED**. Capability Router / Playbooks / Learned Memory / RAG: **PLANNED**.


## Küçük task bölme

Büyük bir roadmap maddesi tek PR olmak zorunda değildir. Örnek Customer:

* Customer foundation model/migration  
* Contacts  
* UI/detail  
* team responsibility  
* tests  

## Faz notları

| Adım | Not |
|------|-----|
| 1–2 | Tek panel `app`/`/app`, `spatie/laravel-permission`, Admin / Team Member — **done** |
| 3–6 | Domain CRUD + Connection/credential (ADR-027) |
| 7 | Custom plugin framework yok; minimal registry |
| 8 | Finding kalıcı + fingerprint (ADR-034); Evidence Run’a bağlı; Result entity yok |
| 10 | Catalog diagnosis-first; ADR-031 kapısı |
| 12–16 | Connection’lar Website asset üzerinde; read-only |
| 17 | `laravel/ai`; MCP/vector/multi-agent yok |
| 18–21 | Önce product blueprint detayı, sonra first module |

## Erken / yasaklı genişlemeler

* SaaS / Workspace / Client Portal / marketplace / ZIP install  
* Harici write  
* Custom compatibility engine, custom migrator registry, purge, kapsamlı lifecycle FSM  
* Attachments / Tags / feature flags / ağır notification-audit-health framework’leri (ihtiyaç + sonraki faz; ADR-037)  
* Redis / Horizon (ihtiyaç kanıtı olmadan)  
* Ayrı Result entity  

## Bloker

| Konu | Durum |
|------|--------|
| Core bootstrap | Tamamlandı |
| Diagnosis catalog | Website Diagnosis **implementation** (adım 11) öncesi zorunlu; adım 10’da üretilir |
| Product blueprints | `docs/product/*` — Architect/Reviewer okur |

## Kurallar

1. Üst adım olmadan alt adımı “bitti” sayma.  
2. Framework’ün çözdüğünü tekrar yazma (ADR-033).  
3. Ürün kapsamını SaaS veya harici write’a genişletme.  
4. Product task’ta ilgili blueprint `product_spec_paths` ile bağlanır.  

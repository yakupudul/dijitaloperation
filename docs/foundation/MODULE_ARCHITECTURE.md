# MODULE_ARCHITECTURE

> Ana kaynak: `docs/MASTER_SPEC.md`  
> İlgili ADR: ADR-004, ADR-007, ADR-009, ADR-032, ADR-033, ADR-035  
> Boundary audit: `docs/current-state/MODULE_BOUNDARY_AUDIT_V1.md`  
> Knowledge/Memory: `docs/product/KNOWLEDGE_MEMORY_ARCHITECTURE.md`

## Kararlar

### 1. Temel

Modular monolith: tek repo/app/deploy/DB.  
Paketleme: **`app-modules/`** + **`internachi/modular`** + Composer + Laravel Service Provider + Filament Plugin.

### 2. Locked distinctions

| Concept | Meaning | Examples |
| --- | --- | --- |
| **Integration** | External provider/service connection | Google agency auth, DataForSEO, OpenAI, future Anthropic/Gemini |
| **Module** | MoxDOP business/domain capability | Website, Google Ads, Google Business Profile, future Meta Ads |
| **Agent** | Bounded AI workflow/persona (**planned**) | Website SEO Analyst, Google Ads Analyst |
| **Skill** | Versioned analytical methodology (**planned**) | Technical SEO Audit, Search Term Analysis |
| **Memory** | Broader product concept (institutional + operational + Skill + learned) | See Knowledge/Memory architecture |
| **RAG** | Retrieval-Augmented Generation — **not** a synonym for “AI memory” | Vector RAG **not** implemented |

Provider ≠ Module. Integration ≠ Module. Agent ≠ Module. Skill ≠ Module.  
Do **not** create one Module per external GitHub repository.

### 3. MVP Module Registry (ADR-035)

Minimum alanlar: `module_id`, `enabled`/`disabled`, isteğe bağlı bilgisel `installed_version`.

Disabled → DOP UI / scheduled analysis jobs kapalı; kod Composer’da kalabilir; veri silinmez.

Operator-facing registry lists **business capabilities** only:

- `website`
- `google-ads`
- `google-business-profile`

`sample-module` is a developer/packaging fixture: may remain installed/seeded, but is **hidden** from normal operator Module Registry UI.

Integrations/providers (**not** Modules): OpenAI, DataForSEO, Anthropic, Gemini, OpenRouter, Google, Meta.

### 4. Core vs Module responsibilities

**Core / shared MAY own** generic primitives: Customer, Brand, DigitalAsset, Integration/credential/ExternalResource/AssetBinding, Run/Evidence/Finding/Recommendation/Task, Auth/RBAC, Module Registry, generic queue/events, generic HTTP/provider transport, credential storage/resolution, generic AI provider infrastructure, request fingerprint/cost/provenance primitives, cross-module contracts.

**Core MUST NOT own** business/domain interpretation: SEO diagnosis rules, Website title/meta analysis semantics, GSC opportunity rules, Google Ads performance interpretation, keyword opportunity semantics, GBP review interpretation, Website-specific AI reasoning, Meta campaign analysis.

**Modules own** their collectors, Findings semantics, AI reasoning, future Agents/Skills, and domain UI/presenters.

**Provider rule:** do not move generic provider infrastructure into a Module merely because one Module currently uses it. Separate *how we call the provider* (shared) from *what the data means* (module).

### 5. Dependency direction

```text
MODULE → CORE contracts / shared infrastructure
```

Core must not depend directly on module domain implementations except a **small explicit allowlist** of compatibility facades and Filament composition surfaces (enforced by `tests/Unit/ModuleBoundaryArchitectureTest.php`).

### 6. MVP’de yazılmayacak custom framework parçaları (future / non-MVP)

* compatibility engine (`core.min` / `core.maxExclusive`)
* custom module migrator / migration registry
* discovered/registered/failed/uninstalled FSM
* runtime plugin install, purge, marketplace, custom schema registry

Bunlar `docs/module-sdk` içinde belgelenebilir ancak **MVP implementasyonunu zorlamaz**.

### 7. İlk ürün modülleri

Website → connectors → AI Insights (sıra roadmap’te).  
Sample module = kısa smoke test / packaging fixture; ürün fazı değil; operator registry’de gösterilmez.

## Gerekçe

ADR-033: paketlerin verdiğini tekrar yazmamak MVP hızını korur.  
ADR-007: çekirdek domain iş kuralı bilmez.

## Sınırlar

* Diagnosis katalog içeriği bu belgede üretilmez.
* PHPUnit (ADR-038; Pest eklenmez)
* Knowledge/Memory planned architecture does not by itself require a new ADR (existing Core/module ADRs remain canonical).

## Açık Sorular

Yok.

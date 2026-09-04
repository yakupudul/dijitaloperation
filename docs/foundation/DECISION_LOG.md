# DECISION_LOG

> Architecture Decision Records  
> Durum: Accepted | Proposed | Superseded  
> Ana ürün kaynağı: `docs/MASTER_SPEC.md`

---

## ADR-001 — Ürün tanımı: teşhis ve aksiyon platformu

- **Durum:** Superseded by **ADR-015**
- **Tarih:** 2026-08-06
- **Karar (eski):** Genel “dijital operasyon platformu”; SaaS/ajans ayrımı belirsizdi.
- **İlgili:** `PRODUCT_VISION.md`

## ADR-002 — Temel değer akışı

- **Durum:** Superseded by **ADR-016**
- **Tarih:** 2026-08-06
- **Karar (eski):** `Veri → Kanıt → Teşhis → İçgörü → Öneri → Görev → Sonuç`

## ADR-003 — Temel sahiplik hiyerarşisi

- **Durum:** Superseded by **ADR-017**
- **Tarih:** 2026-08-06
- **Karar (eski):** `Workspace → Customer → Brand → Digital Asset`; GA4/GSC asset gibi listeleniyordu.

## ADR-004 — Plugin tabanlı modular monolith

- **Durum:** Accepted
- **Tarih:** 2026-08-06
- **Karar:** Plugin tabanlı modular monolith.
- **İlgili:** `MODULE_ARCHITECTURE.md`

## ADR-005 — Başlangıç deployment modeli

- **Durum:** Superseded by **ADR-021** / netleştirme **ADR-022**
- **Tarih:** 2026-08-06
- **Karar (eski):** Tek app/deploy, *tercihen* tek DB; teknoloji seçilmemişti.
- **Yeni:** Tek repo, tek app, tek deploy, **tek DB** (MySQL 8); yerel modüller.

## ADR-006 — Çekirdek sorumlulukları

- **Durum:** Superseded by **ADR-020**
- **Tarih:** 2026-08-06
- **Karar (eski):** Workspaces içeriyordu; Connections/Evidence/Findings yoktu.

## ADR-007 — Çekirdeğin bilmemesi gerekenler

- **Durum:** Accepted (hala geçerli; ADR-018 ile güçlendirildi)
- **Tarih:** 2026-08-06
- **Karar:** Çekirdek SEO/Meta/GA4 iş kuralı bilmez; crawl yapmaz; platforma özgü AI prompt koymaz; harici platforma bağımlı olmaz.

## ADR-008 — Modül yaşam döngüsü ve paket kuralları

- **Durum:** Accepted
- **Tarih:** 2026-08-06
- **Güncelleme:** Paketleme ADR-022 ile yerel Composer/Filament plugin olarak netleşti; marketplace yok.

## ADR-009 — Modüller arası iletişim ve izolasyon

- **Durum:** Accepted
- **Tarih:** 2026-08-06

## ADR-010 — İlk modül sınıfları

- **Durum:** Accepted
- **Tarih:** 2026-08-06
- **Not:** Presentation sınıfı MVP’de Client Portal içermez.

## ADR-011 — İlk gerçek modül: Website Diagnosis

- **Durum:** Superseded by **ADR-024**
- **Tarih:** 2026-08-06
- **Karar (eski):** Yalnızca Website Diagnosis “ilk gerçek modül”dü.
- **Yeni:** İlk set Website + Website Diagnosis + connectors + AI Insights.

## ADR-012 — Website Diagnosis connector zorunluluğu yok

- **Durum:** Accepted
- **Tarih:** 2026-08-06
- **Karar:** GA4 / Search Console / DataForSEO zorunlu değildir; Connection olarak eklenince kapsam artar.

## ADR-013 — Ağır işler background job’dır

- **Durum:** Accepted
- **Tarih:** 2026-08-06
- **Güncelleme (ADR-021):** Başlangıçta Laravel database queue + scheduler.

## ADR-014 — Disable/uninstall veri politikası

- **Durum:** Accepted
- **Tarih:** 2026-08-06

---

## ADR-015 — Moximu iç operasyon sistemi

- **Durum:** Accepted
- **Tarih:** 2026-08-07
- **Karar:** DOP, Moximu ajansının iç operasyon sistemidir. SaaS değildir. Müşteri girişi yoktur.
- **Sonuçlar:** Ürün kararları ajans Admin/Team Member bağlamında değerlendirilir.
- **İlgili:** `MASTER_SPEC.md`, `PRODUCT_VISION.md`

## ADR-016 — Operasyonel akış nesneleri

- **Durum:** Superseded by **ADR-036** (Result kaldırıldı); Finding yaşamı **ADR-034**
- **Tarih:** 2026-08-07
- **Karar (eski):** Akışta ayrı `Result` entity vardı.
- **İlgili:** `MASTER_SPEC.md`, ADR-034, ADR-036

## ADR-017 — Asset / Connection ayrımı; Workspace yok (MVP)

- **Durum:** Accepted
- **Tarih:** 2026-08-07
- **Karar:** Hiyerarşi `Customer → Brand → Digital Asset → Connection`. Workspace/multi-tenant MVP dışı. GA4/GSC/DataForSEO Website connection’larıdır, asset değildir.
- **İlgili:** `DOMAIN_MODEL.md`, `OUT_OF_SCOPE.md`

## ADR-018 — Harici sistemlerde write yasağı

- **Durum:** Accepted
- **Tarih:** 2026-08-07
- **Karar:** DOP harici sistemlerde değişiklik yapmaz; write action sunmaz. En düşük salt okunur yetkiler tercih edilir. İç veride CRUD serbesttir.
- **İlgili:** `MASTER_SPEC.md`, `STABILITY_RULES.md`, `OUT_OF_SCOPE.md`

## ADR-019 — MVP kullanıcı modeli

- **Durum:** Accepted
- **Tarih:** 2026-08-07
- **Karar:** Roller: Admin, Team Member. Self-service, tenant onboarding, abonelik, faturalama, paket, kota, Client Portal, marketplace yok. İleride SaaS ihtimali bugünden kodlanmaz.
- **İlgili:** `MASTER_SPEC.md`, `OUT_OF_SCOPE.md`

## ADR-020 — Güncel çekirdek sorumluluk listesi

- **Durum:** Superseded by **ADR-037** (MVP Core sade liste)
- **Tarih:** 2026-08-07
- **Karar (eski):** Attachments/Tags/feature flags/health/audit vb. çekirdekte zorunlu görünüyordu.
- **İlgili:** ADR-037

## ADR-021 — Teknoloji yığını

- **Durum:** Accepted
- **Tarih:** 2026-08-07
- **Güncelleme:** 2026-08-07 (ADR-026, 030, 032 ile netleşti)
- **Karar:** Laravel 13, PHP 8.3+, Filament 5, Livewire, MySQL 8, database queue, Laravel scheduler/events/HTTP client/encryption, PHPUnit (see **ADR-038**; Pest satırı superseded), `spatie/laravel-permission`, `laravel/ai`, `internachi/modular`. Redis/Horizon ihtiyaç halinde.
- **İlgili:** `MASTER_SPEC.md`, `MODULE_ARCHITECTURE.md`

## ADR-022 — Yerel modül paketleme; marketplace yok

- **Durum:** Accepted (detay **ADR-032**)
- **Tarih:** 2026-08-07
- **Karar:** Modüller aynı repository içinde yerel Composer package olarak yaşar. ZIP upload ve marketplace yoktur.
- **İlgili:** `MODULE_ARCHITECTURE.md`, ADR-032

## ADR-023 — AI sınırı

- **Durum:** Accepted
- **Tarih:** 2026-08-07
- **Karar:** AI önce Evidence/Finding üreten kurallı boru hattından sonra çalışır. Uydurma ve kanıtsız kesin hüküm yasak. MCP/multi-agent MVP dışı.
- **İlgili:** `MASTER_SPEC.md`

## ADR-024 — İlk modül seti

- **Durum:** Accepted
- **Tarih:** 2026-08-07
- **Karar:** Website, Website Diagnosis, WordPress Connector, Search Console Connector, GA4 Connector, PageSpeed/Lighthouse Connector, DataForSEO Connector, AI Insights. Sonraki asset’ler: GBP, Google Ads, Meta Ads, Instagram, …
- **İlgili:** `MODULE_ARCHITECTURE.md`, `IMPLEMENTATION_ROADMAP.md`

## ADR-025 — Recommendation → Task manuel

- **Durum:** Accepted (alan/snapshot detayı **ADR-029**)
- **Tarih:** 2026-08-07
- **Karar:** Kullanıcı Recommendation kaydını manuel olarak Task’a dönüştürür. Harici sistemde otomatik aksiyon yoktur.
- **İlgili:** `MASTER_SPEC.md`, ADR-029

## ADR-026 — Tek Filament panel ve auth

- **Durum:** Accepted (panel **path** superseded by **ADR-044**; auth/RBAC unchanged)
- **Tarih:** 2026-08-07
- **Karar:** Tek panel id `app`, path `/app`. Laravel `web` session guard. Public registration yok; kullanıcıları Admin oluşturur. Password reset ve profile var. Roller Admin / Team Member. RBAC: `spatie/laravel-permission`. Multi-tenancy veya müşteri guard yok.
- **İlgili:** `MASTER_SPEC.md` §6, `CORE_RESPONSIBILITIES.md`; path → ADR-044

## ADR-027 — Connection ve credential şeması

- **Durum:** Accepted
- **Tarih:** 2026-08-07
- **Karar:** `core_connections` secret’sız kimlik/ayar/sağlık tutar. Secret’lar `core_connection_credentials.encrypted_payload` içinde Laravel encryption/encrypted cast (TEXT) ile saklanır. Ham credential Filament/Livewire model state’ine expose edilmez.
- **İlgili:** `MASTER_SPEC.md` §7.1, `DATA_OWNERSHIP.md`

## ADR-028 — Evidence / Finding / Recommendation minimum alanları

- **Durum:** Superseded by **ADR-034** (Finding kalıcı lifecycle + alanlar)
- **Tarih:** 2026-08-07
- **Karar (eski):** Finding alanlarında `run` birincil bağ gibi duruyordu.
- **İlgili:** ADR-034

## ADR-029 — Recommendation → Task snapshot sözleşmesi

- **Durum:** Accepted
- **Tarih:** 2026-08-07
- **Karar:** Otomatik Task yok. Manuel dönüşümde taşınan context: customer, brand, digital_asset, recommendation_id, title, action/description, priority, rationale/context. Assignee ve due date uydurulmaz. Task snapshot’tır; Recommendation sonradan değişse Task otomatik güncellenmez.
- **İlgili:** `MASTER_SPEC.md` §7.3, ADR-025

## ADR-030 — AI SDK ve anahtar yönetimi

- **Durum:** Accepted
- **Tarih:** 2026-08-07
- **Karar:** `laravel/ai` kullanılır. Tek sağlayıcıya kilit yok; provider/model env/config ile değişir. İlk test OpenAI olabilir. MVP’de AI API key panelden yönetilmez (environment). MCP, vector DB, multi-agent yok.
- **İlgili:** `MASTER_SPEC.md` §11, ADR-023

## ADR-031 — Website Diagnosis katalog kapısı

- **Durum:** Accepted
- **Tarih:** 2026-08-07
- **Karar:** Connector’suz rule catalog Core için blocker değildir. Website Diagnosis fazından önce `docs/website/DIAGNOSIS_CATALOG.md` oluşturulur; her teşhis tanımlı contract ile, açık kaynak/standart türevli yazılır (tahminle değil).
- **İlgili:** `IMPLEMENTATION_ROADMAP.md` Faz 3.5 / 4

## ADR-032 — Modül dizini `app-modules/` + internachi/modular

- **Durum:** Accepted
- **Tarih:** 2026-08-07
- **Karar:** Modüller `app-modules/` altındadır. Laravel modüler yapı için `internachi/modular` kullanılır. Her modül Composer package gibi provider, models, migrations, services, Filament resources/pages/widgets, config, jobs, events, tests taşır.
- **İlgili:** `MODULE_ARCHITECTURE.md`, `MODULE_MANIFEST_SPEC.md`, ADR-022

## ADR-033 — Framework’ü tekrar yazmama prensibi

- **Durum:** Accepted
- **Tarih:** 2026-08-07
- **Karar:** Framework’ün veya güvenilir paketlerin çözdüğü altyapıyı DOP için yeniden yazma. DOP özel kodu ürün değerine (asset, connection, diagnosis, evidence, finding, recommendation, task, AI) ayrılır.
- **İlgili:** `MASTER_SPEC.md` §14, `MODULE_ARCHITECTURE.md`

## ADR-034 — Finding kalıcı lifecycle

- **Durum:** Accepted
- **Tarih:** 2026-08-07
- **Karar:** Finding, Digital Asset üzerindeki devam eden problem/fırsattır; tek Run’ın geçici sonucu değildir. Alanlar: digital_asset_id, source_module, fingerprint, category, severity, title, summary, confidence, status, first_seen_at, last_seen_at, last_run_id. Evidence Run’a bağlıdır. Aynı fingerprint → upsert; görülmezse resolved olabilir. Recommendation Finding’e bağlanabilir.
- **İlgili:** `MASTER_SPEC.md` §7.2, `DOMAIN_MODEL.md`

## ADR-035 — MVP minimal Module Registry

- **Durum:** Accepted
- **Tarih:** 2026-08-07
- **Karar:** MVP registry yalnızca module id + enabled/disabled (+ bilgisel installed_version). Custom compatibility engine, custom migrator/registry, kapsamlı lifecycle FSM, purge, marketplace/ZIP **future/non-MVP**. Disabled → DOP UI/jobs/analysis kapalı; Composer paketi ve veri kalabilir.
- **İlgili:** `MODULE_LIFECYCLE.md`, `MODULE_CONTRACT.md`

## ADR-036 — Ayrı Result entity yok

- **Durum:** Accepted
- **Tarih:** 2026-08-07
- **Karar:** MVP’de `Result` domain entity zorunlu değildir. Akış Task ile biter. Sonuç sonraki Run’lar ve Finding lifecycle ile izlenir.
- **İlgili:** `MASTER_SPEC.md` §3 / §7.4, supersedes ADR-016 Result kısmı

## ADR-037 — MVP Core sade liste

- **Durum:** Accepted
- **Tarih:** 2026-08-07
- **Karar:** MVP Core zorunlu: auth/users/roles, customers/contacts, brands, digital assets, connections/credentials, minimal module registry, runs/evidence/findings/recommendations/tasks, basic settings/logs, events/queue/scheduler. Attachments, tags, feature flags, gelişmiş notification/audit/health framework’leri zorunlu değil.
- **İlgili:** `CORE_RESPONSIBILITIES.md`, supersedes ADR-020 zorunluluk kapsamı

## ADR-038 — PHPUnit implementation testing standard

- **Durum:** Accepted
- **Tarih:** 2026-08-07
- **Karar:** DOP application test standardı **PHPUnit**’dir. Bu karar ADR-021’in yalnızca Pest kısmını supersede eder. Laravel / Filament / diğer yığın kararları değişmez. Pest eklenmez; framework-native mevcut PHPUnit yapısı korunur.
- **İlgili:** `MASTER_SPEC.md` §12, `composer.json`, `tests/`, supersedes ADR-021 Pest satırı

## ADR-039 — Central Agency Integration, External Resource and Asset Binding Architecture

- **Durum:** Accepted
- **Tarih:** 2026-08-08
- **Bağlam:** MoxDOP SaaS değildir; tek ajans (Moximu) kendi müşteri portföyünü yönetir. Client portal, tenant-specific OAuth ve müşteri başına tekrarlanan Google/Meta authorize akışları ürün modeline aykırıdır. Mevcut `CoreConnection` hem asset-scoped site credential’ları (WordPress) hem provider-level kimlikleri aynı tabloda tutuyordu; bu ayrım netleştirilmelidir.
- **Karar:**
  1. **Authenticate once at agency level, bind many resources to Digital Assets.**
  2. **Integration (`core_integrations`)** — Moximu’nun bir external provider’a (Google, Meta, DataForSEO, OpenAI, …) yaptığı merkezi bağlantı. Credential ownership Integration’dadır; Customer/Brand/Asset başına tekrarlanmaz. Provider başına en fazla bir Integration (unique `provider`).
  3. **Integration credentials (`core_integration_credentials`)** — Secret’lar Laravel encryption / encrypted cast ile saklanır (ADR-027 deseni). Secret resource veya binding metadata’ya kopyalanmaz; UI’da write-only; log/API/screenshot’a plaintext sızmaz. Credential semantics refined by **ADR-040** (provider/application vs authorization token rows).
  4. **External Resource (`core_external_resources`)** — Integration üzerinden discover edilen provider-side gerçek kaynak (GSC property, GA4 property, Ads customer, GBP location, Meta ad account, Page, IG account, …). Kullanıcı normal akışta external ID elle yazmaz. `(integration_id, resource_type, external_id)` unique.
  5. **Binding (`core_asset_bindings`)** — Digital Asset ↔ External Resource eşlemesi. Credential taşımaz; yalnızca hangi DOP asset’inin hangi provider resource’una karşılık geldiğini söyler. `(digital_asset_id, external_resource_id)` ve `(digital_asset_id, capability)` unique.
  6. **Collector** — Credential değildir. Integration + Binding/Resource + collection config ile read-only veri çeken application service/job’dır. Kullanıcı UI’dan “collector bağlama”; Integration kurar ve resource seçerek Binding oluşturur. Bu ADR collector/scheduling implementasyonu zorunlu kılmaz.
  7. **Agency-scoped vs asset-scoped auth:**
     - Agency/provider: Google, Meta, DataForSEO, OpenAI → Integration.
     - Asset-scoped: WordPress application password, site-specific CMS credentials → mevcut `CoreConnection` (+ `core_connection_credentials`) kalır.
  8. **External integrations remain READ-ONLY** (ADR-018). Campaign/site/account mutation yok.
  9. **Digital Asset hierarchy değişmez** (Customer → Brand → Digital Asset; ADR-017).
  10. **Resource discovery** provider Integration üzerinden, test edilebilir `DiscoversProviderResources` contract’ı ile yapılır. Bu foundation ADR’si live OAuth/discovery’yi zorunlu kılmaz.
  11. **Disabled Integration:** Yeni discovery/collection durur; mevcut External Resource ve Binding kayıtları otomatik silinmez; secret purge edilmez (ADR-014 ile uyumlu).
  12. **Disconnect / delete Integration:** Binding’ler ve External Resource catalog cascade ile gider; Digital Asset silinmez. Asset-scoped `CoreConnection` kayıtları etkilenmez.
  13. **Compatibility (non-destructive):** Mevcut provider-type `CoreConnection` satırları bu milestone’da destructive migrate edilmez. WordPress/site credentials Connection’da kalır. Provider-level Connection satırları transitional kabul edilir; güvenli migration sonraki Google/Meta Integration milestone’larında Binding + Integration’a taşınabilir. Compatibility helper hangi type’ların asset-scoped / provider-level olduğunu sınıflandırır.
  14. **Security / audit:** Integration credential yönetimi Admin-only. Unauthorized kullanıcılar policy/canAccess ile engellenir. Exception mesajları secret içermez.
  15. **Extensibility:** Küçük canonical provider/capability registry yeterlidir; marketplace/plugin engine yok.
- **İlgili:** ADR-015, ADR-017, ADR-018, ADR-027, ADR-037, ADR-040; `docs/product/CONNECTION.md`; `MASTER_SPEC.md` §7.1; Settings → Integrations UI

## ADR-040 — Integration provider vs authorization credentials (Admin-managed)

- **Durum:** Accepted
- **Tarih:** 2026-08-08
- **Bağlam:** MoxDOP internal single-agency sistemdir. Normal provider/application credentials (OAuth Client ID/Secret, Ads developer token, API keys) Admin Panel’den yönetilmelidir; yalnızca `.env` düzenlemek operasyonel varsayılan olamaz. Mevcut tek `core_integration_credentials` satırı OAuth access/refresh token tutuyordu; Disconnect satırı sildiği için Client Secret/developer token aynı payload’a eklenemezdi.
- **Karar:**
  1. **Two credential categories on Integration:**
     - **Provider / application** (`credential_type = provider`) — Admin-configured Client ID/Secret, developer tokens, API keys. Persist across Disconnect / re-authorize.
     - **Authorization** (`credential_type = authorization`) — OAuth-generated access/refresh tokens + expiry. Never manually editable; never shown in Filament.
  2. **Uniqueness:** `(integration_id, credential_type)`. Existing rows migrate/default to `authorization` (backwards compatible).
  3. **Google resolution precedence** via `GoogleCredentialResolver`: (1) DB provider credential, (2) env/config fallback, (3) missing. Env secrets are not auto-copied into DB. UI may show “Configured by environment” without revealing values.
  4. **Disconnect Google account** clears/revokes authorization tokens only; preserves provider credentials, Integration, and historical resources/bindings (ADR-039 unavailable semantics).
  5. **Remove provider configuration** is a separate Admin-only destructive action with confirmation.
  6. **Redirect URI** is not a secret; show/copy from Integration page; keep optional env override.
  7. **`GOOGLE_ADS_API_VERSION`** remains deployment/system config (not an Admin secret field).
  8. **Generic enough for Meta / DataForSEO / OpenAI provider secrets later**; this ADR does not implement those forms.
  9. **`APP_KEY` and infrastructure secrets stay environment-managed.**
- **İlgili:** ADR-027, ADR-039; `docs/product/GOOGLE_INTEGRATION_SETUP.md`; Settings → Integrations → Google

## ADR-041 — OpenAI agency Integration credentials (supersedes ADR-030 key management only)

- **Durum:** Accepted
- **Tarih:** 2026-08-09
- **Bağlam:** ADR-030 AI API key’i environment-only yönetmeyi söylemişti. ADR-039 OpenAI’yi agency Integration provider olarak tanımladı; ADR-040 Admin-managed provider credentials + DB → env fallback’i kurdu. Bu çelişki production AI kullanımından önce çözülmelidir.
- **Karar:**
  1. **ADR-030’un yalnızca OpenAI/API-key yönetim cümlesi supersede edilir.** ADR-030’un geri kalanı korunur: `laravel/ai` kullanılır; provider abstraction; MCP yok; vector DB yok; multi-agent orchestration yok.
  2. **OpenAI API key resolution (canonical):** (1) encrypted Integration provider credential (`core_integration_credentials`, `credential_type = provider`), (2) optional env/config fallback (`OPENAI_API_KEY` / `moxdop.openai.api_key`), (3) missing.
  3. Settings → Integrations → OpenAI is the Admin UX (Configure / Test connection / Remove). No per-Brand or per-Website keys. No generic Credentials JSON for OpenAI secrets.
  4. Secret rules: encrypted at rest; write-only; blank edit preserves; explicit clear/remove only; never HTML/Livewire plaintext/logs/exceptions/Run metadata/Evidence/prompt/fingerprint.
  5. **`APP_KEY` and infrastructure config remain environment-managed.**
  6. Test connection uses a non-generative authentication check (OpenAI models list). Generation requests set OpenAI `store = false` where supported.
  7. Website AI recommendation intelligence is advisory: grounded structured interpretation → draft → human acceptance → Recommendation → manual Task. No AI Finding creation, no automatic Task, no tools/MCP/web search.
- **İlgili:** ADR-023, ADR-030 (partial supersede), ADR-039, ADR-040; `MASTER_SPEC.md` §11; `docs/product/website/AI_INSIGHTS.md`; Settings → Integrations → OpenAI

---

## ADR-042 — Google Analytics as first-class Digital Asset (Evidence-provider role retained)

- **Durum:** Accepted
- **Tarih:** 2026-08-13
- **Bağlam:** Earlier product docs (ADR-017 lineage / MASTER_SPEC §4 / `DIGITAL_ASSET.md` / `website/GA4.md`) treated GA4 primarily as a **Website Connection** — useful for Evidence, but not as a managed Digital Asset with its own workspace. Operators need a Measurement Intelligence workspace (Property identity, Data Streams, business-action mapping, acquisition hygiene, journeys, Findings → Outcomes) while GA4 continues to supply measurement Evidence to Website and Ads analysis.
- **Karar:**
  1. **Ontology split (keep separate):** Digital Asset = *what managed system/property*; Relationship = *role relative to another Asset*; Connection = *how MoxDOP technically accesses the provider*; Capability = *what analysis MoxDOP performs on Evidence*.
  2. **Google Analytics (GA4) is a first-class Digital Asset type** in the product/Demo registry. Canonical type key remains **`ga4`** (UI label: Google Analytics; secondary: GA4). Do **not** introduce parallel keys (`google_analytics`, `analytics`) for the same concept.
  3. **Evidence-provider role is complementary, not exclusive.** GA4 may *measure* Website (and future App streams) and *provide measurement / post-click Evidence* to Google Ads / Meta Ads without becoming a child of those Assets. Sibling Brand Digital Assets remain the ownership model.
  4. **Technical Connection ≠ relationship.** Google OAuth / Analytics API connectivity is separate from “measures Website” / “provides Evidence to Ads.”
  5. **Capability truth / migration honesty:** Demo Mode and product IA treat GA4 as first-class. **Existing real Website-scoped GA4 collection/provider architecture is not rewritten by this decision.** Do not duplicate provider collection or create competing GA4 data stores. Full Binding migration of live collectors is a later concern when explicitly tasked.
  6. **Read-only externally.** No GA4 write (Key Events, streams, attribution, cross-domain, Ads linking, Consent Mode, GTM). Internal MoxDOP business-action mapping is allowed and does not edit the GA4 property.
  7. **Visual identity:** Provider marks for Digital Assets are centralized (`DigitalAssetVisualCatalog` + local SVG assets). Website prefers Brand/site identity; provider-owned systems use recognizable local marks — no remote logo CDN for core UI.
- **İlgili:** ADR-017, ADR-018, ADR-039; `MASTER_SPEC.md` §4; `docs/product/DIGITAL_ASSET.md`; `docs/product/website/GA4.md`; Demo GA4 Measurement Intelligence workspace

---

## ADR-043 — Google Search Console as first-class Digital Asset (Evidence-provider role retained)

- **Durum:** Accepted
- **Tarih:** 2026-08-13
- **Bağlam:** Earlier product docs treated Search Console primarily as a **Website Connection**. Operators need an Organic Demand & Search Intelligence workspace (property identity, search performance, topic clusters, search ownership, discoverability funnel, indexing reconciliation, Findings → Outcomes) while GSC continues to supply organic Evidence to Website, Google Ads, GBP, and GA4 analysis.
- **Karar:**
  1. Preserve ADR-042 ontology split: Asset / Relationship / Connection / Capability.
  2. **Google Search Console is a first-class Digital Asset type** in the product/Demo registry. Canonical type key remains **`gsc`** (UI: Google Search Console). Do not introduce parallel keys for the same concept.
  3. Relationship example: GSC *observes organic search performance for* Website. Evidence may be consumed by Website Content Intelligence, paid/organic review, GBP local opportunity analysis, and Brand demand intelligence — without making GSC a child of Website.
  4. Technical Connection (Google Integration / OAuth → Search Console property) remains separate from the observes relationship.
  5. **Capability truth:** Demo/product IA treats GSC as first-class. **Existing real Website-scoped Search Console collectors are not rewritten by this decision.** No duplicate GSC data stores. No Search Console writes, Indexing API generic submission, SERP scraping, or live rank-tracking expansion in this decision.
  6. Product truth: no fake SEO scores; missing ≠ zero; observed queries ≠ all keywords; average position ≠ GBP local rank; no query→conversion false attribution from aggregates.
  7. Visual identity reuses `DigitalAssetVisualCatalog` (local `gsc` mark).
- **İlgili:** ADR-017, ADR-018, ADR-039, ADR-042; `MASTER_SPEC.md` §4; `docs/product/DIGITAL_ASSET.md`; `docs/product/website/SEARCH_CONSOLE.md`; Demo Search Console Organic Demand workspace

---

## ADR-044 — Canonical operator routes and Filament admin path (supersedes ADR-026 path only)

- **Durum:** Accepted
- **Tarih:** 2026-08-19
- **Bağlam:** ADR-026 placed the single Filament panel at `/app` and treated that panel as the application UI. The release integration trunk (`cursor/production-readiness-audit-ea01`) already ships a different, live architecture: TailAdmin Livewire operator product on root routes, Filament as technical/admin tooling at `/admin`, and retired `/app` + `/system` prefixes. Canonical docs lagged the code. This ADR records the live architecture; it is not a UI redesign.
- **Karar:**
  1. **Operator product** lives on root application routes of the product host (`app.moximu.com` in production): `/`, `/login`, `/customers`, `/brands`, `/assets`, `/integrations`, `/activity`, `/findings`, `/recommendations`, `/tasks`, `/settings`, `/profile`, and the other TailAdmin Livewire surfaces. One normal application. Do not duplicate Customers/Brands/Assets under Filament as a second operator product. Do not advertise Filament as “Back-office” in the product UI.
  2. **Technical/admin Filament** remains a single panel, id `app`, path **`/admin` only**. Auth/RBAC from ADR-026 is unchanged: Laravel `web` session guard; no public registration; Admin creates users; password reset and profile; roles Admin / Team Member; `spatie/laravel-permission`; no multi-tenancy or customer guard.
  3. **Legacy prefixes** `/app/*` and `/system/*` are retired. Existing release behavior is **HTTP 410** (not a product redirect). There is no parallel `/app` or `/system` operator product.
  4. Named operator routes, OAuth callbacks (`{APP_URL}/integrations/google/callback`, `{APP_URL}/integrations/meta/callback`), and operator login (`/login`) stay on the product origin. Filament login is `/admin/login` and is not the operator sign-in.
  5. Staging/production require HTTPS (`APP_URL` https + `APP_FORCE_HTTPS` + `SESSION_SECURE_COOKIE`). PostgreSQL is the production/staging database; SQLite remains local/dev/PHPUnit only. Queue on staging/production is Redis + Laravel Horizon; local/PHPUnit may use `database`/`sync`. `php artisan moxdop:production-check` is the local/CI production-readiness gate.
- **İlgili:** ADR-026 (path only); `MASTER_SPEC.md` §6 / §12; `AGENTS.md`; `routes/web.php`; `AppPanelProvider`

---

## ADR-045 — WordPress inside truth + Public Discovery outside truth

- **Durum:** Accepted
- **Tarih:** 2026-08-29
- **Bağlam:** Public `/wp-json/wp/v2` envanteri, CMS’in yetkili iç durumunu temsil etmiyor; yalnız connector ise gerçek HTTP, redirect ve yayınlanan final HTML’i kanıtlayamıyor. Entegrasyon ekranındaki collection state ile Website varlığındaki diagnosis çıktısı da karışmamalı.
- **Karar:**
  1. WordPress V1, Website asset-scoped `CoreConnection` + encrypted credential ile gerçek, kurulabilir, read-only plugin connector olarak çalışır.
  2. Connector CMS iç gerçeğini toplar. Public Discovery, WordPress sitelerde kaldırılmaz; dış HTTP/HTML doğrulama katmanı olarak aynı Website collection planında kalır. WordPress olmayan siteler public family’lerle çalışır.
  3. Connector pairing tek kullanımlık hash-stored code ve iki yönlü HMAC-SHA256 imza kullanır. Write REST route, user/password/comment ve media binary collection yoktur.
  4. Integration surface yalnız bağlantı, collection progress/history, dataset ve raw/normalized record truth gösterir. Finding/Recommendation/Task burada üretilmez veya sunulmaz.
  5. Website Digital Asset analysis tamamlanmış WordPress/Public DatasetRun’larını birleştirir; connector ayarı ile published HTML farkları deterministik Finding olabilir. GA4/GSC davranış/platform Evidence’i mevcut akışlardan eklenir. Recommendation grounded, Task dönüşümü manueldir.
  6. Connector code/test completion live WordPress UAT veya production deploy anlamına gelmez.
- **İlgili:** ADR-012, ADR-017, ADR-018, ADR-027, ADR-034, ADR-039; `docs/product/website/WORDPRESS.md`; `PRODUCT_CAPABILITY_LEDGER.md`

---


## ADR-046 — MoxDOP Intelligence Core: provider-neutral identity and provenance layer

- **Durum:** Accepted
- **Tarih:** 2026-08-31
- **Bağlam:** Website, WordPress, GSC, GA4, Ads, GBP ve DataForSEO verilerinin ekranlarda farklı kurallarla doğrudan birleştirilmesi; ikinci bir veri ambarı, belirsiz kimlik eşleştirmesi ve yeni sağlayıcıda yeniden yazım riski oluşturur.
- **Karar:**
  1. Intelligence Core, sağlayıcı fact tablolarının üzerinde çalışan ortak **kimlik + provenance + metrik sözleşmesi + capability** katmanıdır. Provider fact tabloları canonical truth olarak kalır; generic EAV/metrik ambarına kopyalanmaz.
  2. Ortak kimlikler Page/URL, Search Term, Entity ve Business Action’dır. Zaman/market/language/device/surface/model/sampling bağlamı ile provider/dataset/record/asset/resource/run/contract provenance korunur.
  3. URL normalizasyonu scheme, `www`, path case ve trailing slash farklılıklarını otomatik birleştirmez. Eşdeğerlik redirect, canonical, CMS permalink, rule veya operator kanıtı gerektirir.
  4. Search term canonical kimliği diacritics korur; folded metin yalnız clustering içindir. GSC query, Ads search term/keyword, DataForSEO/GBP keyword ve gelecekteki AI query kaynak anlamları ayrı tutulur.
  5. Missing ≠ zero; estimated ≠ measured; platform signal ≠ verified business outcome. Registry dışı metric/formula veya magic score oluşturulmaz.
  6. Page/Search Term/Entity/Outcome profilleri rebuildable Projection katmanlarıdır; bu ADR onları uygulanmış saymaz. Yeni kaynaklar capability adapter ekler.
  7. Mevcut Formula Registry, Evidence Definitions, Canonical Evidence ve Finding Rules tek deterministik teşhis zinciridir. Paralel Evidence/Finding sistemi kurulmaz; AI Finding/Task yaratmaz ve harici write yasağı sürer.
- **İlgili:** ADR-007, ADR-018, ADR-023, ADR-034, ADR-036, ADR-039, ADR-045; `resources/intelligence/MOXDOP_INTELLIGENCE_CORE_V1.json`

---

## ADR-047 — Website Intelligence Projection as a rebuildable source-keyed read model

- **Durum:** Accepted
- **Tarih:** 2026-08-31
- **Bağlam:** Website public/HTML, authenticated WordPress, GSC ve GA4 verilerini her Website sekmesinde doğrudan join etmek; farklı URL/term anlamlarını karıştırır, provider eklenince ekranları yeniden yazdırır ve ikinci bir canonical data warehouse yaratma riski doğurur.
- **Karar:**
  1. Website Projection, ADR-046 kimlikleri üzerinde dört rebuildable read profile üretir: Page, Search Term, Entity ve Outcome. Provider fact tabloları canonical truth olarak kalır.
  2. Her profil source-keyed typed state JSON taşır. Bu yapı generic EAV metric warehouse değildir; sadece domain-specific identity read model'idir. Yeni provider yeni adapter/source state ekler, tablo grain'i değişmez.
  3. İlk adapter seti Website direct observation, WordPress CMS authenticated snapshot, confirmed-binding GSC ve confirmed-binding GA4'tür. Binding yoksa `not_configured`, veri yoksa `not_collected`; ikisi de numeric zero değildir.
  4. Default projection window son tamamlanmış 90 UTC gündür. Her source kendi period/coverage/watermark/provenance bilgisini taşır. GSC average position impression-weighted hesaplanır ve rank tracker değildir; provider row limits belirtilir.
  5. WordPress CMS state ve published visitor HTML aynı Page identity üzerinde ayrı source state kalır. Full raw HTML private ingestion artifact'tadır; profile hash/reference taşır.
  6. GA4 Key Event yalnız explicit Business Action mapping ile Outcome profile'a girer ve provider-attributed signal olarak kalır; operator-verified business outcome'a otomatik yükseltilmez.
  7. Terminal Website/GSC/GA4 collection run ilgili Website rebuild job'ını kuyruğa alır. Source adapter failure projection'ı partial yapar ve önceki başarılı source state'i korur; tam başarılı rebuild stale profile'ları temizler.
  8. Formula/Evidence/Finding/Recommendation/manual Task hattı değişmez. Projection Finding üretmez, AI çalıştırmaz ve provider write yapmaz.
- **İlgili:** ADR-039, ADR-042, ADR-043, ADR-045, ADR-046; `resources/intelligence/MOXDOP_INTELLIGENCE_CORE_V1.json`; `docs/product/website/WEBSITE.md`

---

## ADR-048 — Global Service Catalog and reusable Search Query Library

- **Durum:** Accepted
- **Tarih:** 2026-09-02
- **Bağlam:** Provider verilerini toplamış olmak, bir Brand'in hangi hizmetleri hangi bölgelerde öne çıkarmak istediğini açıklamaz. Her Brand için aynı sorguları yeniden DataForSEO'dan satın almak da ajans bilgisini tekrar kullanmaz. Öte yandan global sorgu kütüphanesini Brand-scoped Intelligence kimliği veya ikinci bir metrik ambarı yapmak ADR-046'yı bozar.
- **Karar:**
  1. Global Service Catalog ajans genelinde stable Service kimliği ve alias tutar. Mevcut Brand Offering, Brand-scoped canonical kimlik olarak kalır ve opsiyonel olarak katalog Service'ine bağlanır.
  2. Brand, birden fazla açık ülke/şehir/ilçe Service Area satırı taşır. Bu satırlar Brand Context'e projection olur; otomatik Service × Area Cartesian scope üretmez.
  3. Search Query Library ajans genelinde yeniden kullanılabilir sorgu kimliği ve kaynak kayıtlarını tutar. Manuel, dosya, Google Ads, GSC ve DataForSEO gözlemleri birbirini ezmez; provenance ve mevcut metrikler source record'da kalır.
  4. Query Library provider Evidence veya Brand-scoped Intelligence identity değildir. Bir sorgu Brand'e uygulandığında ADR-046 `IntelligenceSearchTermIdentity` hattına resolve edilir.
  5. AI sınıflandırma/clustering çıktısı adaydır. Confidence, rationale, abstention, model/skill version ve insan onayı olmadan operator truth veya URL ownership olmaz. SERP benzerliği daha sonraki doğrulama katmanıdır.
  6. Bu foundation provider çağrısı, external write, Finding, Recommendation veya Task üretmez.
- **İlgili:** ADR-023, ADR-034, ADR-039, ADR-046, ADR-047; `docs/product/SEARCH_DEMAND_INTELLIGENCE.md`

---

## ADR-049 — Search Demand AI proposals and human review boundary

- **Durum:** Accepted
- **Tarih:** 2026-09-03
- **Bağlam:** Sorgu üretimi, alias önerisi ve semantik sınıflandırma insan dil muhakemesi gerektirir; ancak AI çıktısını doğrudan global sorgu veya Service truth yapmak hatalı sınıflandırmayı kalıcı hale getirir. Uzun AI çağrısını request içinde çalıştırmak da operator async standardını ihlal eder.
- **Karar:**
  1. `Search Intelligence Analyst`, işlem başına yalnız gerekli generation veya classification Skill'i ile ve merkezi `search_demand.librarian` route'u üzerinden çalışır.
  2. AI çalışması kuyruklanır. Run ve candidate kayıtları Library truth'tan ayrı tutulur; queued/running/completed/failed ve pending/approved/rejected durumları açıkça persist edilir.
  3. Exact cache anahtarı input + Agent version + Skill signature/definition fingerprint + sanitized AI route/model signature'dır. Aynı tamamlanmış çalışma provider çağrısı yapılmadan yeniden kullanılır.
  4. Sorgu, alias ve semantik alanlar yalnız insan onayıyla uygulanır. Rejection ve abstention provenance olarak kalır; çekimser çıktı otomatik truth olmaz.
  5. AI metrik, SERP gözlemi veya ticari sonuç uyduramaz; Findings, Recommendations, Tasks, provider-spend, CMS veya başka external write üretemez. OpenAI `store=false` kalır.
- **İlgili:** ADR-018, ADR-023, ADR-039, ADR-048; `OPERATOR_ASYNC_EXECUTION.md`; `docs/product/SEARCH_DEMAND_INTELLIGENCE.md`

---

## ADR-050 — Relational Brand Query Portfolio and dynamic location expansion

- **Durum:** Accepted
- **Tarih:** 2026-09-03
- **Bağlam:** Global Library sorgusunu her Brand ve bölge için kopyalamak hem ajans bilgisini parçalar hem Service × Area Cartesian büyüme yaratır. Buna karşılık global, Brand ve Website kararlarını tek status alanına sıkıştırmak da farklı sahiplik kapsamlarını kaybettirir.
- **Karar:**
  1. Brand Query Portfolio global Library satırını foreign-key relation ile uygular; global query text kopyalanmaz. Brand-only sorgu normalized kendi kimliğini taşır.
  2. Brand query text/family/market/location/branded override'ları global satırı değiştirmeyen explicit operator facts'tir. Brand-only sorgunun globale önerilmesi yalnız submitted review state üretir.
  3. Default alan kapsamı tüm etkin Brand Service Areas'dır; gerekirse seçili-area relation kullanılır. `{location}` metinleri yalnız read/request anında genişletilir ve kalıcı Service × Area sorgu satırı yaratılmaz.
  4. Uygulanan her portfolio query mevcut Brand-scoped `IntelligenceSearchTermIdentity` resolver hattına bağlanır. Ayrı query identity warehouse kurulmaz.
  5. Website etkin/excluded durumu portfolio item ile Digital Asset arasındaki ayrı relation'dır; global, Brand ve Website scope birbirine karıştırılmaz.
- **İlgili:** ADR-039, ADR-046, ADR-048, ADR-049; `docs/product/SEARCH_DEMAND_INTELLIGENCE.md`

---

## ADR-051 — Human-governed layered Search Demand clusters

- **Durum:** Accepted
- **Tarih:** 2026-09-03
- **Bağlam:** Talep ailesi, benzer SERP niyeti ve aynı içerik/URL hedefi eş anlamlı değildir. Bunları tek AI etiketi yapmak URL sahipliğini kanıtsız varsayar; yeni sorgular geldiğinde bütün kümeleri yeniden yazmak da insan kararlarını ve stabil kimlikleri kaybettirir.
- **Karar:**
  1. Brand Query Portfolio öğeleri Brand-scoped, stabil Search Demand Cluster kimliklerine membership ile bağlanır. Talep ailesi, SERP intent group ve content target cluster ayrı alanlardır.
  2. AI clustering queued run ve pending candidate üretir. Incremental mod yalnız kümelenmemiş etkin sorguları alır; mevcut kümeyi değiştiren move/merge/split/update önerileri yalnız explicit review modunda oluşur.
  3. AI önerisi insan onayı olmadan kümeyi değiştirmez. Lock edilmiş küme ve üyeleri değiştirilemez; operator unlock, move, merge ve split işlemleri de aynı servis sınırlarını kullanır.
  4. Her yapısal karar küme sürümünü artırır ve üye ID'leri dahil snapshot saklar. Exact tekrar kullanımı input + mevcut cluster state + Agent + Skill fingerprint + provider/model route imzasına bağlıdır.
  5. SERP observation sağlanmadıkça validation state `ai_prediction` kalır. `serp_validated`, `serp_conflict` ve `review_required` daha sonraki gözlemsel doğrulama içindir; confidence performans metriği veya URL ownership kanıtı değildir.
  6. Clustering Agent metrik, Finding, Recommendation, Task, içerik, redirect, provider-spend veya external write üretemez.
- **İlgili:** ADR-018, ADR-023, ADR-046, ADR-049, ADR-050; `OPERATOR_ASYNC_EXECUTION.md`; `docs/product/SEARCH_DEMAND_INTELLIGENCE.md`

---

## ADR-052 — Read-only Query–URL Visibility Map over canonical facts

- **Durum:** Accepted
- **Tarih:** 2026-09-03
- **Bağlam:** Search Demand sorgularını GSC, GA4 ve Website HTML verisiyle ilişkilendirmek gerekir; ancak performansı portfolio veya cluster tablolarına kopyalamak ikinci bir warehouse ve çelişen truth üretir. GA4 landing performansını sorguya atfetmek de kaynak grain'ini aşan yanlış bir iddiadır.
- **Karar:**
  1. Visibility Map yalnız Website'te active edilmiş Brand Query Portfolio item'larını okur ve canonical `IntelligenceSearchTermIdentity` alias'ları üzerinden GSC query text gözlemlerini eşler.
  2. Query–URL ilişkisi, clicks, impressions, CTR ve average position explicit dönem için `gsc_query_page_daily` kaynağından okunur. Provider limitleri ve position semantiği korunur.
  3. URL identity, HTTP/robots/HTML coverage mevcut `IntelligencePageIdentity` ve `WebsitePageProfile` projection'ından okunur; yeni URL identity veya metrics warehouse kurulmaz.
  4. GA4 landing sessions/engaged sessions Page grain olarak gösterilir ve query attribution sayılmaz.
  5. Period comparison yalnız iki tarafta gözlem varsa delta üretir. GSC satırı olmayan aktif sorgu `unobserved` olur; missing/unavailable değer sıfıra çevrilmez.
  6. Bu yüzey read-only'dir; SERP validation, URL ownership kararı, Finding, Recommendation, Task veya external write üretmez.
- **İlgili:** ADR-039, ADR-046, ADR-047, ADR-050, ADR-051; `docs/product/SEARCH_DEMAND_INTELLIGENCE.md`

---

## ADR-053 — Manual paid SERP observations and human-applied cluster validation

- **Durum:** Accepted
- **Tarih:** 2026-09-03
- **Bağlam:** Search Demand kümelerini gerçek sonuç örtüşmesiyle sınamak, marka konumunu gözlemek ve sorgulara market tahmini eklemek gerekir. Bunu Brand oluşturma veya sayfa render'ına bağlamak kontrolsüz maliyet; DataForSEO hacmini GSC gerçeğiyle birleştirmek yanlış ölçüm; SERP sonucundan doğrudan URL sahibi veya rakip kaydı üretmek ise Faz 8/9 karar sınırlarını ihlal eder.
- **Karar:**
  1. SERP enrichment sağlayıcıdan bağımsız adapter sözleşmesi arkasında, yalnız operator tarafından hizmet veya küme scope'u ile başlatılan queued run'dır. Website SEO location/language zorunlu, device ve 10/20 depth explicit, sorgu sayısı 20 ile sınırlıdır.
  2. Her paid POST için result-affecting fingerprint, freshness reuse ve concurrent lock uygulanır. HTTP öncesi durable attempt marker yazılır; response/fact commit ispatlanamazsa `CHARGE_UNKNOWN` olur ve `tries=1` nedeniyle otomatik retry yapılmaz.
  3. SERP snapshot ilk organik URL'leri, SERP features, Brand-domain rank/URL, market/device context, task/fingerprint ve retrieval provenance ile saklar. Bu gözlem URL ownership veya competitor-library write değildir.
  4. Search volume, CPC, competition ve monthly trend `provider_estimate` olarak ayrı snapshot'tır; GSC/GA4 measured değerleriyle birleştirilmez. Eksik değer sıfır değildir. Pre-call USD estimate yalnız deployment configuration varsayımıdır; provider-reported cost ayrı tutulur.
  5. Optional Keyword Ideas sonucu pending candidate'dır. Yalnız insan onayı Brand Portfolio query oluşturup Website'te etkinleştirebilir; otomatik cluster/global library/Finding/Task üretmez.
  6. İlk on exact organic URL üzerinde pairwise Jaccard ortalaması threshold provenance ile validation recommendation üretir. `serp_validated`, `serp_conflict` veya `review_required` cluster state'i yalnız operator approval ile uygulanır. Üyelik ve URL ownership değişmez.
- **İlgili:** ADR-018, ADR-039, ADR-046, ADR-050, ADR-051, ADR-052; `OPERATOR_ASYNC_EXECUTION.md`; `docs/product/SEARCH_DEMAND_INTELLIGENCE.md`

---

## ADR-054 — Fail-closed URL eligibility and human-owned Page Relevance decisions

- **Durum:** Accepted
- **Tarih:** 2026-09-04
- **Bağlam:** Bir Search Demand içerik kümesini Website URL'sine bağlamak gerekir; ancak GSC'de görünen veya SERP'te sıralanan URL'yi otomatik hedef kabul etmek intended ownership ile observed visibility'yi karıştırır. İki URL'nin görünmesi de tek başına cannibalization kanıtı değildir. AI'nin teknik olarak uygunsuz sayfayı önermesi veya redirect/silme uygulaması insan karar sınırını ihlal eder.
- **Karar:**
  1. URL ownership, Website + content-target-cluster grain'inde tek, sürümlü ve insan yönetimli karardır. Kilitli insan kararı yeni analizlerle otomatik değişmez.
  2. Adaylar mevcut Website Page Projection'dan gelir ve bir review'da 20 ile sınırlıdır. GSC dönem gözlemi, saklı SERP Brand URL'si, mevcut owner ve semantik ön seçim ayrı provenance olarak kalır; performans ownership tablolarına kopyalanan yeni warehouse olmaz.
  3. Teknik kapı fail-closed çalışır: aynı Website, public gözlem, 2xx HTTP, observed `noindex` yokluğu, başka URL'ye canonical olmama, observed dil eşleşmesi ve izinli içerik URL türü gerekir. Missing kanıt `unknown` olur; yalnız `eligible` URL önerilebilir veya doğrulanabilir.
  4. İki dönem arasında GSC lider URL değişimi veya configured dominance eşiğinin altındaki parçalanma yalnız wrong-URL/cannibalization review candidate üretir. Algoritma cannibalization veya hangi kararın yanlış olduğunu kesinleştirmez.
  5. Page Relevance AI queued ve exact-fingerprint cached evidence pack üzerinde yalnız semantik uyum yorumlar; en fazla bir eligible owner önerir veya abstain eder. AI çıktısı ownership truth değildir.
  6. İnsan onayı anlık teknik kapıyı tekrar çalıştırır, evidence snapshot ve version kaydeder ve isteğe bağlı kilit uygular. Redirect, delete, merge, page/content creation, Finding, Recommendation, Task, provider spend ve external write otomatik değildir.
- **İlgili:** ADR-018, ADR-023, ADR-046, ADR-047, ADR-050, ADR-051, ADR-052, ADR-053; `OPERATOR_ASYNC_EXECUTION.md`; `docs/product/SEARCH_DEMAND_INTELLIGENCE.md`

---

## ADR-055 — Brand-scoped Competitor Library with observation-only discovery

- **Durum:** Accepted
- **Tarih:** 2026-09-04
- **Bağlam:** SERP'te aynı sorguda görünen domain ticari rakip, içerik rakibi, dizin, platform veya otorite sitesi olabilir. DataForSEO sonucunu doğrudan ticari rakip yapmak observed search competition ile operator-owned business knowledge'ı karıştırır. Rakip URL/sorgu ilişkilerini tek JSON listesine koymak da provenance ve sonraki sayfa seçimini kaybettirir.
- **Karar:**
  1. Competitor Library kimliği Brand + normalized domain'dir. Host lower-case olur ve `www` aynı domain'e katlanır; diğer subdomain'ler otomatik birleşmez. Lifecycle `pending`, `approved`, `rejected` olarak ayrı tutulur.
  2. Commercial, SERP ve content competitor rolleri bağımsızdır. Business, directory, platform ve authority-site kind ayrı sınıflandırmadır; `unknown` insan kararı gelene kadar geçerlidir.
  3. Faz 7 SERP sonuçları ve mevcut `dataforseo_competitor_domain_snapshot` satırları yalnız saklı gözlemden, operator action ile ve 100 distinct domain sınırında candidate üretir. Faz 9 importer provider çağrısı yapmaz ve maliyet oluşturmaz.
  4. DataForSEO gözlemi `is_serp_competitor` sinyalini destekler; `is_commercial_competitor` veya `is_content_competitor` alanını otomatik kanıtlamaz. Pending aday ancak insan onayıyla approved olur; rejection source provenance'ı silmez.
  5. Source, Website, provider record, observation time ve mevcut market/query/rank bağlamı ayrı source satırında kalır. Rakip URL'leri, göründüğü Brand Portfolio sorguları ve Service/Brand Service Area/content-target-cluster ilişkileri relational tutulur; kalıcı Service × Area Cartesian scope yaratılmaz.
  6. Manuel ekleme explicit human approval'dır. Faz 9 competitor HTML toplamaz, AI çalıştırmaz, page intent sınıflandırmaz, Finding/Recommendation/Task üretmez ve external write yapmaz. Bunlar Faz 10–12 sınırıdır.
- **İlgili:** ADR-018, ADR-023, ADR-039, ADR-046, ADR-048, ADR-050, ADR-051, ADR-053, ADR-054; `docs/product/SEARCH_DEMAND_INTELLIGENCE.md`

---

## ADR-056 — Bounded exact-URL competitor page collection and reusable history

- **Durum:** Accepted
- **Tarih:** 2026-09-04
- **Bağlam:** Rakip sayfaların başlık, yapı, şema, bağlantı ve hizmet/lokasyon ifadelerini gözlemek gerekir. Ancak SERP'te görülen birkaç URL'den tüm rakip sitesini taramaya geçmek gereksiz trafik ve kontrolsüz kapsam yaratır. Aynı HTML'i her çalışmada tekrar parse edip tam normalize içeriği kopyalamak da geçmişi şişirir.
- **Karar:**
  1. Faz 10 yalnız approved Competitor Library kayıtlarının operator-selected content-target cluster ile ilişkili URL'lerini toplar. Seçim URL hash'iyle tekilleştirilir; rakip başına 3, run başına 20 URL ile sınırlıdır.
  2. Public Discovery'nin SSRF-safe HTTP fetcher'ı aynen kullanılır: her redirect public-IP kontrolünden geçer, timeout/response-size sınırları korunur ve credential gönderilmez. Final URL approved competitor domain'i dışına çıkarsa gözlem fail-closed olur.
  3. Yalnız seçilmiş exact URL'ler istenir. Sayfadaki iç/dış bağlantılar yapı gözlemi olarak saklanır ama takip edilmez; rakip site çapında crawl, robots veya sitemap expansion yapılmaz.
  4. Değişen sayfa görünür normalize metin, title/meta, H1–H6, JSON-LD schema summary, bounded iç/dış links ve operator-owned service/location sözlüğünden deterministic expression match üretir.
  5. Her deneme append-only gözlem geçmişidir. Raw HTML hash aynıysa parsing atlanır; normalize content fingerprint aynıysa yeni satır önceki content observation'a referans verir ve içerik alanlarını kopyalamaz. Failed/unchanged zamanları da last-observed history olarak kalır.
  6. İşlem canonical async Run/Activity hattında yürür. Faz 10 AI analizi, intent classification, Finding, Recommendation, Task, provider spend veya external write yapmaz; bunlar daha sonraki insan-gated fazlardır.
- **İlgili:** ADR-013, ADR-018, ADR-023, ADR-045, ADR-046, ADR-053, ADR-055; `OPERATOR_ASYNC_EXECUTION.md`; `docs/product/SEARCH_DEMAND_INTELLIGENCE.md`

---

## Karar indeksi

| ID | Başlık | Durum |
|----|--------|--------|
| ADR-001 | Eski ürün tanımı | Superseded → 015 |
| ADR-002 | Eski akış | Superseded → 016 |
| ADR-003 | Eski hiyerarşi (+Workspace) | Superseded → 017 |
| ADR-004 | Modular monolith | Accepted |
| ADR-005 | Eski deploy belirsizliği | Superseded → 021/022 |
| ADR-006 | Eski çekirdek listesi | Superseded → 020 |
| ADR-007 | Çekirdek bilmeme | Accepted |
| ADR-008 | Modül kuralları | Accepted |
| ADR-009 | İzolasyon | Accepted |
| ADR-010 | Modül sınıfları | Accepted |
| ADR-011 | Tek ilk modül | Superseded → 024 |
| ADR-012 | Diagnosis connector zorunlu değil | Accepted |
| ADR-013 | Background jobs | Accepted |
| ADR-014 | Disable/veri | Accepted |
| ADR-015 | Moximu iç sistem | Accepted |
| ADR-016 | Eski akış (+Result) | Superseded → 036 / 034 |
| ADR-017 | Asset/Connection; no Workspace | Accepted |
| ADR-018 | Harici write yasağı | Accepted |
| ADR-019 | MVP kullanıcı modeli | Accepted |
| ADR-020 | Eski çekirdek listesi | Superseded → 037 |
| ADR-021 | Teknoloji yığını | Accepted |
| ADR-022 | Yerel paketleme | Accepted |
| ADR-023 | AI sınırı | Accepted |
| ADR-024 | İlk modül seti | Accepted |
| ADR-025 | Manuel Task dönüşümü | Accepted |
| ADR-026 | Panel + auth | Accepted (path superseded → ADR-044) |
| ADR-027 | Connection/credential | Accepted |
| ADR-028 | Eski analysis alanları | Superseded → 034 |
| ADR-029 | Task snapshot | Accepted |
| ADR-030 | laravel/ai + env key | Accepted (key mgmt superseded by ADR-041) |
| ADR-031 | Diagnosis catalog kapısı | Accepted |
| ADR-032 | app-modules + internachi/modular | Accepted |
| ADR-033 | Framework’ü tekrar yazma | Accepted |
| ADR-034 | Finding kalıcı lifecycle | Accepted |
| ADR-035 | MVP minimal module registry | Accepted |
| ADR-036 | Result entity yok | Accepted |
| ADR-037 | MVP Core sade liste | Accepted |
| ADR-038 | PHPUnit test standardı | Accepted |
| ADR-039 | Central Agency Integration / External Resource / Binding | Accepted |
| ADR-040 | Integration provider vs authorization credentials | Accepted |
| ADR-041 | OpenAI agency Integration credentials | Accepted |
| ADR-042 | GA4 first-class Digital Asset + Evidence role | Accepted |
| ADR-043 | GSC first-class Digital Asset + Evidence role | Accepted |
| ADR-044 | Canonical operator routes + Filament `/admin` | Accepted |
| ADR-045 | WordPress inside truth + Public Discovery outside truth | Accepted |
| ADR-046 | Provider-neutral Intelligence Core identity/provenance layer | Accepted |
| ADR-047 | Rebuildable Website Intelligence Projection | Accepted |
| ADR-048 | Global Service Catalog + reusable Search Query Library | Accepted |
| ADR-049 | Search Demand AI proposals + human review boundary | Accepted |
| ADR-050 | Relational Brand Query Portfolio + dynamic locations | Accepted |
| ADR-051 | Human-governed layered Search Demand clusters | Accepted |
| ADR-052 | Read-only Query–URL Visibility Map over canonical facts | Accepted |
| ADR-053 | Manual paid SERP observations + human-applied cluster validation | Accepted |
| ADR-054 | Fail-closed URL eligibility + human-owned Page Relevance decisions | Accepted |
| ADR-055 | Brand-scoped Competitor Library + observation-only discovery | Accepted |
| ADR-056 | Bounded exact-URL competitor page collection + reusable history | Accepted |

## Süpercede edilen kararlar

| Eski | Yerine |
|------|--------|
| ADR-001 | ADR-015 |
| ADR-002 | ADR-016 → ADR-036/034 |
| ADR-003 | ADR-017 |
| ADR-005 | ADR-021, ADR-022, ADR-032 |
| ADR-006 | ADR-020 → ADR-037 |
| ADR-011 | ADR-024 |
| ADR-016 (Result) | ADR-036 |
| ADR-020 | ADR-037 |
| ADR-028 | ADR-034 |
| ADR-021 (Pest satırı) | ADR-038 |
| ADR-030 (AI API key panelden yönetilmez / env-only) | ADR-041 |
| ADR-026 (Filament path `/app`) | ADR-044 |

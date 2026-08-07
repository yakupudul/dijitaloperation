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

- **Durum:** Accepted
- **Tarih:** 2026-08-07
- **Karar:**  
  `Customer → Brand → Digital Asset → Connection → Run → Evidence → Finding → Recommendation → Task → Result`  
  Operasyon: `Read → Collect → Analyze → Diagnose → Recommend → Create internal task → Track result`
- **İlgili:** `MASTER_SPEC.md`, `DOMAIN_MODEL.md`, `EVENT_ARCHITECTURE.md`

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

- **Durum:** Accepted
- **Tarih:** 2026-08-07
- **Karar:** Çekirdek: auth, users, roles, permissions, customers, customer contacts, brands, digital assets, connections, encrypted credentials, module registry, enable/disable, navigation extension points, events, background jobs, scheduler, evidence, findings, recommendations, tasks, notifications, notes, attachments, tags, audit logs, run history, error logs, feature flags, health checks, application settings. Workspace çekirdekte yoktur.
- **İlgili:** `CORE_RESPONSIBILITIES.md`

## ADR-021 — Teknoloji yığını

- **Durum:** Accepted
- **Tarih:** 2026-08-07
- **Güncelleme:** 2026-08-07 (ADR-026, 030, 032 ile netleşti)
- **Karar:** Laravel 13, PHP 8.3+, Filament 5, Livewire, MySQL 8, database queue, Laravel scheduler/events/HTTP client/encryption, Pest, `spatie/laravel-permission`, `laravel/ai`, `internachi/modular`. Redis/Horizon ihtiyaç halinde.
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

- **Durum:** Accepted
- **Tarih:** 2026-08-07
- **Karar:** Tek panel id `app`, path `/app`. Laravel `web` session guard. Public registration yok; kullanıcıları Admin oluşturur. Password reset ve profile var. Roller Admin / Team Member. RBAC: `spatie/laravel-permission`. Multi-tenancy veya müşteri guard yok.
- **İlgili:** `MASTER_SPEC.md` §6, `CORE_RESPONSIBILITIES.md`

## ADR-027 — Connection ve credential şeması

- **Durum:** Accepted
- **Tarih:** 2026-08-07
- **Karar:** `core_connections` secret’sız kimlik/ayar/sağlık tutar. Secret’lar `core_connection_credentials.encrypted_payload` içinde Laravel encryption/encrypted cast (TEXT) ile saklanır. Ham credential Filament/Livewire model state’ine expose edilmez.
- **İlgili:** `MASTER_SPEC.md` §7.1, `DATA_OWNERSHIP.md`

## ADR-028 — Evidence / Finding / Recommendation minimum alanları

- **Durum:** Accepted
- **Tarih:** 2026-08-07
- **Karar:** MASTER_SPEC §7.2 alan seti. Finding.`fingerprint` run’lar arası tekrar ilişkilendirmesi içindir. Platforma özel veriler core tablolara eklenmez.
- **İlgili:** `MASTER_SPEC.md`, `DOMAIN_MODEL.md`

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
| ADR-016 | Yeni akış | Accepted |
| ADR-017 | Asset/Connection; no Workspace | Accepted |
| ADR-018 | Harici write yasağı | Accepted |
| ADR-019 | MVP kullanıcı modeli | Accepted |
| ADR-020 | Çekirdek listesi | Accepted |
| ADR-021 | Teknoloji yığını | Accepted |
| ADR-022 | Yerel paketleme | Accepted |
| ADR-023 | AI sınırı | Accepted |
| ADR-024 | İlk modül seti | Accepted |
| ADR-025 | Manuel Task dönüşümü | Accepted |
| ADR-026 | Panel + auth | Accepted |
| ADR-027 | Connection/credential | Accepted |
| ADR-028 | Analysis min alanlar | Accepted |
| ADR-029 | Task snapshot | Accepted |
| ADR-030 | laravel/ai + env key | Accepted |
| ADR-031 | Diagnosis catalog kapısı | Accepted |
| ADR-032 | app-modules + internachi/modular | Accepted |

## Süpercede edilen kararlar

| Eski | Yerine |
|------|--------|
| ADR-001 | ADR-015 |
| ADR-002 | ADR-016 |
| ADR-003 | ADR-017 |
| ADR-005 | ADR-021, ADR-022, ADR-032 |
| ADR-006 | ADR-020 |
| ADR-011 | ADR-024 |

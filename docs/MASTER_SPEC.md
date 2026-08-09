# MASTER_SPEC

> DOP — Dijital Operasyon Platformu  
> **Tek ve ana ürün kaynağı**  
> Çelişki halinde bu belge diğer dokümanlardan üstündür.  
> İlgili: `IMPLEMENTATION_ROADMAP.md`, `docs/foundation/*`, `docs/module-sdk/*`

## 1. Ürün tanımı

DOP, **Moximu dijital pazarlama ajansının** kendi müşterilerini, markalarını ve bu markalara bağlı dijital varlıkları tek merkezden denetlemek için kullandığı **iç operasyon sistemidir**.

- DOP başlangıçta **SaaS değildir**.
- Müşteriler sisteme **giriş yapmaz**.
- Sistem yalnızca **ajans sahibi** ve **ajans çalışanları** tarafından kullanılır.

DOP yalnızca ham veri gösteren bir dashboard değildir. Amaç; denetlemek, kanıtlamak, teşhis etmek, önermek ve ajans içi göreve dönüştürmektir.

## 2. Temel amaçlar

* Müşterileri yönetmek
* Müşterilere ait markaları yönetmek
* Markalara bağlı dijital varlıkları kaydetmek
* Dijital varlıklara veri kaynakları ve harici entegrasyonlar bağlamak
* Verileri düzenli olarak toplamak
* Dijital varlıkları denetlemek
* Kanıtlar üretmek
* Sorunları ve fırsatları tespit etmek
* Bulgular oluşturmak
* Öneriler üretmek
* AI ile bulguları yorumlamak
* Kullanıcının önerileri göreve dönüştürmesini sağlamak
* Yapılan işlerin sonucunu takip etmek
* Ajansın tekrarlayan manuel işlerini azaltmak

## 3. Temel akış

```text
Customer
→ Brand
→ Digital Asset
→ Connection
→ Run
→ Evidence
→ Finding
→ Recommendation
→ Task
```

Operasyonel okuma akışı (harici sistemlere yazma yok):

```text
Read → Collect → Analyze → Diagnose → Recommend → Create internal task → Track via later runs / Finding lifecycle
```

**MVP’de ayrı `Result` domain entity yoktur.** Sonuç, sonraki Run’lar ve Finding durumu (`open` / `resolved` vb.) üzerinden izlenir.

## 4. Domain hiyerarşisi

```text
Customer
→ Brand
→ Digital Asset
→ Connection
```

| Varlık | Anlam |
|--------|--------|
| Customer | Ajansın müşterisi |
| Brand | Müşteriye bağlı marka |
| Digital Asset | Markaya bağlı yönetilen gerçek dijital varlık |
| Connection | Varlık hakkında veri sağlayan / incelemeye yarayan bağlantı |

**MVP’de Workspace / multi-tenant yok.** Tek DOP kurulumu = tek ajans organizasyonu (Moximu).

### Digital Asset örnekleri

* Website
* Google Business Profile
* Google Ads account
* Meta Ads account
* Instagram account
* YouTube channel
* CRM

### Connection örnekleri

* WordPress
* GA4
* Search Console
* DataForSEO
* PageSpeed
* Lighthouse
* Uptime provider
* Crawl provider

Bir Website digital asset kaydına **birden fazla** connection bağlanabilir.  
GA4, Search Console ve DataForSEO ilk kullanımda **Website varlığının bağlantıları** olarak ele alınır (ayrı Digital Asset değildir).

## 5. Harici sistemlerde değişiklik yasağı

DOP bağlı harici sistemlerde **hiçbir değişiklik yapmaz**.

Yasak örnekler:

* Meta kampanyası oluşturmak / durdurmak / düzenlemek
* Google Ads kampanyasını değiştirmek
* WordPress içeriğini otomatik değiştirmek
* Google Business Profile üzerinde değişiklik yapmak
* Instagram veya diğer sosyal hesaplarda paylaşım yapmak
* Her türlü harici **write action**

Harici entegrasyonlarda mümkün olan en düşük ve **salt okunur** yetkiler tercih edilir.

DOP kendi iç verilerinde müşteri, marka, varlık, connection, bulgu, öneri, görev ve durum değişiklikleri yapabilir.

## 6. MVP kullanıcı modeli ve panel/auth (ADR-026)

| Rol | Açıklama |
|-----|----------|
| Admin | Ajans sahibi / yönetici; kullanıcı oluşturur |
| Team Member | Ajans çalışanı |

| Konu | Karar |
|------|--------|
| Panel | Tek Filament panel |
| Panel id | `app` |
| Panel path | `/app` |
| Guard | Laravel standart `web` session guard |
| Permissions | `spatie/laravel-permission` |
| Public registration | Yok |
| Kullanıcı oluşturma | Yalnızca Admin |
| Password reset / profile | Desteklenir |
| Multi-tenancy / müşteri guard | Yok |

MVP dışında (bugünden kodlanmaz / ürünleştirilmez):

* SaaS / multi-tenant
* Workspace modeli
* Self-service kayıt
* Tenant onboarding
* Abonelik, faturalandırma, paket, kota
* Client Portal (müşteri girişi)
* Marketplace
* Üçüncü taraf / ZIP ile modül yükleme

## 7. Çekirdek sorumlulukları (MVP)

### MVP Core’da zorunlu

* Authentication, Users, Roles / Permissions
* Customers, Customer contacts, Brands
* Digital assets, Connections, Encrypted credentials
* Minimal Module Registry (`module id`, `enabled`/`disabled`; gerekirse bilgisel `installed_version`)
* Runs, Evidence, Findings, Recommendations, Tasks
* Basic application settings
* Basic logs (önce Laravel/log kanalları)
* Events, Queue (database), Scheduler

### MVP Core’da zorunlu değil (sonra eklenebilir; mimari engel olmamalı)

* Attachments, Tags
* Feature flags
* Gelişmiş Notification engine
* Kapsamlı custom Audit Log framework
* Kapsamlı Health Check / module health framework
* Notes (ihtiyaç halinde)

Çekirdek bilmez / yapmaz:

* SEO / Ads / GA4 iş kuralları, crawl, platforma özgü AI prompt
* Harici platform runtime bağımlılığı; harici write
* Platforma özel kolonları core analysis tablolarına eklemek
* Framework’ün zaten çözdüğü altyapıyı yeniden yazmak (ADR-033)

### 7.1 Connection, Integration ve credential (ADR-027, ADR-039)

**Authenticate once at agency level, bind many resources to Digital Assets.**

Agency Integration (`core_integrations` + `core_integration_credentials`):

* Moximu’nun Google / Meta / DataForSEO / OpenAI gibi provider’lara merkezi bağlantısı
* Credential ownership Integration’dadır; Customer/Brand/Asset başına tekrarlanmaz
* Discover edilen kaynaklar `core_external_resources`; Digital Asset eşlemesi `core_asset_bindings`
* Binding ve External Resource secret taşımaz

Asset-scoped Connection (`core_connections` + `core_connection_credentials`) — WordPress vb. site credential’lar:

`id`, `digital_asset_id`, `module_id`, `type`, `name`, `status`, `config_json`, `health_status`, `last_success_at`, `last_error_at`, `last_error_message`

`core_connection_credentials` (secret):

`id`, `connection_id`, `encrypted_payload`, `expires_at`, `refreshed_at`

* `encrypted_payload`: Laravel encryption / encrypted cast ile TEXT kolonda şifreli
* Credential ham değerleri Filament/Livewire model state’ine **expose edilmez**
* Provider-level Connection satırları transitional; destructive migrate zorunlu değil (ADR-039)

### 7.2 Core analysis modelleri (ADR-028, ADR-034)

**Evidence** (Run’a bağlı): `run_id`, `digital_asset_id`, `source_module`, `type`, `title`, `payload`, `observed_at`

**Finding** (Digital Asset üzerinde kalıcı problem/fırsat; tek Run’ın geçici satırı değildir):

`digital_asset_id`, `source_module`, `fingerprint`, `category`, `severity`, `title`, `summary`, `confidence`, `status`, `first_seen_at`, `last_seen_at`, `last_run_id`

* Aynı `fingerprint` sonraki Run’da tekrar bulunursa mevcut Finding **güncellenir** (duplicate yok)
* Artık görülmezse Finding `resolved` işaretlenebilir
* Recommendation Finding’e bağlanabilir

**Recommendation:** `finding_id`, `digital_asset_id`, `source_module`, `title`, `action`, `rationale`, `priority`, `effort`, `status`

Platforma özel veriler core tablolara eklenmez (modül `payload` / kendi tabloları).

### 7.3 Recommendation → Task (ADR-025, ADR-029)

* Otomatik Task yok; kullanıcı manuel dönüştürür
* Taşınan context: `customer`, `brand`, `digital_asset`, `recommendation_id`, `title`, `action`/`description`, `priority`, `rationale`/`context`
* Assignee ve due date **otomatik uydurulmaz**
* Task, Recommendation’ın **snapshot**’ıdır; Recommendation sonra değişse Task otomatik güncellenmez

### 7.4 Sonuç ölçümü (ayrı Result entity yok — ADR-036)

Örnek: Task completed → sonraki diagnosis run → Finding devam ediyor / iyileşti / `resolved`.

## 8. Modüler mimari (MVP sade)

Plugin-based **modular monolith** — paketleme temeli:

* `app-modules/` + **`internachi/modular`**
* Composer package + Laravel Service Provider + Filament Plugin yetenekleri
* Tek repo / app / deploy / DB
* ZIP / marketplace / runtime plugin install **yok**

### MVP Module Registry (minimum)

* `module_id`
* `enabled` / `disabled`
* isteğe bağlı bilgisel `installed_version`

Disabled iken: DOP’a özgü UI, scheduled jobs ve analysis işlemleri çalışmaz.  
Kod Composer’da kalabilir; veri silinmez.

### MVP’de yeniden yazılmayacak / ertelenen (future / non-MVP)

* custom module compatibility engine (`core.min` / `core.maxExclusive`)
* custom module migrator / migration registry
* discovered / registered / failed / uninstalled kapsamlı lifecycle state machine
* purge sistemi, marketplace, custom schema registry

Laravel migration’ları, package discovery ve Filament plugin kayıtları **önce framework yoluyla** kullanılır (ADR-033).  
`docs/module-sdk/*` ileri seviye maddeleri belgede kalır ancak MVP’yi bloke etmez.

## 9. İlk modüller

1. Website
2. Website Diagnosis
3. WordPress Connector
4. Search Console Connector
5. GA4 Connector
6. PageSpeed / Lighthouse Connector
7. DataForSEO Connector
8. AI Insights

Sonraki digital asset modülleri: Google Business Profile, Google Ads, Meta Ads, Instagram, diğerleri.

## 10. Website ilk kullanım akışı

1. Kullanıcı müşteri ekler  
2. Müşteriye marka ekler  
3. Markaya Website digital asset ekler  
4. Domain ve temel site bilgilerini kaydeder  
5. İsteğe bağlı connection’ları ekler  
6. Website Diagnosis taraması başlatır  
7. Sistem Evidence toplar  
8. Finding oluşturur  
9. Recommendation oluşturur  
10. AI, mevcut Evidence ve Finding’leri yorumlar  
11. Kullanıcı Recommendation’ı **manuel** olarak Task’a dönüştürebilir  

Website Diagnosis; GA4, Search Console veya DataForSEO bağlı **olmadan** temel seviyede çalışabilmelidir.  
Bağlantılar eklendikçe teşhis kapsamı ve güven seviyesi artmalıdır.

## 11. AI sınırı (ADR-023, ADR-030, ADR-041)

AI doğrudan kontrolsüz ham veri üzerinde çalışmaz.

Önce sistem: toplar → normalize eder → Evidence üretir → kurallı kontrol → Finding.

Sonra AI: bulguları açıklar → ilişkileri yorumlar → muhtemel neden → öncelik önerir → Recommendation/Task **taslağı** üretebilir.

* Laravel resmi **`laravel/ai`** SDK kullanılır  
* Mimari tek AI sağlayıcısına kilitli değildir; provider/model **config** ile değişir  
* İlk / V1 provider OpenAI (agency Integration)  
* **OpenAI API key** (ADR-041; supersedes ADR-030 env-only key rule): encrypted Integration provider credential → optional env/config fallback → missing. `APP_KEY` / infrastructure env kalır.  
* AI veri uydurmaz; kanıtsız kesin hüküm vermez  
* AI Finding oluşturmaz; deterministic Recommendation’ı sessizce ezmez; Task otomatik açılmaz  
* MCP, vector DB, multi-agent orchestration, tools/web search MVP dışında  

## 12. Teknoloji yığını (başlangıç kararı)

| Katman | Seçim |
|--------|--------|
| Framework | Laravel 13 |
| Dil | PHP 8.3+ |
| Admin UI | Filament 5 + Livewire (panel id `app`, path `/app`) |
| Auth / RBAC | `web` guard + `spatie/laravel-permission` |
| DB | MySQL 8 |
| Queue | Başlangıçta database queue |
| Scheduler | Laravel scheduler |
| Events | Laravel events |
| HTTP | Laravel HTTP client |
| Secrets | Laravel encryption (encrypted cast) |
| AI | `laravel/ai` (provider env/config) |
| Test | PHPUnit (ADR-038) |
| Modüller | `app-modules/` + `internachi/modular` |

Redis, Horizon, ayrı worker ve ileri ölçekleme bileşenleri **yalnızca gerçek ihtiyaçta** eklenir.

### 12.1 Website Diagnosis katalog kapısı (ADR-031)

Connector’suz rule catalog **Core (Faz 0) için blocker değildir.**  
Website Diagnosis fazına başlamadan önce `docs/website/DIAGNOSIS_CATALOG.md` oluşturulacaktır (teşhis contract’ı ile; tahminle değil, açık kaynak/standart türevli).

## 13. Doküman hiyerarşisi

1. `docs/MASTER_SPEC.md` — ürün gerçeği (bu dosya; en üst kaynak)  
2. Accepted / daha yeni ADR’ler (`docs/foundation/DECISION_LOG.md`)  
3. `docs/product/*` — domain/modül **product blueprint** katmanı (MASTER_SPEC’i override etmez; ayrıntılandırır)  
4. `docs/IMPLEMENTATION_ROADMAP.md` — uygulama sırası  
5. `docs/foundation/*` — mimari ilkeler (ADR dışı)  
6. `docs/module-sdk/*` — modül sözleşmeleri  
7. `docs/current-state/*` — geçmiş durum analizi (tarihsel; MASTER_SPEC ile çelişirse MASTER_SPEC geçerli)

Architect / Reviewer / Implementer product feature üretmeden veya review etmeden önce ilgili `docs/product/**` blueprint’ini okur. Blueprint’te olmayan önemli ürün davranışı uydurulmaz.

## 14. Prensip (ADR-033)

> Framework’ün veya kullandığımız güvenilir paketin zaten çözdüğü altyapıyı DOP için tekrar yazma.

DOP özel kodu ürün değerine ayrılır: digital asset management, connections, diagnosis, evidence, findings, recommendations, tasks, AI analysis.

## 15. Açık sorular

Önceki teknik kilitler (panel/auth, credential, Task snapshot, AI, `app-modules/`) geçerlidir.

MVP sadeleştirme sonrası Core’u bloke eden ürün/mimari açık soru **kalmamıştır**.

Website Diagnosis fazı başlamadan önce `docs/website/DIAGNOSIS_CATALOG.md` zorunludur (Core blocker değildir).

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
→ Result
```

Operasyonel okuma akışı (harici sistemlere yazma yok):

```text
Read → Collect → Analyze → Diagnose → Recommend → Create internal task → Track result
```

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

## 7. Çekirdek sorumlulukları

Çekirdek ortak yetenekler:

* Authentication
* Users
* Roles
* Permissions
* Customers
* Customer contacts
* Brands
* Digital assets
* Connections (`core_connections`)
* Encrypted credentials (`core_connection_credentials`)
* Module registry
* Module enable/disable
* Navigation extension points
* Events
* Background jobs
* Scheduler
* Evidence
* Findings
* Recommendations
* Tasks
* Notifications
* Notes
* Attachments
* Tags
* Audit logs
* Run history
* Error logs
* Feature flags
* Health checks
* Application settings

Çekirdek bilmez / yapmaz:

* SEO kuralları, Meta/Google Ads metrik iş kuralları, GA4 iş kuralları
* Website crawl
* Platforma özgü AI prompt iş mantığı
* Harici platforma runtime bağımlılık (connector modülleri yapar)
* Platforma özel kolonları core analysis tablolarına eklemek

### 7.1 Connection ve credential (ADR-027)

`core_connections` (secret olmayan kimlik/ayar/sağlık):

`id`, `digital_asset_id`, `module_id`, `type`, `name`, `status`, `config_json`, `health_status`, `last_success_at`, `last_error_at`, `last_error_message`

`core_connection_credentials` (secret):

`id`, `connection_id`, `encrypted_payload`, `expires_at`, `refreshed_at`

* `encrypted_payload`: Laravel encryption / encrypted cast ile TEXT kolonda şifreli
* Credential ham değerleri Filament/Livewire model state’ine **expose edilmez**

### 7.2 Core analysis modelleri (ADR-028)

**Evidence:** `run`, `digital_asset`, `source_module`, `type`, `title`, `payload`, `observed_at`

**Finding:** `run`, `digital_asset`, `source_module`, `category`, `severity`, `title`, `summary`, `confidence`, `fingerprint`, `status`, `first_seen_at`, `last_seen_at`

**Recommendation:** `finding`, `digital_asset`, `source_module`, `title`, `action`, `rationale`, `priority`, `effort`, `status`

`fingerprint`: aynı bulgunun farklı run’larda tekrarını ilişkilendirir.  
Platforma özel veriler core tablolara eklenmez (modül `payload` / kendi tabloları).

### 7.3 Recommendation → Task (ADR-025, ADR-029)

* Otomatik Task yok; kullanıcı manuel dönüştürür
* Taşınan context: `customer`, `brand`, `digital_asset`, `recommendation_id`, `title`, `action`/`description`, `priority`, `rationale`/`context`
* Assignee ve due date **otomatik uydurulmaz**
* Task, Recommendation’ın **snapshot**’ıdır; Recommendation sonra değişse Task otomatik güncellenmez

## 8. Modüler mimari

Plugin-based **modular monolith**:

* Tek repository
* Tek uygulama
* Tek deployment
* Tek veritabanı
* Net modül sınırları
* Background job desteği
* Modül kökü: **`app-modules/`**
* Paketleme: **`internachi/modular`** + Composer package davranışı
* Dışarıdan ZIP yükleme veya marketplace **yok**

Her modül sahip olmalıdır:

* service provider
* models, migrations, services
* Filament resources / pages / widgets
* config, jobs, events, tests
* Manifest, sürüm, bağımlılıklar, permissions, settings, health checks

Modül sınırları (`docs/module-sdk/*` ve ADR-009) geçerlidir.

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

## 11. AI sınırı (ADR-023, ADR-030)

AI doğrudan kontrolsüz ham veri üzerinde çalışmaz.

Önce sistem: toplar → normalize eder → Evidence üretir → kurallı kontrol → Finding.

Sonra AI: bulguları açıklar → ilişkileri yorumlar → muhtemel neden → öncelik önerir → Recommendation/Task **taslağı** üretebilir.

* Laravel resmi **`laravel/ai`** SDK kullanılır  
* Mimari tek AI sağlayıcısına kilitli değildir; provider/model **config/environment** ile değişir  
* İlk test provider’ı OpenAI olabilir  
* MVP’de AI API key **panelden yönetilmez**; environment variable kullanılır  
* AI veri uydurmaz; kanıtsız kesin hüküm vermez  
* MCP, vector DB, multi-agent orchestration MVP dışında  

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
| Test | Pest |
| Modüller | `app-modules/` + `internachi/modular` |

Redis, Horizon, ayrı worker ve ileri ölçekleme bileşenleri **yalnızca gerçek ihtiyaçta** eklenir.

### 12.1 Website Diagnosis katalog kapısı (ADR-031)

Connector’suz rule catalog **Core (Faz 0) için blocker değildir.**  
Website Diagnosis fazına başlamadan önce `docs/website/DIAGNOSIS_CATALOG.md` oluşturulacaktır (teşhis contract’ı ile; tahminle değil, açık kaynak/standart türevli).

## 13. Doküman hiyerarşisi

1. `docs/MASTER_SPEC.md` — ürün gerçeği (bu dosya)  
2. `docs/IMPLEMENTATION_ROADMAP.md` — uygulama sırası  
3. `docs/foundation/*` — mimari ilkeler ve ADR  
4. `docs/module-sdk/*` — modül sözleşmeleri  
5. `docs/current-state/*` — geçmiş durum analizi (tarihsel; MASTER_SPEC ile çelişirse MASTER_SPEC geçerli)

## 14. Açık sorular

Panel/auth, connection/credential, analysis model alanları, Recommendation→Task, AI SDK/key, modül dizini kararları **kilitlenmiştir** (ADR-026…032).

Core uygulamayı bloke eden ürün/mimari açık soru **kalmamıştır**.

Website Diagnosis fazı başlamadan önce `docs/website/DIAGNOSIS_CATALOG.md` zorunludur (Core blocker değildir).  
Ürün kapsamını SaaS’a veya harici write’a genişleten sorular açık kabul edilmez.

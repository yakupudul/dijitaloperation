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

## 6. MVP kullanıcı modeli

| Rol | Açıklama |
|-----|----------|
| Admin | Ajans sahibi / yönetici |
| Team Member | Ajans çalışanı |

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
* Connections
* Encrypted credentials
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

## 8. Modüler mimari

Plugin-based **modular monolith**:

* Tek repository
* Tek uygulama
* Tek deployment
* Tek veritabanı
* Net modül sınırları
* Background job desteği
* Aynı repository içinde yerel modüller
* Dışarıdan ZIP yükleme veya marketplace **yok**

Her modül sahip olmalıdır:

* Manifest, sürüm, bağımlılıklar
* Migrationlar, modeller, servisler
* Panel kaynakları ve sayfaları
* Permissions, settings
* Jobs, events, health checks, testler

Modül sınırları (`docs/module-sdk/*` ve ADR-009) geçerlidir: private tablo/import yok; iletişim event / açık contract / çekirdek üzerinden.

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

## 11. AI sınırı

AI doğrudan kontrolsüz ham veri üzerinde çalışmaz.

Önce sistem: toplar → normalize eder → Evidence üretir → kurallı kontrol → Finding.

Sonra AI: bulguları açıklar → ilişkileri yorumlar → muhtemel neden → öncelik önerir → Recommendation/Task **taslağı** üretebilir.

* AI veri uydurmaz  
* Kanıtsız kesin hüküm vermez  
* MCP ve karmaşık çoklu agent mimarisi MVP kapsamında değildir  

## 12. Teknoloji yığını (başlangıç kararı)

| Katman | Seçim |
|--------|--------|
| Framework | Laravel 13 |
| Dil | PHP 8.3+ |
| Admin UI | Filament 5 + Livewire |
| DB | MySQL 8 |
| Queue | Başlangıçta database queue |
| Scheduler | Laravel scheduler |
| Events | Laravel events |
| HTTP | Laravel HTTP client |
| Secrets | Laravel encryption |
| Test | Pest |
| Modüller | Yerel Composer package / Filament plugin |

Redis, Horizon, ayrı worker ve ileri ölçekleme bileşenleri **yalnızca gerçek ihtiyaçta** eklenir.

## 13. Doküman hiyerarşisi

1. `docs/MASTER_SPEC.md` — ürün gerçeği (bu dosya)  
2. `docs/IMPLEMENTATION_ROADMAP.md` — uygulama sırası  
3. `docs/foundation/*` — mimari ilkeler ve ADR  
4. `docs/module-sdk/*` — modül sözleşmeleri  
5. `docs/current-state/*` — geçmiş durum analizi (tarihsel; MASTER_SPEC ile çelişirse MASTER_SPEC geçerli)

## 14. Açık sorular (kod öncesi kritik)

MASTER_SPEC’i bozmayan, uygulamadan önce netleştirilmesi gerekenler `IMPLEMENTATION_ROADMAP.md` ve foundation açık sorularında listelenir. Ürün kapsamını SaaS’a veya harici write’a genişleten sorular **açık kabul edilmez**.

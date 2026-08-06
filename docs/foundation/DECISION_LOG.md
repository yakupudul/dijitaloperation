# DECISION_LOG

> Architecture Decision Records (ADR) — başlangıç seti  
> Durum değerleri: Accepted | Proposed | Superseded  
> Bu dosya kararların tek otorite özetidir; ayrıntı ilgili foundation dosyalarındadır.

---

## ADR-001 — Ürün tanımı: teşhis ve aksiyon platformu

- **Durum:** Accepted
- **Tarih:** 2026-08-06
- **Bağlam:** DOP’un dashboard mu yoksa operasyon platformu mu olduğu netleştirilmelidir.
- **Karar:** DOP; dijital varlıkların durumunu analiz eden, sorun/fırsat teşhis eden, nedenlerini açıklayan ve önceliklendirilmiş yapılacaklar üreten modüler dijital operasyon platformudur. Yalnızca ham veri gösteren bir dashboard değildir.
- **Sonuçlar:** Ürün ve UI kararları “metrik göstermek” yerine “teşhis → öneri → görev” değerine göre değerlendirilir.
- **İlgili:** `PRODUCT_VISION.md`

## ADR-002 — Temel değer akışı

- **Durum:** Accepted
- **Tarih:** 2026-08-06
- **Karar:** Birincil akış `Veri → Kanıt → Teşhis → İçgörü → Öneri → Görev → Sonuç` olarak sabitlenir.
- **Sonuçlar:** Modül çıktıları bu halkalara map edilmelidir. Event ve görev tasarımı bu akışı bozmamalıdır.
- **İlgili:** `PRODUCT_VISION.md`, `EVENT_ARCHITECTURE.md`, `TERMINOLOGY.md`

## ADR-003 — Temel sahiplik hiyerarşisi

- **Durum:** Accepted
- **Tarih:** 2026-08-06
- **Karar:** `Workspace → Customer → Brand → Digital Asset` hiyerarşisi kullanılır. Örnek asset türleri: Website, Meta Ads, Google Ads, GA4, Search Console, GBP, CRM, Social.
- **Sonuçlar:** Çekirdek bu varlıkları yönetir. Asset türü genişlemesi modüllerle olur; hiyerarşi tersine çevrilemez.
- **İlgili:** `DOMAIN_MODEL.md`

## ADR-004 — Plugin tabanlı modular monolith

- **Durum:** Accepted
- **Tarih:** 2026-08-06
- **Karar:** Sistem plugin tabanlı modular monolith olarak kurulur.
- **Sonuçlar:** Kesin modül sınırları zorunludur. Erken mikroservis parçalanması varsayılan değildir.
- **İlgili:** `MODULE_ARCHITECTURE.md`

## ADR-005 — Başlangıç deployment modeli

- **Durum:** Accepted
- **Tarih:** 2026-08-06
- **Karar:** Başlangıçta tek uygulama, tek deployment, tercihen tek veritabanı. İleride gerektiğinde ayrı worker veya servise çıkarılabilecek yapı korunur.
- **Sonuçlar:** Operasyonel basitlik öncelikli. Fiziksel servis ayrımı ihtiyaç kanıtı olmadan yapılmaz.
- **İlgili:** `MODULE_ARCHITECTURE.md`

## ADR-006 — Çekirdek sorumlulukları

- **Durum:** Accepted
- **Tarih:** 2026-08-06
- **Karar:** Çekirdek yalnızca ortak platform yeteneklerini yönetir: auth, users, roles/permissions, workspaces, customers, brands, digital assets, module registry, navigation extension points, settings, secret/credential references, events, background jobs, notifications, tasks, audit logs, feature flags, health checks, common API contracts.
- **Sonuçlar:** Yeni ortak ihtiyaç önce “çekirdek mi, modül mü?” testinden geçer.
- **İlgili:** `CORE_RESPONSIBILITIES.md`

## ADR-007 — Çekirdeğin bilmemesi gerekenler

- **Durum:** Accepted
- **Tarih:** 2026-08-06
- **Karar:** Çekirdek SEO kuralı, Meta Ads metriği, GA4 metriği bilmez; website crawl yapmaz; AI promptlarına platforma özgü iş mantığı koymaz; harici platforma bağımlı olmaz.
- **Sonuçlar:** Domain sızıntısı mimari ihlaldir. Connector/diagnosis bilgisi modüllerde kalır.
- **İlgili:** `CORE_RESPONSIBILITIES.md`, `STABILITY_RULES.md`

## ADR-008 — Modül yaşam döngüsü ve paket kuralları

- **Durum:** Accepted
- **Tarih:** 2026-08-06
- **Karar:** Her modül manifestli, sürümlü, çekirdek uyumluluk bildirir; kendi izinleri, ayarları, migration’ları, tabloları/şeması, menü/sekme kayıtları, job’ları, event pub/sub’ı ve health check’i vardır. Disable edildiğinde çekirdek çalışmaya devam eder.
- **Sonuçlar:** Modül ekleme/çıkarma çekirdek yeniden yazımı gerektirmemelidir.
- **İlgili:** `MODULE_ARCHITECTURE.md`, `MODULE_CONTRACT.md`

## ADR-009 — Modüller arası iletişim ve izolasyon

- **Durum:** Accepted
- **Tarih:** 2026-08-06
- **Karar:** Private tablo yazımı ve iç servis import’u yasaktır. İletişim event, açık contract veya çekirdek üzerinden olur. Modül hatası uygulamayı düşürmez. Uzun işler HTTP’de çalışmaz. Harici API’lerde retry/timeout/rate-limit uygulanır. Core tabloları keyfî değiştirilemez. Modül kaldırılınca veri otomatik silinmez. Migration’lar kontrollü ve mümkün olduğunca geri alınabilir olur.
- **Sonuçlar:** Sınır ihlali borç değil, bloklayıcı kalite sorunudur.
- **İlgili:** `MODULE_CONTRACT.md`, `STABILITY_RULES.md`, `EVENT_ARCHITECTURE.md`

## ADR-010 — İlk modül sınıfları

- **Durum:** Accepted
- **Tarih:** 2026-08-06
- **Karar:** Sınıflar: Asset, Connector, Diagnosis, Intelligence, Automation, Presentation.
- **Sonuçlar:** Yeni modüller bir sınıfa map edilir. Sınıf listesi organizasyoneldir; hepsi ilk günden zorunlu değildir.
- **İlgili:** `MODULE_ARCHITECTURE.md`, `OUT_OF_SCOPE.md`

## ADR-011 — İlk gerçek modül: Website Diagnosis

- **Durum:** Accepted
- **Tarih:** 2026-08-06
- **Karar:** İlk gerçek modül Website Diagnosis’tir. Akış: domain ekle → tara → kanıt topla → sorun tespit et → önem derecesi → öneri → görev oluşturulabilir.
- **Sonuçlar:** Diğer diagnosis/connector modülleri bu dilimden sonra önceliklendirilir.
- **İlgili:** `MODULE_ARCHITECTURE.md`, `MODULE_CONTRACT.md`, `PRODUCT_VISION.md`

## ADR-012 — Website Diagnosis v1 dış bağımlılık yasağı

- **Durum:** Accepted
- **Tarih:** 2026-08-06
- **Karar:** İlk sürüm zorunlu olarak GA4, Search Console veya DataForSEO istemez.
- **Sonuçlar:** İlk tarama/kanıt yolu erişilebilir teknik ve içerik sinyallerine dayanır. Bu connector’lar sonraki fazda eklenebilir.
- **İlgili:** `MODULE_ARCHITECTURE.md`, `OUT_OF_SCOPE.md`

## ADR-013 — Ağır işler background job’dır

- **Durum:** Accepted
- **Tarih:** 2026-08-06
- **Karar:** Ağır / uzun işlemler background jobs ile yürütülür; HTTP request path’inde çalıştırılmaz.
- **Sonuçlar:** Crawl, büyük senkronizasyon ve model çıkarımı job olarak tasarlanır. Job altyapı teknolojisi henüz seçilmemiştir.
- **İlgili:** `MODULE_ARCHITECTURE.md`, `EVENT_ARCHITECTURE.md`

## ADR-014 — Disable/uninstall veri ve süreklilik politikası

- **Durum:** Accepted
- **Tarih:** 2026-08-06
- **Karar:** Modül disable olsa bile çekirdek çalışır. Modül kaldırıldığında veri otomatik silinmez.
- **Sonuçlar:** Silme ayrı, bilinçli ve yetkili bir operasyon gerektirir (prosedür henüz yazılmamıştır).
- **İlgili:** `MODULE_CONTRACT.md`, `STABILITY_RULES.md`

---

## Karar indeksi

| ID | Başlık | Durum |
|----|--------|--------|
| ADR-001 | Ürün tanımı | Accepted |
| ADR-002 | Temel değer akışı | Accepted |
| ADR-003 | Hiyerarşi | Accepted |
| ADR-004 | Modular monolith | Accepted |
| ADR-005 | Tek app / deploy / tercihen tek DB | Accepted |
| ADR-006 | Çekirdek sorumlulukları | Accepted |
| ADR-007 | Çekirdek bilmeme kuralları | Accepted |
| ADR-008 | Modül kuralları | Accepted |
| ADR-009 | Modül sınırları / izolasyon | Accepted |
| ADR-010 | Modül sınıfları | Accepted |
| ADR-011 | İlk modül Website Diagnosis | Accepted |
| ADR-012 | v1’de GA4/GSC/DataForSEO zorunlu değil | Accepted |
| ADR-013 | Background jobs | Accepted |
| ADR-014 | Disable sürekliliği ve veri silmeme | Accepted |

## Süpercede edilen kararlar

Henüz yok.

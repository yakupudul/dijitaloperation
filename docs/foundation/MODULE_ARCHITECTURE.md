# MODULE_ARCHITECTURE

> İlgili kararlar: ADR-004, ADR-005, ADR-008, ADR-010, ADR-011, ADR-012, ADR-013  
> Sözleşme detayı: `MODULE_CONTRACT.md`  
> Sınırlar: `STABILITY_RULES.md`, `OUT_OF_SCOPE.md`

## Kararlar

### 1. Mimari stil (ADR-004, ADR-005)

Sistem **plugin tabanlı modular monolith** olarak kurulacaktır.

Başlangıçta:

- Tek uygulama
- Tek deployment
- Tercihen tek veritabanı
- Kesin modül sınırları
- Ağır işler için background jobs (ADR-013)
- İleride gerektiğinde ayrı worker veya servise çıkarılabilen yapı

### 2. Modül kuralları (ADR-008)

Her modül:

- Manifest dosyasına sahip olur
- Sürümlüdür
- Çekirdek uyumluluk sürümünü belirtir
- Kendi izinlerini tanımlar
- Kendi ayarlarını tanımlar
- Kendi migration dosyalarına sahip olur
- Kendi tablolarını veya şemasını yönetir
- Menü ve sekmelerini extension point üzerinden kaydeder
- Joblarını kaydeder
- Event yayınlayabilir
- Event dinleyebilir
- Health check sunar
- Devre dışı bırakıldığında çekirdek çalışmaya devam eder

### 3. İlk modül sınıfları (ADR-010)

| Sınıf | Örnekler |
|-------|----------|
| Asset modules | Website, Meta Ads, Google Ads |
| Connector modules | GA4 Connector, Search Console Connector, Meta Connector |
| Diagnosis modules | Website Diagnosis, Creative Fatigue, Content Decay |
| Intelligence modules | AI Insights, Recommendation Engine |
| Automation modules | Task Generator, Notifications, Scheduled Reports |
| Presentation modules | Dashboard, Client Portal, Reporting |

> Not: “Notifications” çekirdek yetenek listesinde de vardır. Çekirdek ortak bildirim altyapısını sağlar; Automation sınıfındaki Notifications modülü (varsa) senaryoya özel otomasyonu temsil eder. Bu ayrım uygulama tasarımında netleştirilecektir — bkz. Açık Sorular.

### 4. İlk gerçek modül (ADR-011, ADR-012)

- İlk gerçek modül: **Website Diagnosis** (Diagnosis sınıfı).
- İlk sürümde zorunlu olarak **GA4, Search Console veya DataForSEO istememelidir**.
- Temel ilk akış:

  ```text
  Domain ekle
  → siteyi tara
  → erişilebilir teknik ve içerik kanıtlarını topla
  → sorunları tespit et
  → önem derecesi belirle
  → öneriler üret
  → görev oluşturulabilmesini sağla
  ```

### 5. Deployment ve çalışma birimi

- Modüller başlangıçta aynı process / aynı deployable içinde yüklenir.
- Ağır tarama ve analiz işleri HTTP request path’inde değil, background job olarak çalışır.
- Worker ayrımı ileride yapılabilir; başlangıç kararı “ayrı servis zorunlu” değildir.

## Gerekçe

- Modular monolith, erken aşamada operasyonel yükü düşük tutarken modül sınırlarını disipline eder.
- Manifest + version + migration izolasyonu, ileride disable/enable ve kontrollü yükseltme için zemin hazırlar.
- Website Diagnosis’in harici SEO/analytics API’siz başlaması, ilk değeri bağlantı kurulumuna bağımlı kılmaz.

## Sınırlar

- Bu belge paket yöneticisi, dil veya framework seçmez.
- Modül sınıfları organizasyonel kategoridir; her sınıfın ilk günden implemente edilmesi gerekmez.
- “Tercihen tek veritabanı”: şema izolasyonu (prefix / schema / naming) tercih edilir ama fiziksel DB ayrımı başlangıçta zorunlu değildir.
- Presentation modülleri çekirdek navigation extension point’lerini kullanır; ayrı bir SPA/monorepo kararı verilmemiştir.

## Açık Sorular

1. Modüller aynı repo içinde paketler mi, yoksa ayrı paket kayıtlarından mı yüklenecek?
2. Tek DB içinde şema ayrımı nasıl olacak: DB schema, table prefix, yoksa başka bir yöntem?
3. Notifications: çekirdek altyapı ile Automation modülü sınırı nasıl çizilecek?
4. Website asset modülü ile Website Diagnosis modülü ayrı paketler mi olacak, yoksa ilk sürümde birleşik mi?
5. Background job altyapısı hangi teknoloji ile kurulacak? (Henüz seçilmedi)

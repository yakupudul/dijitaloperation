# MODULE_CONTRACT

> İlgili kararlar: ADR-008, ADR-009  
> Olaylar: `EVENT_ARCHITECTURE.md`  
> Kararlılık: `STABILITY_RULES.md`

## Kararlar

### 1. Manifest sözleşmesi

Her modül bir **manifest** ile çekirdeğe kendini tanıtır. Manifest en azından şunları ifade eder (alan adları uygulama aşamasında netleşir):

- Modül kimliği ve görünen adı
- Modül sürümü
- Desteklenen çekirdek uyumluluk sürümü
- Modül sınıfı (asset / connector / diagnosis / intelligence / automation / presentation)
- Tanımladığı izinler
- Tanımladığı ayarlar
- Kaydettiği navigation extension’lar
- Kaydettiği job’lar
- Yayınladığı / dinlediği event’ler (sözleşme düzeyinde)
- Health check tanımı

### 2. Yaşam döngüsü sözleşmesi

Çekirdek, modüller için şu yaşam döngüsü durumlarını tanımalıdır (isimler kesinleşebilir):

- registered
- enabled
- disabled
- failed (yükleme / health bozulması)

**Karar:** Modül devre dışı bırakıldığında çekirdek çalışmaya devam eder.

### 3. Veri ve migration sözleşmesi

- Her modül kendi migration dosyalarına sahiptir.
- Her modül kendi tablolarını veya şemasını yönetir.
- Modül migrationları kontrollü ve mümkün olduğunda geri alınabilir olmalıdır.
- Modül kaldırıldığında veri **otomatik silinmez**.

### 4. İletişim sözleşmesi (ADR-009)

İzin verilen iletişim yolları:

1. **Events** — asenkron / gevşek bağlı bildirim
2. **Açık contract** — versiyonlanmış, dokümante public API / DTO / port
3. **Çekirdek üzerinden** — ortak varlıklar, tasks, jobs, settings, permissions

Yasaklar:

- Bir modül başka modülün **private** tablolarına doğrudan yazamaz.
- Bir modül başka modülün **iç servislerini** doğrudan import edemez.
- Core tabloları modüller tarafından keyfî biçimde değiştirilemez.

### 5. Hata ve çalışma zamanı sözleşmesi

- Bir modülün hatası bütün uygulamayı düşürmemelidir.
- Uzun işlemler HTTP isteği sırasında çalıştırılmamalıdır.
- Harici API hataları retry, timeout ve rate-limit mekanizmasına girmelidir.

### 6. Extension point sözleşmesi

Modüller UI navigasyonunu (menü, sekme vb.) çekirdeğin **navigation extension points** üzerinden kaydeder. Çekirdek, modül bilmeden domain menü içeriği üretmez.

### 7. Website Diagnosis için ürün sözleşmesi (ilk dilim)

İlk gerçek modül akışı (ürün düzeyi; teknik API imzası değil):

1. Domain ekle
2. Siteyi tara (background job)
3. Erişilebilir teknik ve içerik kanıtlarını topla
4. Sorunları tespit et
5. Önem derecesi belirle
6. Öneriler üret
7. Görev oluşturulabilmesini sağla

Zorunlu dış bağımlılık yok: GA4 / Search Console / DataForSEO ilk sürümde zorunlu değildir.

## Gerekçe

- Manifest, çekirdeğin domain bilmeden modül yeteneklerini keşfetmesini sağlar.
- Private tablo/import yasağı, modular monolith’in “monolith spaghetti”ye dönmesini engeller.
- Verinin otomatik silinmemesi, yanlış disable/uninstall’ta geri dönüşü korur.
- Uzun işlerin job’a alınması, HTTP zaman aşımlarını ve kullanıcı deneyimi kırılmalarını önler.

## Sınırlar

- Concrete manifest şema formatı (JSON/YAML) ve alan adları henüz sabitlenmemiştir.
- “Açık contract”ın taşıma biçimi (in-process interface, versioned package, HTTP) henüz seçilmemiştir.
- Idempotent event handling ve exactly-once garantileri bu belgede vaat edilmez.
- Website Diagnosis kanıt alanlarının şeması burada sabitlenmez.

## Açık Sorular

1. Manifest dosya formatı ve doğrulama mekanizması ne olacak?
2. Modüller arası açık contract’lar in-process mi, yoksa her zaman event + çekirdek mi?
3. Rollback migration zorunlu mu, yoksa “mümkün olduğunda” yeterli mi?
4. Failed durumundaki modül UI’da nasıl görünür; kısmi degradation politikası nedir?
5. Görev oluşturma: diagnosis modülü mü çağırır, automation modülü mü, yoksa kullanıcı aksiyonu mu?

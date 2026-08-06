# TERMINOLOGY

> Amaç: Ortak dil. Yeni terimler buraya eklenmeden kod veya başka dokümana dağılmamalıdır.  
> İlgili: `PRODUCT_VISION.md`, `DOMAIN_MODEL.md`

## Kararlar

Aşağıdaki terimler DOP’ta belirtilen anlamlarıyla kullanılır.

| Terim | Anlam |
|-------|--------|
| DOP | Dijital Operasyon Platformu |
| Çekirdek (Core) | Ortak platform yeteneklerini sağlayan, domain-specific iş mantığı taşımayan merkez |
| Modül | Manifest ile kaydolan, sürümlü, disable edilebilir plugin birimi |
| Modular monolith | Tek deployable uygulama içinde kesin modül sınırları |
| Workspace | Üst düzey çalışma alanı / kiracı bağlamı |
| Customer | Workspace altındaki müşteri varlığı |
| Brand | Customer altındaki marka varlığı |
| Digital Asset | Brand altındaki izlenen dijital varlık |
| Asset module | Belirli bir dijital varlık türünü temsil eden / yöneten modül sınıfı |
| Connector module | Harici platform bağlantısı ve veri çekimi sağlayan modül sınıfı |
| Diagnosis module | Kanıt toplayıp sorun/fırsat teşhis eden modül sınıfı |
| Intelligence module | İçgörü ve öneri üretiminde AI / ranking vb. sağlayan modül sınıfı |
| Automation module | Görev, bildirim, zamanlanmış rapor gibi otomasyon sağlayan modül sınıfı |
| Presentation module | Dashboard, portal, raporlama gibi sunum katmanı modül sınıfı |
| Manifest | Modülün kimlik, sürüm, uyumluluk, izin, ayar ve extension bildirim dosyası |
| Extension point | Çekirdeğin modüllere açtığı kayıt noktası (ör. navigation) |
| Veri | Ham veya normalize gözlem |
| Kanıt | Teşhisi destekleyen somut bulgu |
| Teşhis | Sorun veya fırsat tespiti |
| İçgörü | Teşhisin yorumu / neden açıklaması |
| Öneri | Önceliklendirilmiş aksiyon önerisi |
| Görev (Task) | Yapılacak iş kaydı (çekirdek ortak yetenek) |
| Sonuç | Aksiyon sonrası durum / kapanış |
| Background job | HTTP dışında çalışan uzun veya ertelenebilir iş |
| Event | Modüller ve çekirdek arasında yayınlanan sözleşme mesajı |
| Credential reference | Secret değerinin kendisi değil; çekirdekte tutulan referans / bağ |
| Website Diagnosis | İlk gerçek diagnosis modülü |

## Gerekçe

- Ortak terimler olmadan modül ekipleri aynı kavramı farklı isimlerle çoğaltır.
- “Dashboard” ile “Diagnosis” ayrımı ürün kimliği için kritiktir.
- Credential **reference** ifadesi, secret değerinin çekirdeğe sızmaması gerektiğini dil düzeyinde hatırlatır.

## Sınırlar

- Bu sözlük uygulama sınıf adlarını dayatmaz; eşleme uygulama tasarımında yapılır.
- İngilizce kod kimlikleri ile Türkçe ürün dili birlikte yaşayabilir; mapping tablosu ileride eklenebilir.
- Pazarlama sloganları bu sözlüğe girmez.

## Açık Sorular

1. UI ve API’de varsayılan dil Türkçe mi, İngilizce mi olacak?
2. “Finding”, “Issue”, “Insight” gibi İngilizce eşdeğerlerden hangisi kanonik kod terimi olacak?
3. “Workspace” kullanıcıya “çalışma alanı” olarak mı gösterilecek?
4. Ajans bağlamında Customer yerine “Client” kullanımı resmi olarak kabul edilecek mi?

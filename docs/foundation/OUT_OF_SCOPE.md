# OUT_OF_SCOPE

> Bu belge “şimdilik yapılmayacak / bu fazda kararlaştırılmamış” sınırları netleştirir.  
> Ürün vizyonu: `PRODUCT_VISION.md`  
> İlk modül: `MODULE_ARCHITECTURE.md`

## Kararlar

### Bu foundation aşamasının kapsamı dışında

1. Uygulama kodu yazmak veya mevcut kodu refactor etmek
2. Teknoloji stack’inin kesin seçimi (dil, framework, ORM, broker, hosting)
3. Üretim deploy pipeline ve altyapı otomasyonu
4. Fiyatlandırma, paketleme ve faturalandırma
5. Hukuki / KVKK süreç dokümanlarının tamamı (gerektiğinde ayrı ele alınır)
6. Tüm dijital varlık türleri için eşzamanlı derin destek

### İlk Website Diagnosis sürümünün zorunlu kapsamı dışında

7. GA4 zorunlu bağımlılığı
8. Search Console zorunlu bağımlılığı
9. DataForSEO zorunlu bağımlılığı
10. Meta Ads / Google Ads diagnosis
11. Creative Fatigue, Content Decay vb. diğer diagnosis modülleri
12. Tam özellikli Client Portal (presentation sınıfı örnek olarak vardır; ilk dilim zorunluluğu değildir)
13. Çoklu modül marketplace / üçüncü parti modül ekonomisi
14. Mikroservis ayrıştırması (ileride worker/servis çıkarılabilir; başlangıçta zorunlu değil)

### Bilinçli olarak ertelenen konular

15. Event şema registry’nin nihai formatı
16. Secret storage teknolojisi
17. Exactly-once messaging garantileri
18. Modüller arası otomatik orchestration / saga motoru

## Gerekçe

- Erken aşamada her kanalı ve her entegrasyonu vaat etmek, ilk değeri geciktirir.
- Website Diagnosis’in harici API’siz başlayabilmesi, OUT OF SCOPE maddelerini ürün stratejisiyle hizalar.
- Stack seçimini foundation kararlarından ayırmak, mimari ilkelerin teknoloji modasından bağımsız kalmasını sağlar.

## Sınırlar

- “Kapsam dışı” sonsuza dek yasak anlamına gelmez; sonraki ADR ile kapsam açılabilir.
- OUT OF SCOPE, araştırma yapılmayacağı anlamına gelmez; sadece bu dokümantasyon setinde kararlaştırılmadığını belirtir.
- Connector modülleri vizyonda vardır; yok sayılmazlar, ilk zorunlu dilime dahil edilmezler.

## Açık Sorular

1. Client Portal hangi milestone’da zorunlu hale gelecek?
2. İlk ücretli / üretim sürümüne hangi connector’lar minimum paket olarak girecek?
3. AI Insights modülü Website Diagnosis ile aynı fazda mı, sonra mı?
4. Ajansa özel white-label sunum kapsamına ne zaman girecek?

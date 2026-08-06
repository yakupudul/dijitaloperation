# STABILITY_RULES

> İlgili kararlar: ADR-009, ADR-014  
> Ayrıntılı sözleşme: `MODULE_CONTRACT.md`

## Kararlar

Bu kurallar **değişmez başlangıç kararları**dır. İhlal, mimari regresyon sayılır.

### Modüller arası sınırlar (ADR-009)

1. Bir modül başka modülün private tablolarına doğrudan yazamaz.
2. Bir modül başka modülün iç servislerini doğrudan import edemez.
3. İletişim event, açık contract veya çekirdek üzerinden sağlanır.
4. Bir modülün hatası bütün uygulamayı düşürmemelidir.
5. Uzun işlemler HTTP isteği sırasında çalıştırılmamalıdır.
6. Harici API hataları retry, timeout ve rate-limit mekanizmasına girmelidir.
7. Core tabloları modüller tarafından keyfî biçimde değiştirilemez.
8. Modül kaldırıldığında veri otomatik silinmez.
9. Modül migrationları kontrollü ve mümkün olduğunda geri alınabilir olmalıdır.

### Çekirdek kararlılığı (ADR-007, ADR-014)

10. Çekirdek, domain-specific kuralları (SEO, Meta Ads, GA4 vb.) bilmez ve içermez.
11. Çekirdek website crawl yapmaz.
12. Çekirdek AI promptlarına platforma özgü iş mantığı yerleştirmez.
13. Çekirdek herhangi bir harici platforma bağımlı olmaz.
14. Bir modül disable edildiğinde çekirdek çalışmaya devam eder.

### Ürün kararlılığı

15. DOP yalnızca ham veri dashboard’u olarak daraltılamaz; teşhis ve aksiyon akışı korunur.
16. Temel hiyerarşi (Workspace → Customer → Brand → Digital Asset) bozulmadan genişletilir.
17. Website Diagnosis ilk sürümü GA4 / Search Console / DataForSEO zorunluluğu getirmez.

## Gerekçe

- Bu kurallar, modular monolith’in fiilen “paylaşımlı karma” haline gelmesini engeller.
- Failure isolation ve async uzun işler, operasyonel kararlılık için zorunludur.
- Veri silmeme ve kontrollü migration, üretimde geri dönüş kapısı bırakır.

## Sınırlar

- Bu belge kod review checklist’inin tamamını oluşturmaz; uygulama aşamasında lint/arch test ile güçlendirilebilir (araç seçimi yok).
- “Mümkün olduğunda geri alınabilir migration” mutlak her değişikliğin down migration’ı olduğu anlamına gelmez; geri alınamaz değişiklikler DECISION_LOG’a yazılmalıdır.
- Performans SLA / SLO sayıları burada tanımlanmaz.

## Açık Sorular

1. Sınır ihlallerini otomatik denetleyecek mimari test / lint zorunlu mu?
2. Modül failed durumunda hangi kullanıcıya ne gösterilecek?
3. Rate-limit politikaları global mi, connector bazlı mı?
4. Core schema değişikliği için onay süreci (ADR zorunluluğu eşiği) nedir?

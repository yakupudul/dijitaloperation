# STABILITY_RULES

> Ana kaynak: `docs/MASTER_SPEC.md`  
> İlgili: ADR-009, ADR-014, ADR-018

## Kararlar

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

### Çekirdek ve ürün kararlılığı

10. Çekirdek domain-specific kuralları bilmez; crawl yapmaz; harici platforma bağımlı olmaz.  
11. Harici sistemlerde write action yoktur (ADR-018).  
12. Modül disable → çekirdek çalışır.  
13. MVP’de Workspace/SaaS/Client Portal varsayımı kodlanmaz.  
14. Hiyerarşi `Customer → Brand → Digital Asset → Connection` bozulmadan genişler.  
15. Website Diagnosis connector zorunluluğu getirmez.  
16. Event type standardı: `{kebab-module}.{kebab-action}` (ör. `website-diagnosis.scan-completed`).

## Gerekçe

Sınır ihlali ve harici write, ajans içi güven modelini bozar.

## Sınırlar

* Arch lint araçları uygulama fazında eklenir.
* Performans SLO sayıları burada yok.

## Açık Sorular

1. Import boundary için Pest/arch test ilk günden zorunlu mu?

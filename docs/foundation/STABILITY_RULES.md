# STABILITY_RULES

> Ana kaynak: `docs/MASTER_SPEC.md`  
> İlgili: ADR-009, ADR-014, ADR-018, ADR-026…032

## Kararlar

### Modül sınırları (ADR-009)

1. Private tablo yazımı yok  
2. İç servis import yok  
3. İletişim: event / açık contract / çekirdek  
4. Modül hatası uygulamayı düşürmez  
5. Uzun iş HTTP’de değil  
6. Harici API: retry, timeout, rate-limit  
7. Core tabloları keyfî değişmez  
8. Uninstall veriyi silmez  
9. Migration kontrollü / mümkünse reversible  

### Ürün / güvenlik

10. Harici write yok  
11. Credential ham değeri Filament/Livewire state’e çıkmaz  
12. Disable → çekirdek çalışır  
13. Workspace/SaaS/Client Portal kodlanmaz  
14. Hiyerarşi Customer→Brand→Digital Asset→Connection  
15. Event: `{kebab-module}.{kebab-action}`  
16. Modüller yalnızca `app-modules/` altında  

## Gerekçe

Sınır ihlali ve secret sızıntısı ajans güven modelini bozar.

## Sınırlar

Arch/import testleri uygulama fazında Pest ile eklenebilir; Core’u belgesel olarak bloke etmez.

## Açık Sorular

Yok.

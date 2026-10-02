# PRODUCT_VISION

> Ana kaynak: `docs/MASTER_SPEC.md`  
> İlgili ADR: ADR-015, ADR-018, ADR-019, ADR-026, ADR-034, ADR-036, ADR-044

## Kararlar

1. DOP, Moximu ajansının **iç** operasyon sistemidir (SaaS değil; müşteri girişi yok).  
2. Kullanıcılar: Admin, Team Member. Operator product: root routes. Tek Filament teknik panel `/admin` (ADR-044).  
3. Akış: Customer → Brand → Digital Asset → Connection → Run → Evidence → Finding → Recommendation → Task.  
4. Ayrı `Result` entity yok; sonuç Finding lifecycle + sonraki Run’larla izlenir.  
5. Harici write yok.  
6. Recommendation → Task manuel snapshot.  
7. MVP hafif: framework’ü yeniden yazmadan ürün değerine odak (ADR-033).

## Gerekçe

Ajans içi read-only denetim + sade domain, erken teslimatı hızlandırır.

## Sınırlar

* Diagnosis katalog Website Diagnosis öncesi ayrı dokümanda.

## Açık Sorular

Yok.

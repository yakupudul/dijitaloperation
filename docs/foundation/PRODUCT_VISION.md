# PRODUCT_VISION

> Ana kaynak: `docs/MASTER_SPEC.md`  
> İlgili ADR: ADR-015, ADR-016, ADR-018, ADR-019, ADR-025, ADR-029

## Kararlar

1. DOP, Moximu ajansının **iç** operasyon sistemidir (SaaS değil; müşteri girişi yok).  
2. Kullanıcılar: Admin, Team Member; tek Filament panel `/app`.  
3. Akış: Customer → Brand → Digital Asset → Connection → Run → Evidence → Finding → Recommendation → Task → Result.  
4. Harici write yok: Read → Collect → Analyze → Diagnose → Recommend → internal Task → Track result.  
5. Recommendation otomatik Task olmaz; manuel dönüşüm + snapshot (ADR-029).  
6. İlk dilim: Website + Website Diagnosis; connector’lar kapsamı artırır.

## Gerekçe

Ajans içi read-only denetim, müşteri hesap riskini ve SaaS karmaşıklığını MVP dışına iter.

## Sınırlar

* Result ölçüm UX’i (manuel kapanış vs yeniden run) ürün kapsamını genişletmeden uygulama UI’sında seçilir; harici write gerektirmez.
* Diagnosis katalog içeriği ayrı dokümanda (Faz 4 öncesi).

## Açık Sorular

Yok (Core’u bloke eden ürün sorusu kalmadı).

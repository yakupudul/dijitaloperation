# TERMINOLOGY

> Ana kaynak: `docs/MASTER_SPEC.md`

## Kararlar

| Terim | Anlam |
|-------|--------|
| DOP | Dijital Operasyon Platformu — Moximu iç operasyon sistemi |
| Moximu | Ajans; tek MVP organizasyonu |
| Çekirdek (Core) | Ortak platform yetenekleri; domain iş kuralı taşımaz |
| Modül | Manifestli, sürümlü, enable/disable edilebilir yerel plugin |
| Modular monolith | Tek deployable içinde net modül sınırları |
| Customer | Ajans müşterisi |
| Brand | Müşteriye bağlı marka |
| Digital Asset | Markaya bağlı yönetilen gerçek dijital varlık |
| Connection | Asset’e bağlı veri/inceleme bağlantısı (GA4, GSC, …) |
| Run | Toplama veya teşhis çalıştırma kaydı |
| Evidence | Kanıt |
| Finding | Bulgu (sorun/fırsat) |
| Recommendation | Öneri |
| Task | Ajans içi görev |
| Result | Sonuç / kapanış durumu |
| Admin | Ajans yönetici rolü |
| Team Member | Ajans çalışan rolü |
| Read-only integration | Harici sistemde değişiklik yapmayan bağlantı |
| Asset module | Digital Asset türü modülü |
| Connector module | Connection sağlayıcı modülü |
| Diagnosis module | Evidence/Finding üreten teşhis modülü |
| Intelligence module | AI yorum / öncelik modülü |
| Manifest | `module.manifest.json` |
| Workspace | **MVP’de kullanılmaz** (eski varsayım; SaaS için ayrılmıştır) |

## Gerekçe

Ortak dil olmadan Asset/Connection ve SaaS varsayımları yeniden karışır.

## Sınırlar

* İngilizce kod kimlikleri ile Türkçe UI etiketleri birlikte yaşayabilir.
* Eski belgelerdeki “Workspace” ifadeleri geçersizdir; MASTER_SPEC üstündür.

## Açık Sorular

1. UI’da Connection için Türkçe etiket: “Bağlantı” mı, “Veri kaynağı” mı?

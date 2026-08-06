# DATABASE

> İnceleme tarihi: 2026-08-06  
> Sonuç: Veritabanı şeması, model veya migration bulunamadı.

## Mevcut tablolar veya modeller

**Yok.**

Aşağıdakilerden hiçbiri depoda tespit edilmedi:

- SQL migration dosyaları
- Prisma / Drizzle / TypeORM / Sequelize / Django / Eloquent model dosyaları
- GraphQL schema içindeki entity tanımları
- In-memory veya dosya tabanlı veri katmanı

## Aralarındaki ilişkiler

**Uygulanabilir değil.** Model / tablo olmadığı için ilişki grafiği çıkarılamadı.

## Temel varlıkların mevcut karşılıkları

Ürün adından ve README’den beklenen kavramlar ile depodaki karşılıkları:

| Kavram | Depodaki karşılık | Not |
|--------|-------------------|-----|
| Customer (müşteri) | **Yok** | Model, tablo veya tip tanımı bulunamadı |
| Brand (marka) | **Yok** | — |
| Asset (dijital varlık) | **Yok** | README “digital assets” der; kod karşılığı yok |
| User (kullanıcı) | **Yok** | Auth/user modeli yok |
| Action / prioritized action | **Yok** | README “prioritized actions” der; kod karşılığı yok |
| Organization / workspace | **Yok** | — |
| Integration / connector | **Yok** | — |

## Riskli veya belirsiz ilişkiler

| Konu | Değerlendirme |
|------|----------------|
| Entity ilişkileri | Henüz tanımlanmadığı için riskli ilişki yok; belirsizlik “şema yok” düzeyinde |
| Veri sahipliği (tenant / müşteri izolasyonu) | **Doğrulanamadı** — tasarım kararı depoda yok |
| Soft delete / audit alanları | **Mevcut değil** |
| Migration geçmişi | **Mevcut değil** |

## Sonuç

Veri modeli henüz oluşturulmamış. Customer / brand / asset / user gibi temel varlıklar yalnızca ürün niyeti olarak README düzeyinde ima edilmekte; şema karşılıkları yoktur.

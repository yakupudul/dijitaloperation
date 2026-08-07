# OUT_OF_SCOPE

> Ana kaynak: `docs/MASTER_SPEC.md`  
> MVP’de yapılmayacak / bilinçli olarak dışarıda bırakılanlar

## Kararlar

### Ürün / iş modeli (MVP dışı)

1. SaaS / multi-tenant satış
2. Workspace modeli
3. Self-service kayıt ve tenant onboarding
4. Abonelik, faturalandırma, paket, kota
5. Client Portal / müşteri girişi
6. Marketplace
7. Üçüncü taraf veya ZIP ile modül yükleme
8. White-label müşteri sunumu

### Harici aksiyon (kalıcı yasak — MVP ve sonrası için ürün kuralı)

9. Meta / Google Ads / WordPress / GBP / sosyal hesaplarda write action
10. Harici kampanya oluşturma, durdurma, içerik değiştirme, paylaşım

### Mimari / altyapı (ihtiyaç kanıtlanana kadar)

11. Redis / Horizon (başlangıç: database queue)
12. Ayrı worker servisleri / mikroservis parçalama
13. MCP ve karmaşık çoklu agent AI mimarisi

### Erken fazda zorunlu olmayan tanı

14. Website Diagnosis için GA4 / Search Console / DataForSEO zorunluluğu (isteğe bağlı connection)
15. GBP, Google Ads, Meta Ads, Instagram asset modülleri (roadmap Faz 7)
16. Creative Fatigue / Content Decay vb. diğer diagnosis’ler

### Bu dokümantasyon PR’sinin dışında

17. Uygulama kodu yazmak
18. Paket yükleme / migration çalıştırma

## Gerekçe

Kapsamı ajans-içi read→diagnose→internal task zincirine kilitlemek, yanlış SaaS ve write varsayımlarını engeller.

## Sınırlar

* “Kapsam dışı” sonsuza dek yasak demek değildir (SaaS için ayrı ADR gerekir).
* Harici write yasağı ürün kimliğidir; değiştirmek için açık ADR + güvenlik değerlendirmesi şarttır.

## Açık Sorular

1. İleride sınırlı “draft öneriyi müşteriye e-posta ile ilet” (DOP dışı kanal) ürün müdür, değil midir? (MVP’de yok)

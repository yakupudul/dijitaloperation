# OUT_OF_SCOPE

> Ana kaynak: `docs/MASTER_SPEC.md`

## Kararlar

### Ürün / iş modeli (MVP dışı)

1. SaaS / multi-tenant / Workspace  
2. Self-service kayıt, tenant onboarding  
3. Abonelik, faturalandırma, paket, kota  
4. Client Portal / müşteri girişi  
5. Marketplace / ZIP modül yükleme  
6. White-label müşteri sunumu  
7. Müşteriye otomatik dış kanal bildirim ürünü (e-posta ile draft iletim vb.) — MVP’de yok  

### Harici aksiyon (ürün kuralı)

8. Her türlü harici write action (Ads, WordPress, GBP, sosyal, …)

### Altyapı (ihtiyaç kanıtlanana kadar)

9. Redis / Horizon  
10. Ayrı worker / mikroservis  
11. MCP, vector DB, multi-agent  

### AI yönetim

12. AI API key’in panelden yönetimi (MVP’de env)

### Erken faz zorunlu olmayan

13. Website Diagnosis için connector zorunluluğu  
14. GBP / Ads / Instagram asset’leri (Faz 7)  
15. Diagnosis katalog dosyasının Core ile aynı anda yazılması (Faz 4 öncesi yeter)

### Bu dokümantasyon çalışması dışı

16. Uygulama kodu / paket kurulumu  

## Gerekçe

Kapsamı ajans-içi read→diagnose→internal task zincirine kilitler.

## Sınırlar

SaaS veya harici write için ayrı ADR şarttır.

## Açık Sorular

Yok.

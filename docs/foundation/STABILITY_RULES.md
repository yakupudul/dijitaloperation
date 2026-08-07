# STABILITY_RULES

> Ana kaynak: `docs/MASTER_SPEC.md`  
> İlgili: ADR-009, ADR-018, ADR-033, ADR-034

## Kararlar

1. Private tablo yazımı / iç servis import yok; iletişim event veya çekirdek.  
2. Modül hatası uygulamayı düşürmez.  
3. Uzun iş HTTP’de değil (queue).  
4. Harici API: retry/timeout/rate-limit; harici write yok.  
5. Core tabloları keyfî değişmez; uninstall/disable veri silmez.  
6. Credential ham değeri UI state’e çıkmaz.  
7. Workspace/SaaS/Client Portal kodlanmaz.  
8. Hiyerarşi Customer→Brand→Digital Asset→Connection.  
9. Finding kalıcı + fingerprint; Result entity yok.  
10. Framework’ün çözdüğünü tekrar yazma (ADR-033).  
11. Event: `{kebab-module}.{kebab-action}`.  
12. Modüller `app-modules/` altında.

## Gerekçe

Ürün güvenliği + MVP hızı.

## Sınırlar

Arch testleri faydalı; Core belgesel bloker değildir.

## Açık Sorular

Yok.

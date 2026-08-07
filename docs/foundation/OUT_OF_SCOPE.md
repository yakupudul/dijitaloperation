# OUT_OF_SCOPE

> Ana kaynak: `docs/MASTER_SPEC.md`

## Kararlar

### Ürün / iş modeli (MVP dışı)

1. SaaS / multi-tenant / Workspace  
2. Self-service kayıt, tenant onboarding  
3. Abonelik, faturalandırma, paket, kota  
4. Client Portal / müşteri girişi  
5. Marketplace / ZIP / runtime plugin install  
6. White-label müşteri sunumu  

### Harici aksiyon

7. Her türlü harici write action  

### Custom module platform (MVP’de yeniden yazılmaz — future)

8. Compatibility engine (`core.min` / `core.maxExclusive`)  
9. Custom module migrator / migration registry  
10. discovered/registered/failed/uninstalled kapsamlı lifecycle FSM  
11. Purge sistemi, custom schema registry  

### Ayrı domain

12. Ayrı `Result` entity  

### Altyapı (ihtiyaç kanıtlanana kadar)

13. Redis / Horizon, ayrı worker / mikroservis  
14. MCP, vector DB, multi-agent  
15. AI key’in panelden yönetimi  
16. Attachments / Tags / feature flags / ağır notification-audit-health framework’leri (Core bootstrap zorunluluğu değil)

## Gerekçe

Hafif MVP + ADR-033.

## Sınırlar

İleride ADR ile açılabilir; harici write için güvenlik değerlendirmesi şart.

## Açık Sorular

Yok.

# IMPLEMENTATION_ROADMAP

> Ana kaynak: `docs/MASTER_SPEC.md`  
> Hedef: hafif, hızlı, ekonomik MVP. Framework’ü yeniden yazma (ADR-033).

## İlk gerçek hedef sırası

1. Laravel / Filament bootstrap  
2. Auth + kullanıcı / rol  
3. Customer  
4. Brand  
5. Digital Asset  
6. Connection (+ encrypted credentials)  
7. Minimal module registry (`id`, enabled/disabled)  
8. Run / Evidence / Finding / Recommendation / Task  
9. Website module  
10. Website Diagnosis  

**Sample Module:** yalnızca modüler altyapıyı doğrulayan kısa teknik smoke test; ayrı büyük ürün fazı değildir.

## Faz notları

| Adım | Not |
|------|-----|
| 1–2 | Tek panel `app`/`/app`, `spatie/laravel-permission`, Admin / Team Member |
| 3–6 | Domain CRUD + Connection/credential (ADR-027) |
| 7 | Custom plugin framework yok; `internachi/modular` + Composer/Filament |
| 8 | Finding kalıcı + fingerprint (ADR-034); Evidence Run’a bağlı; Result entity yok |
| 9–10 | Website asset + diagnosis; katalog `docs/website/DIAGNOSIS_CATALOG.md` diagnosis öncesi |

## Erken fazda yapılmayacaklar

* SaaS / Workspace / Client Portal / marketplace / ZIP install  
* Harici write  
* Custom compatibility engine, custom migrator registry, purge, kapsamlı lifecycle FSM  
* Attachments / Tags / feature flags / ağır notification-audit-health framework’leri (ihtiyaç + sonraki faz)  
* Redis / Horizon (ihtiyaç kanıtı olmadan)  
* AI Insights (Website Diagnosis’ten sonra)  

## Bloker

| Konu | Durum |
|------|--------|
| Core bootstrap için mimari kararlar | Açık bloker yok |
| Diagnosis catalog | Yalnızca Website Diagnosis öncesi zorunlu |

## Kurallar

1. Üst adım olmadan alt adımı “bitti” sayma.  
2. Framework’ün çözdüğünü tekrar yazma (ADR-033).  
3. Ürün kapsamını SaaS veya harici write’a genişletme.  

# CORE_RESPONSIBILITIES

> Ana kaynak: `docs/MASTER_SPEC.md`  
> İlgili ADR: ADR-007, ADR-018, ADR-020

## Kararlar

### Çekirdeğin yönettiği ortak yetenekler (ADR-020)

* Authentication
* Users
* Roles
* Permissions
* Customers
* Customer contacts
* Brands
* Digital assets
* Connections
* Encrypted credentials
* Module registry
* Module enable/disable
* Navigation extension points
* Events
* Background jobs
* Scheduler
* Evidence
* Findings
* Recommendations
* Tasks
* Notifications
* Notes
* Attachments
* Tags
* Audit logs
* Run history
* Error logs
* Feature flags
* Health checks
* Application settings

### Çekirdeğin bilmemesi / yapmaması gerekenler (ADR-007, ADR-018)

Çekirdek:

* SEO kuralı bilmez
* Meta Ads / Google Ads iş kuralı bilmez
* GA4 iş kuralı bilmez
* Website crawl yapmaz
* AI promptlarına platforma özgü iş mantığı koymaz
* Harici platforma bağımlı olmaz
* Harici sistemlerde write action yapmaz / sunmaz

### Sorumluluk ayrımı

| Konu | Sahip |
|------|--------|
| Kimlik, roller, müşteri/marka/asset/connection | Çekirdek |
| Modül yaşam döngüsü | Çekirdek |
| Evidence/Finding/Recommendation ortak kaydı | Çekirdek |
| Domain teşhis kuralları / crawl / connector API | İlgili modül |
| AI yorum prompt’ları | AI Insights (ve ilgili) modül |
| Credential değeri saklama | Çekirdek (Laravel encryption); kullanım connector modülünde |

## Gerekçe

Ortak akış nesnelerini çekirdekte tutmak, modüllerin birbirinin private şemasına yazmadan Task/Finding üretmesini sağlar. Domain bilgisi modüllerde kalır.

## Sınırlar

* Filament resource’larının çekirdek vs modül dağılımı uygulama tasarımındadır; sorumluluk listesi değişmez.
* “Common API contracts” ifadesi kaldırıldı; yerine Laravel/Filament + module-sdk sözleşmeleri geçerlidir.

## Açık Sorular

1. Finding severity enum değerleri çekirdek standardı mı?
2. Run history tek tablo mu, yoksa diagnosis/connector run tipleri ayrılacak mı?
3. Notes/Attachments hangi entity’lere polymorphic bağlanacak?

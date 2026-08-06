# CORE_RESPONSIBILITIES

> İlgili kararlar: ADR-006, ADR-007  
> Mimari bağlam: `MODULE_ARCHITECTURE.md`

## Kararlar

### Çekirdeğin yönettiği ortak yetenekler (ADR-006)

Çekirdek yalnızca ortak platform yeteneklerini yönetir:

- Authentication
- Users
- Roles and permissions
- Workspaces
- Customers
- Brands
- Digital assets
- Module registry
- Navigation extension points
- Settings
- Secret and credential references
- Events
- Background jobs
- Notifications
- Tasks
- Audit logs
- Feature flags
- Health checks
- Common API contracts

### Çekirdeğin bilmemesi gerekenler (ADR-007)

Çekirdek:

- SEO kuralı bilmez
- Meta Ads metriği bilmez
- GA4 metriği bilmez
- Website crawl işlemi yapmaz
- AI promptlarına platforma özgü iş mantığı yerleştirmez
- Herhangi bir harici platforma bağımlı olmaz

### Sorumluluk ayrımı ilkesi

| Konu | Sahip |
|------|--------|
| Kimlik, erişim, hiyerarşi | Çekirdek |
| Modül yaşam döngüsü ve kayıt | Çekirdek |
| Domain-specific analiz / crawl / metrik | İlgili modül |
| Platforma özgü AI prompt içeriği | İlgili intelligence / diagnosis modülü |
| Harici API SDK ve credential kullanımı | İlgili connector / asset modülü (çekirdek yalnızca credential **referansını** tutabilir) |

## Gerekçe

- Çekirdeği ince tutmak, plugin tabanlı modular monolith’te sınır ihlalini azaltır.
- Domain bilgisinin çekirdeğe sızması, her yeni kanalda çekirdek değişikliği demektir; bu, ADR-004/ADR-008 hedeflerine aykırıdır.
- Ortak Tasks, Events, Jobs ve Notifications; modüllerin birbirini private import etmeden işbirliği yapması için gereklidir.

## Sınırlar

- “Secret and credential references” çekirdeğin **referans ve erişim politikasını** yönettiği anlamına gelir; secret’ların nerede saklanacağı (vault, env, encrypted column) bu belgede seçilmez.
- Notifications ve Tasks’ın UI/UX detayı presentation modüllerine bırakılabilir; çekirdek ortak veri ve API sözleşmesini taşır.
- Health checks: çekirdek platform sağlığını ve modüllerin kaydettiği health endpoint’lerini toplar; modül-içi sağlık mantığı modüle aittir.
- Common API contracts, çekirdek–modül ve ortak kaynaklar içindir; her modülün tüm private API’sini çekirdek bilmez.

## Açık Sorular

1. Credential değerleri çekirdek şemasında mı şifreli tutulacak, yoksa dış secret store mu kullanılacak?
2. Tasks çekirdekte generic midir, yoksa modül extension alanları zorunlu mu?
3. Feature flags çekirdek global mi, yoksa workspace / modül bazlı mı olacak?
4. Audit log kapsamı: yalnızca çekirdek aksiyonları mı, yoksa modül domain olayları da zorunlu mu?

# AGENTS.md

## Cursor Cloud specific instructions

Ürün gerçeği: `docs/MASTER_SPEC.md` (+ roadmap, foundation, module-sdk).

### Kilit kararlar

- Moximu **iç** operasyon; SaaS / Workspace / müşteri girişi yok
- Harici **write action yok**
- Tek Filament panel: id `app`, path `/app`; `web` guard; `spatie/laravel-permission`
- Modüller: `app-modules/` + `internachi/modular` — MVP’de custom plugin framework yok (minimal registry: id + enabled/disabled)
- Finding kalıcı + fingerprint; Evidence Run’a bağlı; **ayrı Result entity yok**
- AI: `laravel/ai`; key environment’ta
- Event: `{kebab-module}.{kebab-action}`
- Prensip: framework’ün çözdüğünü tekrar yazma (ADR-033)
- Website Diagnosis katalog: diagnosis fazı öncesi `docs/website/DIAGNOSIS_CATALOG.md` (Core blocker değil)

### Pratik

- SaaS, Client Portal, harici write, marketplace/ZIP, custom migrator/FSM ekleme.
- MVP Core listesi dışında Attachments/Tags/feature-flags/ağır health-audit zorunlu sayma.

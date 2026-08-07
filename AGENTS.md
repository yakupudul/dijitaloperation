# AGENTS.md

## Cursor Cloud specific instructions

Ürün gerçeği: `docs/MASTER_SPEC.md` (+ roadmap, foundation, module-sdk).

### Kilit kararlar

- Moximu **iç** operasyon; SaaS / Workspace / müşteri girişi yok
- Harici **write action yok**
- Tek Filament panel: id `app`, path `/app`; `web` guard; `spatie/laravel-permission`
- Modüller: `app-modules/` + `internachi/modular`
- AI: `laravel/ai`; key environment’ta
- Event: `{kebab-module}.{kebab-action}`
- Website Diagnosis katalog: Faz 4 öncesi `docs/website/DIAGNOSIS_CATALOG.md` (Core blocker değil)

### Pratik

- Uygulama iskeleti yoksa paket kurup servis ayağa kaldırmayın; dokümana uyun.
- SaaS, Client Portal, harici write eklemeyin.

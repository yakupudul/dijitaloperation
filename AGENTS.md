# AGENTS.md

## Cursor Cloud specific instructions

`dijitaloperation` (DOP) şu an **dokümantasyon ağırlıklı** bir depodur. Ürün gerçeğinin tek kaynağı:

- `docs/MASTER_SPEC.md`
- `docs/IMPLEMENTATION_ROADMAP.md`
- `docs/foundation/*`
- `docs/module-sdk/*`
- `docs/current-state/*` (tarihsel analiz; çelişirse MASTER_SPEC geçerli)

### Ürün özeti (ajanlar için)

- Moximu ajansı **iç** operasyon sistemi; SaaS / müşteri girişi / Workspace **yok**
- Harici sistemlerde **write action yok**
- Stack kararı: Laravel 13, PHP 8.3+, Filament 5, Livewire, MySQL 8, database queue, Pest
- Modüller: yerel Composer / Filament plugin; marketplace yok

### Pratik kurallar

- Bu ortamda uygulama iskeleti henüz yoksa bağımlılık kurup servis ayağa kaldırmayın; önce dokümanlara uyun.
- Yeni özellik eklerken MASTER_SPEC dışına (SaaS, Client Portal, harici write) çıkmayın.
- Event isimleri: `{kebab-module}.{kebab-action}` (ör. `website-diagnosis.scan-completed`).

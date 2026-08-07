# MODULE_LIFECYCLE

> Ana kaynak: `docs/MASTER_SPEC.md`  
> ADR-035 (MVP registry), ADR-033 (framework’ü tekrar yazma)

## MVP (zorunlu)

### Durumlar

Yalnızca:

| Durum | Anlam |
|-------|--------|
| `enabled` | DOP UI / schedule / analysis aktif |
| `disabled` | DOP UI / schedule / analysis kapalı; kod Composer’da kalabilir; **veri silinmez** |

Minimal registry alanları: `module_id`, `enabled`/`disabled`, isteğe bağlı bilgisel `installed_version`.

### Davranış

* Enable/disable kurulum genelidir (tek ajans).  
* Disabled modül navigasyonda görünmez; analysis/scheduled DOP işleri çalışmaz.  
* Composer package remove ≠ veri purge.  
* Package discovery / Service Provider: **Laravel + internachi/modular**.  
* Migrations: **Laravel migration** yolu; custom migrator yok.

## Future / non-MVP (implementasyonu zorlamaz)

Aşağıdakiler belgede kavramsal olarak durur; MVP’de geliştirilmez:

* `discovered` / `registered` / `failed` / `uninstalled` kapsamlı FSM  
* `core.min` / `core.maxExclusive` uyumluluk kapısı  
* custom migration registry  
* otomatik purge / uninstall cascade  
* runtime ZIP/plugin install  

## Gerekçe

Hafif MVP; framework özelliklerini yeniden yazmamak.

## Sınırlar

* “failed boot” için pratikte log + disabled bırakmak yeter; ayrı state machine şart değil.

## Açık Sorular

Yok.

# TERMINOLOGY

> Ana kaynak: `docs/MASTER_SPEC.md`

## Kararlar

| Terim | Anlam |
|-------|--------|
| DOP / MoxDOP | Moximu iç Dijital Operasyon Platformu |
| Panel `app` | Tek Filament panel; path `/app` |
| Admin / Team Member | MVP roller |
| Customer / Brand / Digital Asset / Connection | Sahiplik zinciri |
| Run | Toplama/teşhis çalıştırması |
| Evidence | Run’a bağlı kanıt |
| Finding | Asset’te kalıcı bulgu; fingerprint ile upsert |
| fingerprint | Finding kimliği (run’lar arası) |
| Recommendation | Finding’e bağlı öneri |
| Task | Manuel oluşturulmuş iç görev (snapshot) |
| Result (entity) | **MVP’de yok**; sonuç Finding/Run ile izlenir |
| Minimal Module Registry | id + enabled/disabled (+ bilgisel version); operator UI = business modules only |
| Integration | External provider/service connection (≠ Module) |
| Module | Business/domain capability (≠ Integration/Agent/Skill) |
| Agent | Bounded AI workflow/persona (**planned**; ≠ Module) |
| Skill | Versioned analytical methodology (**planned**; ≠ Module) |
| Memory | Institutional + operational + Skill + learned layers — see `docs/product/KNOWLEDGE_MEMORY_ARCHITECTURE.md` |
| RAG | Retrieval-Augmented Generation (≠ “AI memory”); vector RAG not current |
| `app-modules/` + `internachi/modular` | Modül paketleme temeli |
| Workspace | MVP’de yok |

## Gerekçe

Finding vs Run ve Result’ın kaldırılması ortak dilde netleşmeli.

## Sınırlar

* UI etiketleri uygulama temasında seçilebilir.

## Açık Sorular

Yok.

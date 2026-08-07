# EVENT_ARCHITECTURE

> Ana kaynak: `docs/MASTER_SPEC.md`  
> Normatif: `docs/module-sdk/EVENT_CONTRACT.md`  
> İlgili ADR: ADR-009, ADR-013, ADR-016, ADR-021

## Kararlar

1. Event’ler gevşek bağlı birincil iletişim yoludur.  
2. İsim: `{kebab-module}.{kebab-action}`; çekirdek `core.*`; snake_case yok; workspace event yok.  
3. Taşıyıcı: Laravel events; uzun iş database queue job.  
4. Akış: Customer → … → Result.  
5. Dinleyici hatası yayıncıyı düşürmez; at-least-once; idempotent dinleyici.  
6. Website Diagnosis adayları: `website-diagnosis.scan-requested|scan-completed|evidence-collected|findings-ready|recommendations-ready`; `core.task.create-requested` (manuel iç dönüşüm; harici write değil).

Finding için hem tekil `*.finding-created` hem toplu `findings-ready` kullanılabilir; zorunlu minimum Website Diagnosis’te `findings-ready` + Recommendation hazır event’leridir (uygulama ayrıntısı ürün kapsamını değiştirmez).

## Gerekçe

Tek isimlendirme + Laravel events MVP için yeterlidir.

## Sınırlar

Exactly-once, saga, MCP yok.

## Açık Sorular

Yok (Core bloker değil).

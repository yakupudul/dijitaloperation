# EVENT_ARCHITECTURE

> Ana kaynak: `docs/MASTER_SPEC.md`  
> Normatif isim: `docs/module-sdk/EVENT_CONTRACT.md`  
> Taşıyıcı MVP: Laravel events

## Kararlar

1. Event’ler gevşek bağlı iletişimdir.  
2. İsim: `{kebab-module}.{kebab-action}`; `core.*`; snake_case yok.  
3. Akış: Customer → … → Task (Result entity yok).  
4. Finding güncellemeleri fingerprint upsert sonrası event üretebilir (`*.finding-upserted` vb. — uygulama detayı).  
5. Uzun iş database queue job.  
6. Website Diagnosis adayları: `website-diagnosis.scan-requested|scan-completed|evidence-collected|findings-ready|recommendations-ready`.

## Gerekçe

Laravel events yeter; ayrı bus/schema registry MVP’de yok (ADR-033).

## Sınırlar

Exactly-once / saga / MCP yok.

## Açık Sorular

Yok.

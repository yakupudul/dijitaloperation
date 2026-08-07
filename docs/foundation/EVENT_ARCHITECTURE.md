# EVENT_ARCHITECTURE

> Ana kaynak: `docs/MASTER_SPEC.md`  
> Normatif isimlendirme: `docs/module-sdk/EVENT_CONTRACT.md`  
> İlgili ADR: ADR-009, ADR-013, ADR-016

## Kararlar

### 1. Event’lerin rolü

Event’ler modüller arası ve modül–çekirdek arası birincil gevşek bağlı iletişim yoludur.

### 2. İsimlendirme standardı

| Parça | Biçim |
|-------|--------|
| Modül kimliği | kebab-case |
| Event action | kebab-case |
| Ayırıcı | `.` |
| Tam type | `{moduleId}.{eventName}` |
| Çekirdek | `core.{eventName}` |

Örnek: `website-diagnosis.scan-completed`  
Snake_case kullanılmaz. Workspace event’leri MVP’de yoktur.

### 3. Ürün akışı ile hizalama

```text
Customer → Brand → Digital Asset → Connection → Run → Evidence → Finding → Recommendation → Task → Result
```

### 4. Job ve event

Uzun toplama/teşhis job’da çalışır (database queue). Job aşamaları event üretebilir. Dinleyiciler kısa olmalı.

### 5. Güvenilirlik ilkeleri

* Dinleyici ayakta varsayılmaz  
* Dinleyici hatası yayıncıyı düşürmez  
* Harici API: retry, timeout, rate-limit  
* Taşıyıcı: Laravel events (başlangıç); broker yok  

### 6. Website Diagnosis aday event’leri

* `website-diagnosis.scan-requested`  
* `website-diagnosis.scan-completed`  
* `website-diagnosis.evidence-collected`  
* `website-diagnosis.findings-ready`  
* `website-diagnosis.recommendations-ready`  
* `core.task.create-requested` (manuel dönüşüm / iç akış; harici write değil)

## Gerekçe

Tek isimlendirme + Laravel events, MVP için yeterli gevşek bağlılık sağlar.

## Sınırlar

* Exactly-once yok.  
* Saga motoru yok.  
* MCP/multi-agent yok.

## Açık Sorular

1. Finding oluşturulunca tek event mi (`*.finding-created`) yoksa toplu `findings-ready` mi yeterli?
2. Recommendation → Task dönüşümü event mi, yoksa yalnızca senkron core servis mi?

# JOB_CONTRACT

> Ana kaynak: `docs/MASTER_SPEC.md`  
> Dayanak: ADR-013, ADR-021 (database queue + Laravel scheduler)  
> İlgili: `EVENT_CONTRACT.md`, `MODULE_LIFECYCLE.md`, `ERROR_ISOLATION.md`

## Amaç

Background job tanımlama, kaydetme, çalıştırma ve hata politikası.

## Kararlar

### 1. Ne zaman job zorunlu?

Aşağıdakiler HTTP request path’inde **çalıştırılamaz**:

- Website crawl / tarama  
- Harici API’den toplu senkron  
- Uzun AI çıkarımı  
- Toplu rapor üretimi  
- 2 saniyeyi aşması beklenen her iş (kılavuz eşik; sert SLA değil)

### 2. Job kimliği

| Kural | Değer |
|-------|--------|
| Biçim | `{moduleId}.{jobName}` |
| `jobName` | kebab-case |
| Örnek | `sample-module.refresh-notes` |

### 3. Manifest kaydı

```json
"jobs": [
  {
    "id": "sample-module.refresh-notes",
    "title": "Refresh sample notes",
    "queue": "default",
    "maxAttempts": 5,
    "timeoutMs": 60000,
    "concurrency": 1,
    "retry": {
      "strategy": "exponential",
      "initialDelayMs": 1000,
      "maxDelayMs": 300000
    }
  }
]
```

| Alan | Zorunlu | Açıklama |
|------|---------|----------|
| `id` | Evet | Global unique job type |
| `title` | Evet | İnsan okunur |
| `queue` | Evet | Mantıksal kuyruk adı (`default`, `crawl`, …) |
| `maxAttempts` | Evet | Toplam deneme |
| `timeoutMs` | Evet | Tek deneme üst süresi |
| `concurrency` | Hayır | Aynı job type eşzamanlılık; varsayılan çekirdek politikası |
| `retry` | Evet | Retry politikası |

### 4. Job payload zarfı

| Alan | Açıklama |
|------|----------|
| `jobId` | Çalıştırma örneği UUID |
| `type` | Manifest job `id` |
| `moduleId` | Sahip modül |
| `requestedBy` | user id veya `system` |
| `correlationId` | Zincir |
| `data` | Job’a özel payload (entity id’ler burada) |
| `attempt` | 1-based |

MVP’de `workspaceId` yoktur.

### 5. Kayıt ve çalışma kuralları

1. Job handler’ı modül `enabled` iken register edilir  
2. Modül `disabled`/`failed` iken **yeni** job enqueue reddedilir veya no-op  
3. Zaten kuyruktaki job: worker modül durumunu kontrol eder; disabled ise job `cancelled` + log  
4. Harici API çağrıları: timeout, retry, rate-limit zorunlu  
5. Başarı/başarısızlık anlamlı event üretebilir (`*.job-completed`, `*.job-failed`)  
6. Job içinden başka modül private servisi import edilmez  

### 6. Çekirdek Job API (sözleşme düzeyi)

Modüller yalnızca çekirdek API kullanır:

- `enqueue(type, data, options)`  
- `schedule(type, cronOrDelay, data)` — cron ifadesi desteği uygulama aşamasında  
- `cancel(jobId)`  

Teknoloji: Laravel queue (**database** driver başlangıç) + scheduler. Redis/Horizon yok (ihtiyaç ADR’si ile).

### 7. Log alanları

Her job log satırında zorunlu:

- `module_id`  
- `job_type`  
- `job_id`  
- `correlation_id`  
- `attempt` 

## Gerekçe

Crawl ve connector senkronları HTTP’yi kilitlemeden ölçeklenir; retry/rate-limit connector kırılganlığını sınırlar.

## Sınırlar

- Queue: Laravel database driver (Horizon yok).  
- Zamanlama: Laravel scheduler / cron ifadeleri.  
- Priority kuyrukları v1’de opsiyonel.

## Migration Impact

| Mevcut durum | Etki |
|--------------|------|
| Job/queue yok (`docs/current-state`) | Worker + registry + enqueue API sıfırdan |
| Teknoloji yok | Bu sözleşme adaptör arkasında uygulanmalı |
| Foundation “ağır işler job” | Bu belge job id, retry ve disable davranışını somutlaştırır |

## Açık Sorular

Yok. Concurrency application settings / queue config ile. Dead-letter admin UI v1’de opsiyonel.

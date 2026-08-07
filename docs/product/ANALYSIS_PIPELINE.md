# Analysis Pipeline

## Purpose

Harici okumadan ajans içi Task'a kadar kanıta dayalı teşhis boru hattını tanımlar.

## User value

Ekip 'neden?' sorusuna Evidence ile cevap verir; AI yalnızca kanıtı açıklar.

## Core concepts

External sources → Read → Collect → Normalize → Evidence → deterministic/rule checks → Finding → AI interpretation → Recommendation → manual Task → later Runs → Finding lifecycle outcome tracking.

* Evidence = Run-bound
* Finding = persistent Digital Asset-level (ADR-034)
* fingerprint ile duplicate yok
* Recommendation Finding'e bağlanabilir
* Task manuel (ADR-025); snapshot ADR-029
* Ayrı Result entity yok (ADR-036)

## MVP behavior

* Run bir asset/connection kapsamında çalışır
* Evidence normalize edilmiş kanıttır
* Finding upsert (fingerprint)
* Görülmeyen Finding resolved olabilir
* Recommendation'dan kullanıcı Task oluşturur
* Assignee/due date AI uydurmaz
* AI'dan önce deterministic katman tercih edilir

## Important data / attributes

Run status/timings; Evidence payload/type; Finding fields per ADR-034; Recommendation text/priority; Task snapshot fields per ADR-029.

## Relationships

Asset → Run → Evidence; Asset → Finding → Recommendation → Task; Finding.last_run_id.

## Main screens / workflows

Run detail + evidence; Findings list/detail; Recommendations; Task board/list.

## Rules / invariants

AI kanıtın yerine geçmez. Harici write yok. Result entity yok. Framework log/queue kullanılır; özel ağır audit/health zorunlu değil.

## Derived information

Outcome = later runs + Finding status, not Result rows.

## Later enhancements

Richer scoring, auto-priority hints, cross-finding clustering.

## Explicit non-goals

Autonomous external remediation; uncontrolled AI; Result domain.

## Acceptance intent

Pipeline kanıt → bulgu → öneri → iç görev zincirini Result olmadan taşır.

# Analysis Pipeline

## Purpose

Harici okumadan ajans içi Task'a kadar kanıta dayalı teşhis boru hattını tanımlar.

## User value

Ekip 'neden?' sorusuna Evidence ile cevap verir; AI yalnızca kanıtı açıklar.

## Core concepts

External sources → Read → Collect → Normalize → Evidence → deterministic/rule checks → Finding → AI interpretation → Recommendation → manual Task → human completion → later comparable Finding evaluation → observed Outcome signal on Task.

* Evidence = Run-bound
* Finding = persistent Digital Asset-level (ADR-034)
* fingerprint ile duplicate yok
* Recommendation Finding'e bağlanabilir
* Task manuel (ADR-025); snapshot ADR-029
* Ayrı Result entity yok (ADR-036)
* Outcome = Task üzerindeki gözlemlenmiş sinyal (`docs/product/OPERATIONAL_OUTCOME_LOOP.md`) — nedensel attribution değil

## MVP behavior

* Run bir asset/connection kapsamında çalışır
* Evidence normalize edilmiş kanıttır
* Finding upsert (fingerprint)
* Görülmeyen Finding resolved olabilir
* Recommendation'dan kullanıcı Task oluşturur
* Assignee/due date AI uydurmaz
* AI'dan önce deterministic katman tercih edilir
* Task completion Finding'i resolve etmez; Outcome sonraki Finding lifecycle değerlendirmesinden gelir
* Outcome sınıflandırması V1'de deterministic'dir (AI yok)

## Important data / attributes

Run status/timings; Evidence payload/type; Finding fields per ADR-034; Recommendation text/priority; Task snapshot fields per ADR-029; Task completion + Outcome columns (`completed_at`, `completed_by_id`, `completion_note`, `outcome_review_after_at`, `outcome_status`, `outcome_checked_at`, `outcome_run_id`, `outcome_json`).

## Relationships

Asset → Run → Evidence; Asset → Finding → Recommendation → Task; Finding.last_run_id; Task.outcome_run_id (follow-up provenance).

## Main screens / workflows

Run detail + evidence; Findings list/detail; Recommendations; Tasks workspace (Before / Action / After / Outcome).

## Rules / invariants

AI kanıtın yerine geçmez. Harici write yok. Result entity yok. Framework log/queue kullanılır; özel ağır audit/health zorunlu değil. Outcome nedensellik iddia etmez.

## Derived information

Outcome = later comparable Finding evaluations + Finding status, stored as current signal on Task — not Result rows.

## Later enhancements

Module-specific metric Outcome signals; Learning Candidates; Playbooks; Digital Operations Analyst.

## Explicit non-goals

Autonomous external remediation; uncontrolled AI; Result domain; causal attribution engine.

## Acceptance intent

Pipeline kanıt → bulgu → öneri → iç görev → gözlemlenmiş Outcome zincirini Result olmadan taşır.

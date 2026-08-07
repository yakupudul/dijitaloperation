# DOP Development Autopilot

DOP ürün geliştirme döngüsü **kullanıcıdan routine prompt / review / merge istemeden** çalışır.

## Tek gerçek akış

**DOP Autopilot** (`.github/workflows/dop-autopilot.yml`)

1. **Architect (OpenAI)** — roadmap + MASTER_SPEC + product blueprints’ten sıradaki küçük işi seçer  
2. **Cursor Implementer** — yalnızca o işi kodlar / lokal test çalıştırır  
3. **Quality Gates** (`.automation/scripts/quality_gates.sh`) — CI env bootstrap (`.env` from example + ephemeral `key:generate`), secret scan, composer validate, PHPUnit (sqlite/:memory: via `phpunit.xml`), Pint, automation tests  
4. **PR** — `<!-- DOP_AUTOMATION_PR -->` marker ile açılır  
5. **OpenAI Reviewer** — aynı workflow run içinde product specs + diff inceler  
6. **Cursor Fixer** — en fazla 3 düzeltme turu  
7. **Final Gates** — tekrar kalite + mergeability  
8. **Auto Merge** — squash + branch sil  
9. **repository_dispatch (`dop-next-task`)** — sıradaki Autopilot run  

## Ne zaman durur?

* `ROADMAP_COMPLETE`
* `HUMAN_REQUIRED` / hard blocker issue (`<!-- DOP_HARD_BLOCKER -->`)
* test / secret / suspicious-diff failure
* 3 fix sonrası çözülmeyen sorun
* boş diff / tekrarlanan task

Bu durumlarda **yeni dispatch yok**.

## Secrets

Repository Actions secrets:

* `OPENAI_API_KEY`
* `CURSOR_API_KEY`

Opsiyonel vars:

* `OPENAI_ARCHITECT_MODEL` (default **gpt-5-mini**)
* `OPENAI_REVIEWER_MODEL` (default **gpt-5-mini**)
* `OPENAI_ESCALATION_MODEL` (default **gpt-5**, yalnız escalation)
* `OPENAI_REASONING_EFFORT` (default **low**)
* `CURSOR_AGENT_MODEL`

Secret değerleri log/prompt/artifact’a yazılmaz. Usage metrikleri (token counts) step summary’ye yazılır.

## Branch isolation

Product Autopilot PRs are always created from a freshly fetched `origin/main`.

* `.automation/scripts/prepare_product_branch.sh` — detach/reset to `origin/main`, apply product patch
* `.automation/scripts/assert_product_branch_infra.sh` — fail if three-dot diff touches `.github/` or `.automation/` unless Architect `files_or_areas` explicitly allows it

This prevents stale-base races where a concurrent workflow commit on `main` makes GitHub reject the product branch push (`workflows` permission).

## Merge invariant (fail-closed)

Product PR merge requires **both**:

1. Verified runtime Reviewer evidence with `verdict == APPROVED` for the **current PR HEAD SHA**
2. All deterministic final gates PASS

Missing/invalid OpenAI key, reviewer process failure, missing review JSON, `FIX_REQUIRED`, `HUMAN_REQUIRED`, or approved-SHA ≠ HEAD **cannot merge**.

Local Cursor maintenance agents must **not** bypass Autopilot by merging product PRs when Reviewer is unavailable. Review/merge stays in GitHub Actions (where `OPENAI_API_KEY` exists as a secret).

## Token economy

* Stable prefix: `.automation/context/CORE_RULES.md` (MASTER_SPEC yerine compact rules)
* Architect yalnız sıradaki domain candidate blueprint’lerini yükler (`docs/product/**` tamamı değil)
* Reviewer: CORE_RULES + `product_spec_paths` + ilgili ADR excerpt + diff/tests
* Normal başarılı task hedefi: **1 Architect + 1 Reviewer** OpenAI call
* Escalation modeli normal akışta çağrılmaz

## Manuel ilk start

Actions → **DOP Autopilot** → Run workflow (bir kez).  
Sonrası `repository_dispatch` zinciriyle devam eder.

## Product memory

Architect/Reviewer/Implementer `docs/product/**` blueprint’lerini kullanır.  
`TASK_READY` için `product_spec_paths` boş olamaz.

## Yerel testler (API yok)

```bash
python -m unittest discover -s .automation/tests -v
```

## Maliyet

OpenAI (architect + her review) + Cursor (implement + fix).  
1 task / run ve max 3 fix limiti maliyeti sınırlar.

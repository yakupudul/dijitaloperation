# DOP Development Autopilot

DOP ürün geliştirme döngüsü **kullanıcıdan routine prompt / review / merge istemeden** çalışır.

## Tek gerçek akış

**DOP Autopilot** (`.github/workflows/dop-autopilot.yml`)

1. **Architect (OpenAI)** — roadmap + MASTER_SPEC + product blueprints’ten sıradaki küçük işi seçer  
2. **Cursor Implementer** — yalnızca o işi kodlar / lokal test çalıştırır  
3. **Quality Gates** — secret scan, composer validate, PHPUnit, Pint, automation tests  
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

Opsiyonel vars: `OPENAI_ARCHITECT_MODEL`, `OPENAI_REVIEWER_MODEL`, `CURSOR_AGENT_MODEL`

Secret değerleri log/prompt/artifact’a yazılmaz.

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

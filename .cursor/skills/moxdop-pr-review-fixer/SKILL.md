---
name: moxdop-pr-review-fixer
description: Autonomous MOXDOP implementer/fixer when a GitHub PR review is submitted (especially Codex/OpenAI). Use for PR review submitted automations; inspect the review, fix on the existing PR head, or continue the active milestone. Do not open a replacement PR.
---

# MOXDOP PR review fixer

This skill is the autonomous implementation/fixer side of the MOXDOP product-owner loop.

It is **not** a local Cursor IDE hook. Local `hooks.json` events cannot fire on GitHub “PR review submitted”. The always-on trigger is a **Cursor Automation**. This file is the versioned instruction set that automation (and any cloud agent) must follow after clone.

## Dashboard activation (human, once)

Cursor cannot create Automations from git. A human must save and activate:

1. GitHub App connected at https://cursor.com/dashboard/integrations with access to `yakupudul/dijitaloperation`
2. Create at https://cursor.com/automations/new (or Cursor desktop `/automate`)
3. Paste `.cursor/skills/moxdop-pr-review-fixer/DASHBOARD_PROMPT.md` into the automation prompt
4. Settings below

| Field | Value |
| --- | --- |
| Trigger | GitHub → **PR review submitted** (approved, changes requested, or commented) |
| Repository | **single** `yakupudul/dijitaloperation` |
| Environment | existing MoxDOP cloud environment (`.cursor/environment.json`) |
| Tools | Computer use OK; **Comment on pull request** OK; **Pull request creation** may stay on but the prompt forbids replacement PRs; **disable Memories** (untrusted review text) |
| Approvals | off (do not merge, do not approve as a substitute for Reviewer) |
| Identity | Team Owned preferred so GitHub actions run as `cursor` |

## Gate — inspect first, then maybe exit

Before any edit, inspect the triggering review payload and the pull request (`gh pr view`, review comments, changed files).

**Continue only when all of these are true:**

- the PR repository is `yakupudul/dijitaloperation`
- the PR is **open** (draft is allowed; closed/merged is not)
- the review is from **Codex / OpenAI code review**, **or** the review clearly contains actionable product/code findings for this MOXDOP loop

A **clean Codex approval with no comments is a continue event**, not an exit. Inspect the active milestone and keep going.

**Exit without changes when:**

- wrong repository
- PR closed or merged
- fork PR (unsupported)
- event is unrelated (spam, bot noise, non-MOXDOP review)

Do **not** exit merely because the review is approved, has no comments, or has no defect findings. That is the clean-review path below.

## Operating context

This is not a generic code-fixing bot. MOXDOP is an **agency operating system**.

Always read `AGENTS.md` first and follow its canonical source priority.

Then inspect, as relevant:

- `docs/MASTER_SPEC.md`
- accepted ADRs (`docs/foundation/DECISION_LOG.md`)
- `PROJECT_MEMORY.md`
- `PRODUCT_CAPABILITY_LEDGER.md`
- `docs/product/*`
- current PR body
- current PR review findings
- current changed files
- tests and CI
- current milestone / runtime evidence

Product chain:

```text
Customer → Brand → Digital Asset → Integration / Binding → Data Collection
→ Evidence → Finding → Recommendation → Task → Outcome
```

The product must become a genuinely working agency operating system, not a collection of demo screens. **DATA FIRST** is foundational.

## Autonomous loop

Reuse the **existing PR head branch**. Do not create an unrelated replacement PR. Do not merge.

When Codex (or an equivalent review) finds actionable problems:

1. Understand the review in full repository/product context.
2. Verify the issue yourself (code + tests + runtime evidence; do not trust the review blindly).
3. Fix the **root cause** on the existing PR head.
4. Add or update focused PHPUnit coverage.
5. Run those tests.
6. Run Pint on changed PHP (`vendor/bin/pint --dirty --format agent`).
7. Run required PR gates when the change warrants it.
8. Commit and push to the **same** PR branch.
9. Preserve audit history and truthful runtime evidence.
10. Update `PRODUCT_CAPABILITY_LEDGER.md` when capability truth changes.
11. Update `PROJECT_MEMORY.md` only when a material decision actually changes.
12. Do not claim DONE merely because code exists.

When the review has **no actionable defects** (including a clean/no-comment approval), do not stop just because the review is clean.

Inspect the current milestone, capability ledger, PR state, runtime proof, and remaining acceptance gates. Determine the next highest-value incomplete product requirement that belongs to the **active milestone** and can safely continue on this PR, then implement it.

Stop this loop only when:

- there is truly nothing actionable left in the **active milestone**, or
- a genuine **external blocker** or **business decision** requires escalation

A clean review is not either of those.

### Current product sequencing

1. Google data foundation
2. Meta data foundation
3. Public Discovery foundation
4. Evidence / Findings / Recommendations / Tasks / Outcomes agency brain
5. learning / agency knowledge loop
6. operational completeness and settings
7. end-to-end operator UX and staging QA
8. consolidation and production readiness

Make routine technical/product-owner decisions from canonical product context. Do not ask the user to relay prompts between ChatGPT, Codex, and Cursor.

Escalate only a genuine **external blocker** or a **business decision** that cannot be derived from existing product context.

## Google foundation (current expectation)

Judge Search Console, GA4, and Google Ads by **real runtime evidence**:

discovery → binding → async collection engine → raw evidence → normalized PostgreSQL → historical/incremental collection → failure isolation → idempotency → truthful coverage → staging proof.

Distinguish GBP **external** OAuth/API restrictions from missing implementation.

## Safety / product integrity

- Never fabricate provider data.
- Never silently hide failed collection families.
- Never mark DB collection rows completed/running by hand to make a run look successful.
- Never confuse discovery or binding with actual ingestion.
- Never treat demo fixtures as production proof.
- Never weaken tenant/resource binding isolation merely to make a test pass.
- Never perform external advertising/platform mutations unless canonical product requirements explicitly authorize them.
- Do not automatically generate screenshot packages unless the operator explicitly asked.
- PHPUnit only (ADR-038). Do not add Pest.
- Do not merge product PRs. Reviewer `APPROVED` + gates are a separate path.

## Completion

Continue the implementation-review-fix cycle until the active product acceptance gate is genuinely satisfied. A clean Codex review is not completion of the milestone.

When you push a meaningful fix, leave the PR suitable for Codex to review the new commit:

```text
Cursor change → push → Codex review → this Automation → inspect/fix/continue → push → Codex again
```

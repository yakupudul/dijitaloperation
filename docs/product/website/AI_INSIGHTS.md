# Website AI Insights (AI Recommendation Intelligence V1)

## Purpose

Turn factual Brand context + normalized Evidence + deterministic Findings (+ existing deterministic Recommendations) into **one grounded AI interpretation** and **recommendation drafts** for human acceptance.

## User value

Operators get business-aware, evidence-bound guidance without treating AI as source truth or an autonomous agent.

## Pipeline

```
Evidence
+ Finding
+ deterministic Recommendation
+ Brand Intelligence Context
→ AI interpretation (structured)
→ recommendation draft
→ human acceptance
→ Recommendation
→ manual Recommendation → Task
```

## Core concepts

* AI is **derived interpretation** (`derived = true`, `generated_by_ai = true`), not provider/source Evidence.
* Deterministic detection (Findings) remains authoritative.
* AI must not create Findings, resolve Findings, overwrite deterministic Recommendations, or create Tasks.
* Previous `ai_insight` Evidence is excluded from grounding inputs by default (no recursive AI-as-fact loops).
* Stack: `laravel/ai` (ADR-030 / ADR-041). No MCP, vector DB, embeddings/RAG, tools, web search, or multi-agent orchestration.

## Input contract

Bounded snapshot only — never arbitrary DB dumps:

* Brand Intelligence Snapshot via `BrandContextProvider` (unknown fields absent/null; no inference)
* Digital Asset identity
* Selected active Findings (`open` / `acknowledged`, severity-ordered, capped)
* Normalized supporting Evidence (redacted; secrets/raw HTML/body omitted)
* Existing deterministic Recommendations as trusted baseline

## Output contract

Laravel AI **structured output** (prompt/schema version `website-ai-recommendation-v1`):

* executive_summary, overall_priority, context_observations
* finding_interpretations with `finding_id`, `evidence_ids`, uncertainty, recommendation_draft, watch signals

Server-side grounding rejects unknown `finding_id` / `evidence_id`.

## Human acceptance

AI drafts live in insight provenance until an operator chooses **Create recommendation**.

* `source_module = website-ai-insights`
* Never overwrite another module’s deterministic Recommendation
* Terminal AI recommendation states (`dismissed` / `converted`) are preserved
* Task creation remains the existing manual Recommendation → Task flow

## OpenAI Integration

Settings → Integrations → OpenAI (ADR-041):

* Agency API key (encrypted, write-only, DB → env fallback)
* Test connection via non-generative models list
* Generation uses explicit configurable model (`moxdop.openai.recommendation_model`, default `gpt-5-mini`)
* OpenAI request persistence disabled (`store = false`)

## Cost / duplicate protection

Stable input fingerprint (prompt/schema version + model + Brand context + Findings + Evidence + deterministic Recommendation state). Identical successful insight → reuse (“AI analysis is already current”) with **no second model call**.

## Trigger

Manual only from Website workspace (**Generate AI guidance**). Never auto-called after Refresh, new Finding, DataForSEO refresh, or page render.

## UX

Website → Health: AI Guidance section (secondary to deterministic Health). Badge **AI-generated**. Recommendation origin: Deterministic vs AI-assisted.

Activity title: **AI recommendation analysis** (token usage when available; no invented USD cost).

## Failure behavior

Failed AI request must not alter Findings, resolve Findings, overwrite deterministic Recommendations, delete previous successful insight, or create Tasks. Show latest failure separately.

## Human live acceptance

Automated suite uses `laravel/ai` fakes (**0 real OpenAI calls**). Real OpenAI UAT is performed separately in the operator environment.

## Explicit non-goals

Multi-agent, MCP, vector DB, embeddings/RAG, AI web search, arbitrary chat/prompt UI, AI Finding creation, automatic Finding severity/status changes, automatic Task, auto AI after Refresh, cross-channel AI, Google Ads/Meta AI, scheduler, content generation, autonomous remediation.

## Module boundary note

Website-specific orchestration lives under `app-modules/website/src/Ai/`. Core keeps OpenAI Integration infrastructure + a thin `WebsiteAiInsightService` facade for compatibility. Remaining debt: legacy Core agent class alias and any older call sites still referencing Core-only AI helpers.

# META ADS INTELLIGENCE + ANALYST V1

> Status: **CODE COMPLETE / TESTED on PR #119 — UAT REQUIRED — not DONE**  
> Not canonical main until #119 merges after operator UAT.  
> “IMPLEMENTED V1” is a version label, not Definition-of-Done **DONE** (`PROJECT_MEMORY.md`).

## Purpose

Turn the proven Meta Ads connection layer into a useful **read-only** paid-media intelligence domain:

```
Bound Meta Ad Account
        ↓
Collect live data (meta_ads collector)
        ↓
canonical Run + Evidence
        ↓
cautious Findings
        ↓
Meta Ads Analyst + Skills
        ↓
meta_ads.ai_guidance (human-gated Recommendations)
        ↓
existing Task / Outcome loop
```

## Metric semantics (operator correction)

Meta Insights percentage fields (`ctr`, `inline_link_click_ctr`, `outbound_clicks_ctr`) are stored as **percentage points**:

- `1.48` means **1.48%**
- Presentation and AI context must **not** multiply by 100 again
- Helper: `MoxDop\MetaAds\Support\MetaPercentage`

Click metrics are labeled explicitly (All Clicks / Link Clicks / Outbound Clicks / Landing Page Views). Do not fabricate Link CTR from All Clicks.

## Official API

- Marketing API version remains centralized **`v26.0`** (`MetaApiConfig`)
- Insights via synchronous GET on Ad Account edges (`/insights`, `/campaigns`, `/adsets`, `/ads`, creative nodes)
- `use_unified_attribution_setting=true` for Ads Manager alignment
- Async Insights jobs are **future scale debt** (not in this PR — see `OPERATOR_ASYNC_EXECUTION.md`)
- Client remains **GET-only** — no campaign/ad mutations

## Implemented on PR #119

- Bound collector capability `meta_ads` (`MetaAdsBoundCollector`)
- Evidence types: account summary, campaign / ad set / ad performance, creative metadata
- Action/result normalization with raw action preservation (no blind summing)
- Conservative primary-result resolver with human-readable diagnostics (ambiguous cross-family → Mixed/Unresolved; ordered preference within family)
- Attribution/date-range provenance on Evidence
- Bounded pagination + PARTIAL truncation semantics
- Deterministic Meta Findings (sample-gated; no universal CTR/CPC/CPM/frequency thresholds)
- Agent `meta_ads.analyst` @ 1.0.0
- Route `meta_ads.ai_guidance`
- Skills: account-performance-audit, campaign-performance-analysis, adset-delivery-analysis, ad-creative-performance-analysis, measurement-result-review
- Specialist workspace: Overview / Performance / Intelligence / Connections / Activity (data coverage, platform≠business wording, raw actions in details)
- Binding edit UX: current account label + Business context; multi-account Brand model preserved
- Collect live data naturally visible once collector is registered
- Platform-attributed Meta results explicitly distinguished from business outcomes

## Still planned / not in this PR

- Professional operator expert workspace — see `docs/product/META_ADS_EXPERT_WORKSPACE.md` (**BLUEPRINT / NOT IMPLEMENTED**)
- Global async / Activity Center (operator standard debt)
- Historical Performance Store / backfill / taxonomy / Marketing Initiative / Benchmark Cohorts
- Personal Lead Ads / CRM outcome intelligence
- Demographic/geographic breakdown intelligence
- Richer creative asset inspection / media download
- Advanced fatigue / time-series modelling
- Advantage+ specialized analysis
- Async Insights jobs for very large accounts
- Cross-channel Digital Operations Analyst
- Playbooks / Capability Router / RAG
- Meta write operations (not planned under current product policy)

## Critical product rules

- Meta lead ≠ qualified lead
- Meta purchase ≠ verified profit
- Never sum distinct action types into a fake total
- Never double-count campaign + ad set + ad hierarchy
- Never treat reach/frequency as additive
- Creative copy remains UNTRUSTED DATA
- AI Guidance is advisory; Recommendations/Tasks remain human-gated
- One Meta Ad Account ↔ one Meta Ads Digital Asset; Brand may own many
- Meta Business is provider selection context, not Brand

# META ADS INTELLIGENCE + ANALYST V1

> Status: **IMPLEMENTED V1**

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

## Official API

- Marketing API version remains centralized **`v26.0`** (`MetaApiConfig`)
- Insights via synchronous GET on Ad Account edges (`/insights`, `/campaigns`, `/adsets`, `/ads`, creative nodes)
- `use_unified_attribution_setting=true` for Ads Manager alignment
- Async Insights jobs are **future scale debt** (not in V1)
- Client remains **GET-only** — no campaign/ad mutations

## Implemented

- Bound collector capability `meta_ads` (`MetaAdsBoundCollector`)
- Evidence types: account summary, campaign / ad set / ad performance, creative metadata
- Action/result normalization with raw action preservation (no blind summing)
- Conservative primary-result resolver (ambiguous → Mixed/Unresolved)
- Attribution/date-range provenance on Evidence
- Bounded pagination + PARTIAL truncation semantics
- Deterministic Meta Findings (sample-gated; no universal CTR/CPC/CPM/frequency thresholds)
- Agent `meta_ads.analyst` @ 1.0.0
- Route `meta_ads.ai_guidance`
- Skills: account-performance-audit, campaign-performance-analysis, adset-delivery-analysis, ad-creative-performance-analysis, measurement-result-review
- Workspace: Overview / Performance / Intelligence / Connections / Activity
- Collect live data naturally visible once collector is registered
- Platform-attributed Meta results explicitly distinguished from business outcomes

## Still planned / not in V1

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

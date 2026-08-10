# Brand Intelligence Context

## Purpose

Give MoxDOP a **factual, structured, operator-editable** understanding of each Brand before richer AI Recommendation systems are introduced.

Context answers:

- What does this company do?
- What does it sell / which offerings matter most?
- Who is it trying to reach?
- Which markets matter?
- What business outcomes / conversions matter?
- How does it position itself?
- Who are known competitors?
- What operational/compliance constraints apply?

## Factual / operator-owned principle

Everything in Brand Intelligence Context must be **explicitly entered** (or later derived with operator confirmation).

- Unknown → leave empty
- Never fabricate audiences, markets, competitors, goals, positioning, or conversions
- Source for V1: `operator`
- No Evidence records for Admin typing Brand facts
- **No AI in this milestone**

## Ownership

Brand-level Core domain data:

```
Brand
  hasOne
BrandIntelligenceContext
```

Multiple Digital Assets / modules (Website, Ads, future GBP, future AI Insights) consume the same Brand context.

Do **not** duplicate Website-specific copies of business summary / audiences / competitors.

## Separation from Evidence

| Brand Intelligence | Evidence |
| --- | --- |
| Operator-owned facts | Run-bound observed data |
| Editable Brand workspace | Collected from providers |
| No provider cost | May consume provider credits |

## Separation from Website SEO market

| Brand target markets | Website SEO market |
| --- | --- |
| Business context | DataForSEO analysis config |
| e.g. Germany, UK, Netherlands | e.g. United Kingdom · English |
| Independent | Independent |

## Fields (V1)

- `business_summary`, `business_model`
- `products_services` (bounded structured list)
- `priority_offerings` (ordered names)
- `target_audiences`, `target_markets`
- `business_goals`, `conversion_goals` (business types — **not** GA4 event maps)
- `positioning`, `differentiators`
- `known_competitors` (name / optional URL / note — no fetching)
- `important_constraints`
- `source`, `updated_by`, timestamps

## Completeness

Lightweight completeness: “6 of 8 key areas completed”.

This is **completeness**, not a Brand Intelligence Score / quality score.

## Future consumer contract

```
BrandContextProvider → BrandIntelligenceSnapshot
```

Normalized read-only facts for modules / future AI. No AI methods on the provider. Modules must not poke random JSON structures ad hoc.

## Intended future pipeline

```
Evidence
+ Finding
+ Brand Intelligence Context
+ small approved intelligence playbook
↓
AI explanation / prioritization / Recommendation
```

AI may enrich interpretation.  
AI must **never** silently modify Brand Intelligence facts.  
Operator remains source of truth unless a future explicit review/approval flow exists.

## Future: Discover brand context (**PLANNED / NOT IMPLEMENTED**)

See [`DISCOVERY_INTELLIGENCE.md`](./DISCOVERY_INTELLIGENCE.md).

Outside-in Discovery may later propose **candidates** (business summary, offerings, locations, social links, positioning signals, potential competitors) from public sources.

Expected UX:

```text
Discover → review candidates → provenance visible → Accept / Edit / Ignore
```

Distinguish:

| Kind | Treatment |
| --- | --- |
| Discovered fact candidate | Attributable public observation (source URL / retrieved_at) |
| AI-derived inference | Explicitly labeled interpretation — never equivalent to operator fact |

Candidates must not silently overwrite operator-maintained Brand Context.

## MarketingSkills reference

`coreyhaines31/marketingskills` `product-marketing` was used as **concept / taxonomy inspiration only**.

MoxDOP does **not** import its runtime, skills framework, files, prompts, or plugin system.

## Explicit non-goals

AI, agents, MarketingSkills runtime, competitor fetching, DataForSEO calls, CRM, personas DB, GA4 event mapping, Tasks/Scheduler/Meta, fake Brand health score, Discovery runtime (planned separately — not implemented here).

## Acceptance intent

Operators can maintain a small, structured Brand business context that future analysis/AI can trust without inventing facts.

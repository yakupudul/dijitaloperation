# AI Control Plane

> **STATUS: PARTIALLY IMPLEMENTED (V1)**  
>  
> **Implemented in V1:** OpenAI + Anthropic + Gemini agency Integrations, workflow-specific AI Routes (`website.ai_guidance`), Laravel native FailoverableException failover, route-step persistence, provider/model provenance.  
> **Still PLANNED / NOT IMPLEMENTED:** Agent Profiles, Skill Library, Memory/Retrieval, vector RAG, aggregator providers (OpenRouter, etc.).  
>  
> Authority order remains: `MASTER_SPEC` → accepted ADRs → product blueprints → this direction doc.  
> Related: [`KNOWLEDGE_MEMORY_ARCHITECTURE.md`](./KNOWLEDGE_MEMORY_ARCHITECTURE.md), Integrations workspace.

---

## 1. Vision

Intended long-term reasoning flow:

```text
Evidence providers
        ↓
    Evidence
        ↓
Deterministic Findings
        +
Brand Intelligence Context
        ↓
    AI Control Plane
        ↓
  AI interpretation
        ↓
 Recommendation Draft
        ↓
   Human approval
        ↓
  Recommendation
        ↓
       Task
```

Product rules (already canonical in MASTER_SPEC / ADR-041 path):

- AI remains **advisory**.
- AI does not create Findings automatically.
- AI does not silently overwrite deterministic Recommendations.
- AI does not open Tasks automatically.
- External platform integrations remain **read-only** (no campaign/budget/ad/creative/content writes).

---

## 2. Current vs planned

| Area | Status |
| --- | --- |
| OpenAI agency Integration + Website AI Recommendation Intelligence V1 | **IMPLEMENTED** (PR #106) |
| Multiple AI provider Integrations (OpenAI, Anthropic, Gemini) | **IMPLEMENTED V1** |
| Route-specific primary/fallback (`website.ai_guidance`) | **IMPLEMENTED V1** |
| Agent Profiles | **PLANNED / NOT IMPLEMENTED** |
| Skill Library | **PLANNED / NOT IMPLEMENTED** |
| Knowledge / Memory architecture (four layers) | **PLANNED** — partially realized via structured Brand Context / Run / Evidence / Finding data; see [`KNOWLEDGE_MEMORY_ARCHITECTURE.md`](./KNOWLEDGE_MEMORY_ARCHITECTURE.md) |
| Vector RAG / embeddings | **NOT IMPLEMENTED** — deferred until knowledge volume justifies it |
| Aggregator providers (OpenRouter, DeepSeek, Groq, …) | **NOT IMPLEMENTED** (V1 proves direct providers only) |
| MCP / unbounded agents | **REJECTED** as MoxDOP core |

Do not describe planned rows as current product functionality.

---

## 3. AI providers

Direction: MoxDOP should eventually support multiple AI providers using the installed **`laravel/ai`** abstraction where practical.

Potential providers may include (non-promise list):

- OpenAI
- Anthropic
- Google Gemini
- OpenRouter
- other providers safely supported by the installed Laravel AI SDK

Provider availability depends on:

- current Laravel AI support
- provider capability
- structured-output compatibility
- security / credential model
- operational value

**Current implemented production providers:** OpenAI, Anthropic, Gemini (agency Integration credentials; OpenAI `store=false` on generation; route-owned models).

Intentionally **not** in V1: OpenRouter, DeepSeek, Groq, xAI, Mistral, Ollama, Bedrock, Azure OpenAI, generic OpenAI-compatible endpoints.

Do not permanently promise every named provider.

---

## 4. AI Routes (not one global ranking)

Do **not** model future AI selection as a single universal global provider order.

Preferred direction: **AI Routes** — workflow-specific routing.

Examples of routes:

- Website Recommendation
- SEO Intelligence
- Google Ads Analysis
- Executive Analysis

Each route may define:

- primary provider + model
- fallback provider/model(s)

Conceptual example:

```text
Website Recommendation
  1. OpenAI / model A     — PRIMARY
  2. Anthropic / model B  — FALLBACK
  3. Gemini / model C     — FALLBACK
```

The best provider may differ by workflow. This routing layer is **NOT IMPLEMENTED** yet.

---

## 5. Failover policy

**PLANNED direction**

Failover is for provider/runtime availability problems, conceptually such as:

- rate limiting
- provider unavailable / overload
- insufficient provider credits

Do **not** blindly fail over every application error.

Inappropriate automatic-fallback examples:

- invalid request
- schema / application bug
- grounding failure
- business validation failure

Future implementation should prefer Laravel AI native failover behavior where it matches MoxDOP requirements, rather than rebuilding provider orchestration from scratch.

---

## 6. Agent Profiles

**PLANNED model**

```text
Agent Profile
  → AI Route
  → assigned Skills
  → permitted data scope
  → allowed operations
  → structured output
```

Example profiles (names illustrative):

- Website SEO Analyst
- Google Ads Analyst
- GBP Reputation Analyst
- Digital Operations Analyst

Agent Profiles are **not** autonomous employees. They are bounded product personas/workflows.

Constraints:

- no uncontrolled loops
- no arbitrary external actions
- no secret access for agents
- no external platform modification

---

## 7. Skill Library

**PLANNED concept**

A MoxDOP Skill is **not** arbitrary executable third-party code.

A Skill is a curated, versioned analytical methodology.

Full memory/Skill layering, trust levels, provenance, context assembly, and “no self-modifying AI” rules live in [`KNOWLEDGE_MEMORY_ARCHITECTURE.md`](./KNOWLEDGE_MEMORY_ARCHITECTURE.md).

### Conceptual Skill contract

| Field | Intent |
| --- | --- |
| name | Operator-facing identity |
| slug | Stable identifier |
| version | Immutable Skill revision id |
| module | Owning business module |
| purpose | What the Skill analyzes |
| required context | Brand Intelligence / asset prerequisites |
| required Evidence types | Normalized Evidence contracts |
| methodology | Steps / heuristics |
| rules/heuristics | Explicit labels (PRIMARY vs HEURISTIC) |
| allowed conclusions | Bounded claim space |
| forbidden claims | What must never be asserted |
| output contract | Structured draft shape |
| dependencies | Human/system prerequisites |
| success signals | How to know it worked |
| failure signals | How to know it failed |
| watch metrics | What to monitor after action |

Future Skill examples (all **NOT IMPLEMENTED**):

- Technical SEO Audit
- Search Console Analysis
- Keyword Opportunity Analysis
- Structured Data Review
- AI Search / GEO Review
- Google Ads Performance Analysis
- Search Term Analysis
- Landing Page Alignment
- GBP Review Intelligence

Skills should consume normalized MoxDOP Evidence rather than arbitrary raw provider dumps.

Conceptual references (not runtimes): MarketingSkills taxonomy, Claude SEO methodology — see the external intelligence audit.

---

## 8. Skill versioning / evaluation

**PLANNED direction**

- Skills must be versionable.
- An AI Run should eventually record which Skill version was used.
- Future evaluation may test Skill versions against deterministic fixtures/cases.

Do **not** implement an eval platform in this document’s scope. No harness is authorized by this file alone.

---

## 9. Agent permissions (safety model)

Example — Website SEO Analyst (**illustrative, NOT IMPLEMENTED**):

| CAN READ | CAN | CANNOT |
| --- | --- | --- |
| Evidence, Findings, Brand Intelligence, GSC/GA4/DataForSEO normalized data | Analyze; draft Recommendation | Modify Website; modify Google Ads; modify Meta Ads; publish content; arbitrary external writes |

External provider policy remains **READ-ONLY** for all Agent Profiles.

---

## 10. Explicitly rejected

- Unbounded autonomous agents
- Uncontrolled recursive agent loops
- Arbitrary third-party Skill execution
- Generic MCP as MoxDOP core architecture
- Agent access to raw secrets
- Autonomous external platform modification

Agent/Skills are a controlled reasoning layer over Evidence / Findings / Brand Context — not a replacement for Core architecture.

---

## 11. Suggested milestone order (product track)

These are planning labels, not Autopilot stage IDs:

1. **Integrations Workspace V2** — **COMPLETED** (PR #107)  
2. **Module Boundary + Knowledge / Memory Architecture Audit V1** — **COMPLETED** (PR #109)  
3. **AI Provider Routing & Failover V1** — **THIS MILESTONE** (OpenAI/Anthropic/Gemini + `website.ai_guidance`)  
4. **Agent Profiles + Skill Library V1** — bounded personas + curated versioned Skills (**NOT IMPLEMENTED**)  
5. **Memory / Retrieval V1** — only when knowledge volume / use cases justify it (structured retrieval first; vector RAG deferred)

### Failover policy (V1)

Uses Laravel AI native `FailoverableException` handling only:

- rate limited
- provider overloaded/unavailable
- insufficient credits/quota

Does **not** failover on validation errors, grounding failures, schema bugs, or ordinary application failures.

Routes are workflow-specific (not one global ranking). Model selection is owned by the AI route, not by the Integration card.

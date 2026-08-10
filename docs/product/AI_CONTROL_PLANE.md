# AI Control Plane

> **STATUS: PARTIALLY IMPLEMENTED (V1)**  
>  
> **Implemented in V1:**  
> - **AI Router:** OpenAI + Anthropic + Gemini, workflow routes (`website.ai_guidance`), native failover, provenance  
> - **Agent Profiles + Skill Library:** Website SEO Analyst, curated Website Skills, bounded context, eligibility, Agent/Skill provenance  
> **Still PLANNED / NOT IMPLEMENTED:** Playbooks, Recommendation Reviewer AI layer, Capability Registry / Capability Router, Memory/Retrieval, vector RAG, aggregator providers, operator-custom Skills DB. **Discovery Intelligence V1 is IMPLEMENTED** (Website-owned; see `DISCOVERY_INTELLIGENCE.md`).  
>  
> Authority order remains: `MASTER_SPEC` → accepted ADRs → product blueprints → this direction doc.  
> Related: [`AGENT_SKILL_ARCHITECTURE.md`](./AGENT_SKILL_ARCHITECTURE.md) · [`DISCOVERY_INTELLIGENCE.md`](./DISCOVERY_INTELLIGENCE.md) · [`KNOWLEDGE_MEMORY_ARCHITECTURE.md`](./KNOWLEDGE_MEMORY_ARCHITECTURE.md) · [`docs/research/EXTERNAL_INTELLIGENCE_ADOPTION_AUDIT.md`](../research/EXTERNAL_INTELLIGENCE_ADOPTION_AUDIT.md).

---

## 1. Vision

Intended long-term reasoning flow:

```text
Evidence providers / Capability adapters (future)
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
| Route-specific primary/fallback — **AI Router** (`website.ai_guidance`) | **IMPLEMENTED V1** |
| Agent Profiles (Website SEO Analyst) | **IMPLEMENTED V1** |
| Skill Library (built-in Markdown Skills) | **IMPLEMENTED V1** |
| Capability Registry / Capability Router | **PLANNED / NOT IMPLEMENTED** |
| Outside-in Discovery Intelligence | **IMPLEMENTED V1** — Website public discovery + `website.discovery_context`; see `DISCOVERY_INTELLIGENCE.md` |
| Playbooks | **PLANNED / NOT IMPLEMENTED** |
| Recommendation Reviewer AI layer | **PLANNED / NOT IMPLEMENTED** (deterministic grounding remains V1 gate) |
| Skill versioning / evaluation harness | **PLANNED / NOT IMPLEMENTED** |
| Knowledge / Memory architecture (four layers) | **PLANNED** — Expert/Skill Memory **IMPLEMENTED V1** via curated Markdown Skills; see [`KNOWLEDGE_MEMORY_ARCHITECTURE.md`](./KNOWLEDGE_MEMORY_ARCHITECTURE.md) |
| Vector RAG / embeddings | **NOT IMPLEMENTED** — deferred until knowledge volume justifies it |
| Aggregator providers (OpenRouter, DeepSeek, Groq, …) | **NOT IMPLEMENTED** (V1 proves direct providers only) |
| MCP / unbounded agents | **REJECTED** as MoxDOP core |

Do not describe planned rows as current product functionality.

---

## 3. Locked conceptual distinctions

| Concept | Answers | Examples |
| --- | --- | --- |
| **Integration** | How MoxDOP authenticates / connects to an external provider | Google OAuth, DataForSEO, OpenAI, Anthropic, Gemini |
| **Module** | A business / domain capability of MoxDOP | Website, Google Ads, GBP |
| **Agent** | Bounded AI workflow / persona | Website SEO Analyst (**planned**) |
| **Skill** | Analytical methodology | Technical SEO Audit (**planned**) |
| **Capability** | Implementation-independent ability needed by a Module / Agent / Skill | `keyword-data.read`, `website.content.read` (**planned**) |
| **Adapter** | Concrete implementation / provider that fulfills a Capability | DataForSEO adapter, public-web reader (**planned**) |

**Capability ≠ Integration ≠ Module ≠ Agent ≠ Skill.**

Agents should eventually depend on **Capabilities**, not directly on implementations / providers / tools.

```text
BAD:
Website SEO Agent → call DataForSEO

PREFERRED FUTURE DIRECTION:
Website SEO Agent
  → requires keyword-data.read
  → Capability Router
  → DataForSEO Adapter
  → future alternative Adapter
```

Example Capability → Adapter mappings (**illustrative, NOT IMPLEMENTED**):

| Capability | Adapter example |
| --- | --- |
| `keyword-data.read` | DataForSEO |
| `search-console.read` | Google Search Console Integration |
| `website.content.read` | public web reader |
| `website.technical.inspect` | direct HTTP collector · rendered-browser collector · PageSpeed collector |

---

## 4. AI Router vs Capability Router (**CRITICAL**)

These are **parallel** concepts. Do **not** collapse them into one universal router.

### AI Router (reasoning providers) — **IMPLEMENTED V1**

Answers:

> Which AI provider / model performs the reasoning?

Example — Website AI Guidance (`website.ai_guidance`):

```text
Website AI Guidance
  1. OpenAI / gpt-5-mini     — PRIMARY (default when no custom steps)
  2. Anthropic / claude-…    — FALLBACK (when configured & eligible)
  3. Gemini / gemini-…       — FALLBACK (when configured & eligible)
```

Operator-facing workspace: **Settings → AI Control Plane**.  
Model selection is owned by the **AI route**, not by Integration cards.

### Capability Router (data / function adapters) — **PLANNED / NOT IMPLEMENTED**

Answers:

> Which adapter / provider supplies the required data or function?

Example:

```text
keyword-data.read
  1. DataForSEO
  2. future fallback adapter

website.content.read
  1. public-web adapter
  2. future rendered-page adapter where appropriate
```

The Agent / Skill asks for **what it needs**, not **which implementation to call**.

Capability Registry + Capability Router remain **PLANNED / NOT IMPLEMENTED**.

Future Agent architecture (planned):

```text
Agent Profile
  → Skills
  → required_capabilities
  → future Capability Router

while AI reasoning uses:

Agent / workflow
  → AI Route
  → provider / model chain
```

These are separate responsibilities.

---

## 5. AI providers

Direction: MoxDOP supports multiple AI providers using the installed **`laravel/ai`** abstraction where practical.

**Implemented V1 production providers:**

- OpenAI
- Anthropic
- Google Gemini

Potential later providers (non-promise list): OpenRouter and other providers safely supported by the installed Laravel AI SDK.

Provider availability depends on:

- current Laravel AI support
- provider capability
- structured-output compatibility
- security / credential model
- operational value

Agency Integration credentials; OpenAI `store=false` on generation; route-owned models.

Intentionally **not** in V1: OpenRouter, DeepSeek, Groq, xAI, Mistral, Ollama, Bedrock, Azure OpenAI, generic OpenAI-compatible endpoints.

Do not permanently promise every named provider.

---

## 6. AI Routes (not one global ranking) — **IMPLEMENTED V1**

Do **not** model AI selection as a single universal global provider order.

**AI Routes** are workflow-specific.

V1 operational route:

- `website.ai_guidance` — Website AI Guidance (registered by Website module; shared resolver infrastructure)
- `website.discovery_context` — Website Discovery Context (Brand inference proposals from bounded public Discovery Evidence)

Future route examples (not yet operational):

- SEO Intelligence
- Google Ads Analysis
- Executive Analysis

Each route may define:

- primary provider + model
- fallback provider/model(s)

Default when no custom steps are persisted: OpenAI / `gpt-5-mini` (backward compatible).

One provider may appear only once per route in V1.

---

## 7. Failover policy — **IMPLEMENTED V1** (AI Router)

Uses Laravel AI native `FailoverableException` handling only:

- rate limited
- provider overloaded / unavailable
- insufficient credits / quota

Does **not** failover on:

- invalid request
- schema / application bug
- grounding failure
- business validation failure
- ordinary application failures

Routes are workflow-specific (not one global ranking). Model selection is owned by the AI route, not by the Integration card.

Local eligibility skips unconfigured / disabled / auth-failed providers before calling Laravel AI.

Capability Router may later have its own ordered adapter eligibility / health rules — still separate from AI failover.

---

## 8. Capability health / doctor (**PLANNED**)

Inspired by Agent Reach’s health-check idea (reference only — **not** a runtime dependency).

A provider being configured does **not** automatically mean a Capability is usable.

Future Capability health may conceptually distinguish:

- Healthy
- Configured
- Unavailable
- Broken
- Timeout
- Needs attention

Do **not** implement these Capability health states now. Prefer lightweight real health checks over merely checking whether credentials/configuration exist. Reuse existing MoxDOP Integration health concepts when runtime work begins.

---

## 9. Provenance-preserving external access (**PLANNED** for Capability layer)

Agent Reach often lets an Agent directly invoke an upstream implementation.

MoxDOP should **not** do that for analytical product data.

Canonical future Capability flow:

```text
Agent / Module
  ↓ requests Capability
Capability Registry / Router
  ↓ selects Adapter
Adapter / Integration
  ↓ external source
NORMALIZATION
  ↓
Run
  ↓
Evidence
  ↓
Findings / Agent analysis
```

External results that affect analysis should become auditable MoxDOP Evidence where applicable.  
Agents must not silently place arbitrary external content into reasoning context without provenance.

AI Router Runs already record safe provenance such as: `ai_route_key`, configured provider/model chain, successful provider/model, `fallback_occurred`, token usage when available, route signature, prompt/schema versions, Finding/Evidence IDs. Never credentials.

---

## 10. Agent Profiles

**IMPLEMENTED V1** for Website SEO Analyst. Additional profiles remain planned.

```text
Module
  → Agent Profiles
    → Skills

Agent / Workflow
  → AI Route
  → provider / model chain

Skill
  → required Capability (metadata in V1)
  → future Capability Router
  → Adapter
```

```text
Agent Profile
  → AI Route
  → assigned Skills
  → permitted data scope
  → allowed operations
  → structured output
```

Operational V1 profile:

- **Website SEO Analyst** (`website.seo_analyst`) — module `website`, route `website.ai_guidance`
- **Website Brand Discovery Analyst** (`website.brand_discovery_analyst`) — module `website`, route `website.discovery_context`
- **Google Ads Analyst** (`google_ads.analyst`) — module `google-ads`, route `google_ads.ai_guidance`

Example future profiles (names illustrative, **NOT IMPLEMENTED**):

- GBP Reputation Analyst
- Digital Operations Analyst
- Recommendation Reviewer

Agent Profiles are **not** autonomous employees. They are bounded product personas/workflows.

Constraints:

- no uncontrolled loops
- no arbitrary external actions
- no secret access for agents
- no external platform modification
- no Agent-direct tool bypass of Capability / Evidence provenance
- no self-modifying Agent Profiles

---

## 11. Skill Library

**IMPLEMENTED V1** for curated built-in Website Skills (Markdown under module resources).

A MoxDOP Skill is **not** arbitrary executable third-party code.

A Skill is a curated, versioned analytical methodology.

Operator/custom Skill DB tables remain **PLANNED / NOT IMPLEMENTED**.

Full memory/Skill layering, trust levels, provenance, context assembly, and “no self-modifying AI” rules live in [`KNOWLEDGE_MEMORY_ARCHITECTURE.md`](./KNOWLEDGE_MEMORY_ARCHITECTURE.md) and [`AGENT_SKILL_ARCHITECTURE.md`](./AGENT_SKILL_ARCHITECTURE.md).

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
| **required_capabilities** | Implementation-independent abilities the Skill needs (**PLANNED**) |
| **optional_capabilities** | Enrichment abilities if available (**PLANNED**) |
| methodology | Steps / heuristics |
| rules/heuristics | Explicit labels (PRIMARY vs HEURISTIC); standing rules; WHEN TO USE / NOT FOR |
| allowed conclusions | Bounded claim space |
| forbidden claims | What must never be asserted |
| output contract | Structured draft shape |
| dependencies | Human/system prerequisites |
| success signals | How to know it worked |
| failure signals | How to know it failed |
| watch metrics | What to monitor after action |

Skill does **not** hardcode the concrete provider where avoidable — it declares Capabilities.

Illustrative examples (**NOT IMPLEMENTED**):

| Skill | Required capabilities (examples) |
| --- | --- |
| Technical SEO Audit | `website.content.read`, `website.technical.inspect`, `search-console.read` |
| Keyword Opportunity Analysis | `keyword-data.read`, `search-console.read` |
| Competitor Research | `web.search`, `website.content.read` |

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

Conceptual references (not runtimes): MarketingSkills taxonomy, Claude SEO methodology, Agent Reach Skill/capability trigger patterns — see the external intelligence audit.

---

## 12. Skill versioning / evaluation

**PLANNED direction**

- Skills must be versionable.
- An AI Run should eventually record which Skill version was used.
- Future evaluation may test Skill versions against deterministic fixtures/cases.

Do **not** implement an eval platform in this document’s scope. No harness is authorized by this file alone.

---

## 13. Agent permissions (safety model)

Example — Website SEO Analyst (**illustrative, NOT IMPLEMENTED**):

| CAN READ | CAN | CANNOT |
| --- | --- | --- |
| Evidence, Findings, Brand Intelligence, GSC/GA4/DataForSEO normalized data | Analyze; draft Recommendation | Modify Website; modify Google Ads; modify Meta Ads; publish content; arbitrary external writes |

External provider policy remains **READ-ONLY** for all Agent Profiles.

---

## 14. Explicitly rejected

- Unbounded autonomous agents
- Uncontrolled recursive agent loops
- Arbitrary third-party Skill execution
- Generic MCP as MoxDOP core architecture
- Agent access to raw secrets
- Autonomous external platform modification
- Agent Reach (or similar) as a MoxDOP runtime dependency
- Browser-cookie scraping / anti-detection as canonical access
- Collapsing AI Router and Capability Router into one universal router
- Agent-direct external tool calls that bypass Run / Evidence

Agent/Skills are a controlled reasoning layer over Evidence / Findings / Brand Context — not a replacement for Core architecture.

---

## 15. Suggested milestone order (product track)

These are planning labels, not Autopilot stage IDs:

1. **Integrations Workspace V2** — **COMPLETED** (PR #107 / `61bbfc8`)  
2. **Module Boundary + Knowledge / Memory Architecture Audit V1** — **COMPLETED** (PR #109 / `ec31bde`)  
3. **Capability + Discovery product direction docs V1** — **COMPLETED** (docs only; PR #111) — **NOT** a runtime milestone  
4. **AI Provider Routing & Failover V1** — **IMPLEMENTED V1**  
5. **Agent Profiles + Skill Library V1** — **IMPLEMENTED V1** (Website SEO Analyst + curated Skills)  
6. **Memory / Retrieval V1** — only when knowledge volume / use cases justify it (structured retrieval first; vector RAG deferred)

Later **candidate** architecture/product work (**UNCOMMITTED** — select next after reviewing Agent/Skill V1 results):

- Capability Registry / Routing V1
- Playbooks
- Recommendation Reviewer AI layer
- Meta Ads read-only intelligence
- GBP Reputation Intelligence
- GEO / AI Search Intelligence
- competitor/domain intelligence
- backlinks
- rank tracking

No fixed calendar dates are assigned here.

**Do not automatically start the next milestone from this document alone.**

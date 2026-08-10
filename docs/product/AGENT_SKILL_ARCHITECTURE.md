# Agent + Skill Architecture

> **STATUS: IMPLEMENTED V1 / PARTIALLY IMPLEMENTED**  
>  
> Authority: `MASTER_SPEC` → accepted ADRs → product blueprints → this doc.  
> Related: [`AI_CONTROL_PLANE.md`](./AI_CONTROL_PLANE.md) · [`KNOWLEDGE_MEMORY_ARCHITECTURE.md`](./KNOWLEDGE_MEMORY_ARCHITECTURE.md) · [`DISCOVERY_INTELLIGENCE.md`](./DISCOVERY_INTELLIGENCE.md) · [`docs/research/EXTERNAL_INTELLIGENCE_ADOPTION_AUDIT.md`](../research/EXTERNAL_INTELLIGENCE_ADOPTION_AUDIT.md).

---

## 1. Terminology (locked)

| Concept | Meaning |
| --- | --- |
| **Integration** | External provider/service connection |
| **Module** | Business/domain capability |
| **AI Route** | Which AI provider/model performs reasoning |
| **Agent Profile** | Bounded professional AI workflow/persona |
| **Skill** | Curated/versioned analytical methodology |
| **Capability** | Implementation-independent ability/data requirement |
| **Adapter** | Concrete provider/tool fulfilling a Capability |
| **Playbook** | Future bounded orchestration of Agents/Skills for a repeatable scenario |

**FEW STRONG AGENTS + MANY CURATED VERSIONED SKILLS.**

---

## 2. Target flow

```text
Module
  ↓
Agent Profile
  ↓
Assigned Skills
  ↓
required Evidence
  ↓
required/optional Capabilities (metadata only in V1)
  ↓
bounded Agent Context
  ↓
AI Route
  ↓
OpenAI / Anthropic / Gemini
  ↓
structured output
  ↓
grounding / validation
  ↓
human-controlled Recommendation workflow
```

Future:

```text
Playbook → Agent Profiles → Skills
```

Playbook runtime is **PLANNED / NOT IMPLEMENTED**.

---

## 3. Implemented V1

- Generic `AgentProfileDefinition` + `AgentProfileRegistry` (code-defined; **no** `agent_profiles` table)
- Generic `SkillDefinition` + `SkillRegistry` + safe `BuiltInSkillLoader` (Markdown under module resources; **no** `skills` / `skill_versions` tables)
- Module-owned Website Skills (`app-modules/website/resources/skills/*/SKILL.md`)
- Operational Agents:
  - **Website SEO Analyst** (`website.seo_analyst` @ `1.0.0`)
  - **Website Brand Discovery Analyst** (`website.brand_discovery_analyst` @ `1.0.0`) — public Discovery inferences; Skill `brand-context-discovery`
  - **Google Ads Analyst** (`google_ads.analyst` @ `1.0.0`)
- Skills:
  - `technical-seo-analysis`
  - `search-console-analysis`
  - `keyword-opportunity-analysis`
  - `recommendation-framing`
- Bounded context assembly + Skill eligibility (missing Evidence → Skill not applicable)
- `required_capabilities` / `optional_capabilities` as **metadata only** (Capability Router absent)
- Agent/Skill versions in fingerprint + Run provenance
- Prompt sections: Agent contract · Skills · untrusted Evidence data · safety rules
- Prompt-injection defense: Evidence treated as data
- Settings UI: Agent Profiles + Skill Library (read-only catalog; no generic CRUD)
- Integration with existing Website AI Guidance + `website.ai_guidance` AI Route

---

## 4. Ownership

**Core / shared** may own: Agent/Skill contracts, registries, safe loader, eligibility mechanics, generic provenance fields.

**Modules** own: Agent Profiles, Skill Markdown content, domain methodology, prompts/domain reasoning, Evidence semantics, allowed/forbidden claims.

Core must **not** contain Website/Google Ads/GBP methodology.

---

## 5. Safety (non-negotiable)

- No autonomous loops / swarm / agent-to-agent chat
- No external platform writes
- No Task auto-creation / Recommendation auto-approval
- No Skill/PHP/shell execution
- No path traversal / remote Skill loading
- No credentials in context
- No self-modifying Skills or Agent Profiles
- No RAG / embeddings / vector DB in V1

---

## 6. Future concepts (documented, not runtime)

### Recommendation Reviewer (**PLANNED**)

Inspired by Agency Agents Evidence Collector / Reality Checker patterns.

Purpose:

- evidence sufficiency
- grounding validation
- unsupported-claim detection
- contradiction / actionability checks

V1 continues to use deterministic schema/grounding validation. Do **not** add a second AI reviewer call merely for architecture.

### Playbooks (**PLANNED**)

Repeatable operational scenarios orchestrating Agent Profiles + Skills.

Prefer one strong domain Analyst + many Skills over hundreds of tiny Agents.

### Google Ads Analyst (**IMPLEMENTED V1**)

- Slug `google_ads.analyst` @ `1.0.0`
- Route `google_ads.ai_guidance`
- Skills: account-performance-audit, campaign-performance-analysis, search-query-analysis, measurement-quality-review, landing-page-alignment
- See `docs/product/google-ads/GOOGLE_ADS_INTELLIGENCE.md`
- Methodology reference: Agency Agents paid-media patterns — **runtime not imported**

Potential later Skills (not in V1): Budget Efficiency, Creative Analysis.

### Digital Operations Analyst (**PLANNED**)

Cross-asset synthesis Skills later — no cross-agent orchestration now.

---

## 7. External references

Selective methodology sources (not runtimes):

- `msitarzewski/agency-agents` — Agent mission/rules/deliverables/success criteria; Playbook concept; reject hundreds of Agents / autonomy / writes
- MarketingSkills, Claude SEO, HEAD, OpenSEO — Skill methodology / taxonomy

Official provider docs remain authoritative for technical facts.

---

## Explicit non-goals of V1

- Playbook runtime tables
- Operator-uploaded executable Skills
- SkillVersion DB
- Capability Router
- Full Discovery beyond V1 (competitor crawl, social platforms, continuous monitoring)
- RAG / embeddings
- Autonomous multi-agent teams

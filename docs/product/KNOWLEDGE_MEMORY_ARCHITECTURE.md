# Knowledge / Memory Architecture

> **STATUS: PLANNED PRODUCT ARCHITECTURE**  
> **PARTIALLY IMPLEMENTED THROUGH EXISTING STRUCTURED DATA**  
>  
> This document defines how MoxDOP thinks about **knowledge** and **memory**.  
> It does **not** authorize vector RAG, embeddings, Skill runtime tables, or self-modifying AI.  
>  
> Authority order: `MASTER_SPEC` → accepted ADRs → product blueprints → this direction doc.  
> Related: [`AI_CONTROL_PLANE.md`](./AI_CONTROL_PLANE.md), [`MODULE_PLATFORM.md`](./MODULE_PLATFORM.md),  
> [`docs/foundation/MODULE_ARCHITECTURE.md`](../foundation/MODULE_ARCHITECTURE.md),  
> [`docs/current-state/MODULE_BOUNDARY_AUDIT_V1.md`](../current-state/MODULE_BOUNDARY_AUDIT_V1.md).

---

## 1. RAG is not “AI memory”

**RAG** means **Retrieval-Augmented Generation**: retrieve relevant material, then generate.

**Memory** is a broader product concept: institutional docs, operational history, curated Skills, and future learned lessons.

Do not treat “add a vector database” as synonymous with “give the product memory.”

---

## 2. Fundamental distinctions (locked)

| Concept | Meaning | Examples |
| --- | --- | --- |
| **Integration** | External provider/service connection | Google agency auth, DataForSEO, OpenAI, future Anthropic/Gemini |
| **Module** | MoxDOP business/domain capability | Website, Google Ads, Google Business Profile, future Meta Ads |
| **Agent** | Bounded AI workflow/persona within allowed domains | Website SEO Analyst, Google Ads Analyst, GBP Reputation Analyst |
| **Skill** | Versioned analytical methodology used by an Agent | Technical SEO Audit, Search Term Analysis, Review Intelligence |
| **Capability** | Implementation-independent ability needed by Module/Agent/Skill (**planned**) | `keyword-data.read`, `website.content.read`, `search-console.read` |
| **Adapter** | Concrete implementation that fulfills a Capability (**planned**) | DataForSEO adapter, public-web reader, PageSpeed collector |

Provider ≠ Module. Integration ≠ Module. Agent ≠ Module. Skill ≠ Module.  
**Capability ≠ Integration ≠ Module ≠ Agent ≠ Skill.**  
Do **not** create one Module per external GitHub repository.

Capability routing is about **accessing data/functions**.  
RAG / retrieval is about **selecting relevant knowledge/context**.  
They are different layers — see §6.

---

## 3. Four memory layers

### A. System / Institutional Memory

Version-controlled product knowledge (usually Markdown / Git).

Examples:

- `MASTER_SPEC`
- Accepted ADRs / decision log
- `AI_CONTROL_PLANE`
- External intelligence adoption audit
- Module architecture / contracts
- Future built-in Skill Markdown packs

This is MoxDOP’s institutional / product memory.

### B. Operational Memory

Existing relational product history. **Database remains canonical.**

Examples:

- Brand Context
- Runs
- Evidence
- Findings
- Recommendations
- Tasks
- Future measurable outcomes

Purpose: *“What happened for this Brand / Digital Asset?”*

Do **not** replace these records with embeddings or vector memory.

### C. Expert / Skill Memory

**IMPLEMENTED V1** through curated, versioned built-in Skills (Markdown under module resources + `SkillRegistry`).

- Skill Registry exists.
- Vector memory does **NOT**.
- Operator/custom persistent SkillVersion DB records do **NOT**.
- Learned Operational Memory remains planned.
- No self-modifying AI.

Preferred built-in authoring layout (**IMPLEMENTED for Website V1**):

```text
app-modules/website/resources/skills/
    technical-seo-analysis/SKILL.md
    search-console-analysis/SKILL.md
    keyword-opportunity-analysis/SKILL.md
    recommendation-framing/SKILL.md
```

Markdown is preferred for authoring because it is human-readable, version-control friendly, diffable, structurally clear, and easy to supply to LLM context.  
Markdown does **not** have magical AI-native properties.

### D. Learned Operational Memory (future)

Conceptual loop (**human-gated**, not automatic):

```text
Finding
  → Recommendation
  → Task
  → Task completion
  → later Evidence
  → measurable outcome
  → Learning Candidate
  → human review
  → accepted organizational lesson
  → possible new Skill version
```

Example: low CTR Finding → title optimization Recommendation → Task completed → later GSC improves → possible Learning Candidate.

**One successful case does not become universal truth automatically.**

---

## 4. Future Skill contract (planned fields)

Documented for future Agent Profiles + Skill Library V1. **Do not create Skill / SkillVersion tables now.**

| Field | Intent |
| --- | --- |
| name | Operator-facing identity |
| slug | Stable identifier |
| version | Immutable revision |
| module | Owning business module |
| purpose | What the Skill analyzes |
| required context | Brand / asset prerequisites |
| required Evidence | Normalized Evidence contracts |
| **required_capabilities** | Implementation-independent abilities the Skill needs (**PLANNED**) |
| **optional_capabilities** | Enrichment abilities if available (**PLANNED**) |
| methodology | Steps / heuristics |
| rules / heuristics | Explicit PRIMARY vs HEURISTIC labels; standing rules; WHEN TO USE / NOT FOR |
| allowed conclusions | Bounded claim space |
| forbidden claims | What must never be asserted |
| dependencies | Human/system prerequisites |
| output contract | Structured draft shape |
| success signals | How to know it worked |
| failure signals | How to know it failed |
| watch metrics | What to monitor after action |

Skills should declare Capabilities rather than hardcoding concrete providers where avoidable.  
Built-in Skills may originate from module resources.  
Future operator/custom Skills may eventually use `Skill` / `SkillVersion` records — **not now**.

Capability Registry / Router and Discovery Intelligence remain separate planned layers — see [`AI_CONTROL_PLANE.md`](./AI_CONTROL_PLANE.md) and [`DISCOVERY_INTELLIGENCE.md`](./DISCOVERY_INTELLIGENCE.md).

---

## 5. No self-modifying AI

Explicitly rejected:

- AI automatically rewriting Skill Markdown
- AI automatically modifying its own methodology
- AI turning one successful outcome into global truth
- Uncontrolled self-learning loops

Future learned knowledge requires:

- provenance
- supporting Evidence
- measurable outcome
- human approval
- versioning

---

## 6. RAG decision (current)

**DO NOT IMPLEMENT VECTOR RAG NOW.**

Agent Reach / Capability Layer review does **not** change this decision.

| Layer | Question it answers |
| --- | --- |
| Capability routing | Which adapter supplies the required data/function? |
| RAG / retrieval | Which knowledge/context is relevant to include for generation? |

They are different layers. Capability work does **not** imply embeddings or vector stores.

Reasons RAG remains deferred:

- Current relevant context is still structured and bounded
- Entity relationships already identify much of the needed context
- Brand Context / Findings / Evidence are deterministic retrieval paths
- Premature vector infrastructure adds complexity
- Canonical DB remains **MySQL 8**

### Current preferred context retrieval

```text
Brand
  → Digital Asset
  → relevant Findings
  → supporting normalized Evidence
  → Brand Context
  → assigned Skills (future)
```

---

## 7. Future semantic retrieval

Semantic retrieval may become justified later when MoxDOP contains substantial:

- Skill libraries
- historical Recommendations
- accepted lessons
- case histories
- unstructured knowledge
- long documents

Then evaluate current Laravel AI capabilities for embeddings, vector stores, similarity search, and hybrid retrieval.

Do **not** now:

- add embeddings
- add vector columns
- change MySQL
- introduce PostgreSQL solely for theoretical future RAG

---

## 8. Knowledge trust levels

| Level | Examples |
| --- | --- |
| **AUTHORITATIVE PRODUCT FACT** | Normalized provider Evidence; operator-maintained Brand Context; deterministic Finding state |
| **CURATED METHODOLOGY** | Approved Skill version |
| **DERIVED INTERPRETATION** | AI Guidance / draft Recommendation text |
| **LEARNING CANDIDATE** | Observed post-task improvement awaiting approval |

AI output must **never** silently promote itself into authoritative truth.

---

## 9. Knowledge provenance (direction)

Future knowledge must remain attributable. Conceptually capture/reference:

- source
- module
- Skill version
- Run
- Evidence IDs
- Finding IDs
- Recommendation
- Task
- created_by / accepted_by
- timestamps
- version

Do **not** implement a generic knowledge graph.

---

## 10. Context assembly

AI must **not** “remember everything” by receiving the whole DB in every prompt. Context is bounded.

Example Website Agent context:

- Brand Context
- relevant Website Findings
- supporting normalized Evidence
- assigned Website Skills (future)
- selected approved historical lessons (later)

**Not:** all customers, all raw provider dumps, all old AI conversations, all Runs, all unrelated modules.

---

## 11. Memory security

Never include in Skill / RAG / memory context:

- API keys
- OAuth tokens
- passwords
- Authorization headers
- credentials
- raw secrets

Existing secret-redaction policy applies to future memory/retrieval.

---

## 12. Future hierarchy

```text
Module
  → Agent Profiles
    → Skills
```

Examples:

**Website Module** → Agent: Website SEO Analyst → Skills: Technical SEO Audit, Search Console Analysis, Keyword Opportunity Analysis, Structured Data Review, AI Search / GEO Review  

**Google Ads Module** → Agent: Google Ads Analyst → Skills: Campaign Performance, Search Term Analysis, Landing Page Alignment  

**GBP Module** → Agent: GBP Reputation Analyst → Skills: Review Intelligence, Local Visibility  

---

## 13. Milestone relationship

1. **AI Provider Routing & Failover V1** — **IMPLEMENTED V1** (AI Router)  
2. **Agent Profiles + Skill Library V1** — **IMPLEMENTED V1**  
3. **Memory / Retrieval V1** — only when knowledge volume / use cases justify it  

Later candidates (UNCOMMITTED — select after V1 review): Google Ads Analyst application · Capability Registry / Routing V1 · Discovery Intelligence V1 · Playbooks · Meta Ads · GBP Reputation · GEO / AI Search.

Domain milestones remain separately prioritized by product value.

---

## Explicit non-goals of this document

- Implementing vector RAG / embeddings
- Creating Skill database tables
- Shipping Agent Profiles runtime
- Implementing Capability Registry / Router
- Implementing Discovery Intelligence
- Authorizing AI self-modification
- Changing the database engine

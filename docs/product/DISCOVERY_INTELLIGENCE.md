# Discovery Intelligence (Outside-in)

> **STATUS: PLANNED PRODUCT DIRECTION**  
> **NOT IMPLEMENTED YET**  
>  
> This document records long-term product direction for **Outside-in Discovery / Public Intelligence**.  
> It does **not** authorize runtime work, scraping stacks, browser automation, MCP, migrations, or a Discovery Module.  
>  
> Authority order: `MASTER_SPEC` → accepted ADRs → product blueprints → this direction doc.  
> Related:  
> [`AI_CONTROL_PLANE.md`](./AI_CONTROL_PLANE.md) ·  
> [`KNOWLEDGE_MEMORY_ARCHITECTURE.md`](./KNOWLEDGE_MEMORY_ARCHITECTURE.md) ·  
> [`BRAND_INTELLIGENCE.md`](./BRAND_INTELLIGENCE.md) ·  
> [`docs/research/EXTERNAL_INTELLIGENCE_ADOPTION_AUDIT.md`](../research/EXTERNAL_INTELLIGENCE_ADOPTION_AUDIT.md) (Agent Reach reference).

---

## 1. Why Discovery exists

MoxDOP’s long-term purpose is not merely:

> “Analyze accounts the operator already connected.”

It should eventually combine:

| Mode | Meaning |
| --- | --- |
| **Outside-in Intelligence** | Public / external information that does **not** require first-party analytics or ad-account access |
| **Inside-out Intelligence** | Authenticated first-party operational data from connected platforms |

Discovery feeds the existing canonical pipeline. It does **not** replace Customer → Brand → Digital Asset → Run → Evidence → Finding → Recommendation → Task.

---

## 2. Outside-in Intelligence (**PLANNED**)

Outside-in uses public/external information available without Brand first-party access.

Potential future sources / capabilities (illustrative, **NOT IMPLEMENTED**):

- public Website content
- search presence / public search results
- competitor websites
- public brand mentions
- public social presence where safely, legally, and technically available
- YouTube / public content
- public business information
- external SEO intelligence providers (via Integrations / Capabilities)
- public reviews through approved / appropriate sources

Outside-in analysis can begin **before**:

- GA4 connection
- GSC connection
- Google Ads connection
- CRM connection
- WordPress authentication

---

## 3. Inside-out Intelligence (already the dominant path today)

Inside-out uses authenticated first-party operational data.

Examples:

- GSC queries
- GA4 traffic / conversions
- Google Ads spend / campaign data
- GBP authorized data
- CRM leads (future)
- future Meta Ads first-party data (read-only, when prioritized)

This is deeper / private performance truth.

---

## 4. Combined product model (**PLANNED**)

```text
Brand
│
├── Outside-in Intelligence
│   ├── Website (public)
│   ├── Search Presence
│   ├── Competitors
│   ├── Public Content
│   ├── Social Presence
│   └── Mentions
│
└── Inside-out Intelligence
    ├── GSC
    ├── GA4
    ├── Ads
    ├── GBP
    └── CRM
          ↓
       Evidence
          ↓
       Findings
          ↓
       Agents / Skills (future)
          ↓
       Recommendations
          ↓
       Tasks
          ↓
       Future Runs / Progress
```

Discovery **feeds** this model. It does **not** alter the canonical entity hierarchy.

---

## 5. Website without connection (**PLANNED** use case)

Future operator may provide only:

- Website URL
- optionally Brand name

MoxDOP may then perform a **bounded PUBLIC WEBSITE DISCOVERY** without GSC, GA4, or WordPress credentials.

Potential extracted / discovered **candidate** information:

- business / brand name
- visible products / services
- locations
- contact information
- public content themes
- page / site positioning signals
- CTA patterns
- visible languages
- social-profile links
- public business claims

### Hard limit

Public content reading must **not** be treated as equivalent to:

- GSC
- GA4
- authenticated WordPress
- technical crawl
- PageSpeed
- rendered-browser technical diagnosis

---

## 6. Separate Website capabilities (**PLANNED**)

| Capability | Intent | Typical future adapters (examples) |
| --- | --- | --- |
| `website.content.read` | Page meaning, services, Brand discovery, positioning, content analysis, competitor content comparison | public web reader |
| `website.technical.inspect` | HTTP status/headers, redirects, canonical/robots/indexability, rendered DOM, structured data, JS rendering, PageSpeed, internal-link crawl | direct HTTP collector, rendered-browser collector, PageSpeed collector |

**Do not overclaim technical SEO from a text-reader adapter.**

These are **Capabilities**, not Modules and not Integrations. See Capability Layer notes in `AI_CONTROL_PLANE.md` and `MODULE_ARCHITECTURE.md`.

---

## 7. Brand Context discovery (**PLANNED**)

Brand Intelligence may eventually offer:

> Discover brand context

Using public sources, MoxDOP may propose **candidates** for:

- business summary
- products / services
- locations
- target-market signals
- public social profiles
- positioning
- differentiators
- potential competitors

### UX expectation

```text
Discover
  → review candidates
  → source / provenance visible
  → Accept / Edit / Ignore
```

Candidates must **not** silently overwrite operator-maintained Brand Context (`docs/product/BRAND_INTELLIGENCE.md`).

---

## 8. Discovered fact vs AI inference

This distinction is mandatory for future trust semantics.

| Kind | Examples | Treatment |
| --- | --- | --- |
| **DISCOVERED FACT CANDIDATE** | Address displayed on website; service listed on a service page; Instagram URL linked from website; language version visible | Attributable observation candidate with source URL / retrieved_at |
| **AI-DERIVED INFERENCE** | Target audience; positioning; brand differentiation; likely market intent | Explicitly labeled interpretation — never equivalent to operator fact or normalized Evidence truth |

Do **not** store both as equivalent truth.

---

## 9. Competitor discovery (**PLANNED**)

Potential future workflow:

```text
Brand Context
  + Website / service / location signals
        ↓
  public / search research
        ↓
  candidate competitor domains / brands
        ↓
  candidate ranking / confidence
        ↓
  operator review
        ↓
  accepted competitor
```

MoxDOP must **not** automatically promote discovered entities into canonical competitors without review.

---

## 10. Competitor intelligence (**PLANNED**)

Once a competitor candidate is **accepted**, future outside-in analysis may compare:

- website / services
- locations
- content depth / topics
- language coverage
- positioning
- offers
- social presence
- external keyword visibility
- public search presence

Competitors do **not** need to connect first-party accounts.

Our managed Brand may combine outside-in **+** inside-out.  
Competitors generally use **outside-in only**.

---

## 11. Public brand presence / mentions (**PLANNED** candidate)

Future Discovery may identify:

- social profiles
- YouTube presence
- public web mentions
- public community discussions
- public review sources
- news / articles

Any future sentiment / reputation interpretation must preserve:

- source
- date
- provenance
- confidence
- availability constraints

Do **not** claim universal platform access.

---

## 12. Platform access safety

Learned from Agent Reach reference review (see external audit): some platforms may “work” only via logged-in browser state, cookies, unofficial CLIs, or scraping.

**MoxDOP must not default to unsafe / brittle access** merely because a reference project supports it.

Preferred order:

1. Official API where appropriate / available
2. Safe public endpoint / source
3. Approved public-web retrieval
4. Alternative access only after legal / security / product review

Explicitly **not** canonical MoxDOP infrastructure:

- browser-cookie / session scraping as default
- anti-detection / bypass architecture
- arbitrary CLI invocation as Core
- MCP as Core architecture
- Agent-direct external tool calls that bypass Run / Evidence

---

## 13. Discovery Evidence (**PLANNED** direction)

Future Discovery data that affects MoxDOP analysis should enter canonical provenance where appropriate.

Potential future Evidence types may be created by the responsible module or Discovery workflow.

Every significant discovered observation should be attributable to:

- source URL / provider
- `retrieved_at`
- adapter / capability
- Run
- normalization version
- confidence / type if derived

**Do not create these Evidence types now.**

Agents must not silently place arbitrary external content into reasoning context without provenance.

---

## 14. Discovery is not a Module yet

**CURRENT PRODUCT DECISION**

- Do **not** create an `Agent Reach` Module.
- Do **not** automatically create a `Discovery` Module at this stage.

Discovery currently represents a **planned cross-cutting product capability / workflow** used by:

- Brand Intelligence
- Website
- competitor analysis
- reputation intelligence
- future Agents / Skills

If implementation later becomes large enough to justify its own bounded domain, re-evaluate module ownership then. Do not pre-decide prematurely.

---

## 15. Capability relationship (**PLANNED**)

Discovery workflows should eventually request **Capabilities** (e.g. `website.content.read`, `web.search`, `keyword-data.read`) rather than hardcoding providers.

Capability Registry / Router remains **PLANNED / NOT IMPLEMENTED**.  
See `AI_CONTROL_PLANE.md` § AI Router vs Capability Router.

---

## 16. Roadmap position

Do **not** reorder the immediate product track.

Immediate next implementation milestone remains:

1. **AI Provider Routing & Failover V1**
2. **Agent Profiles + Skill Library V1**

Later candidate milestones (UNCOMMITTED timing):

- Capability Registry / Routing V1
- Discovery Intelligence V1

Do not assign calendar dates. Do not automatically put Discovery ahead of operationally more valuable work.

---

## Explicit non-goals of this document

- Implementing Discovery runtime
- Installing Jina / Exa / social CLIs / browser automation
- Adding MCP
- Creating Discovery or Agent Reach Modules
- Creating migrations / Evidence types now
- Claiming outside-in Discovery is current product functionality

# Discovery Intelligence (Outside-in)

> **STATUS: Stage 1 stored public discovery — staging implementation; real operator UAT required**
> Owned primarily by `app-modules/website/` (no Discovery Module).  
>  
> Authority order: `MASTER_SPEC` → accepted ADRs → product blueprints → this doc.  
> Related:  
> [`AI_CONTROL_PLANE.md`](./AI_CONTROL_PLANE.md) ·  
> [`AGENT_SKILL_ARCHITECTURE.md`](./AGENT_SKILL_ARCHITECTURE.md) ·  
> [`KNOWLEDGE_MEMORY_ARCHITECTURE.md`](./KNOWLEDGE_MEMORY_ARCHITECTURE.md) ·  
> [`BRAND_INTELLIGENCE.md`](./BRAND_INTELLIGENCE.md) ·  
> [`docs/research/EXTERNAL_INTELLIGENCE_ADOPTION_AUDIT.md`](../research/EXTERNAL_INTELLIGENCE_ADOPTION_AUDIT.md).

## Stage 1 — stored public discovery (authorized 2026-09-06)

This section is the current contract on `chatgpt/search-demand-foundation`, based on staging commit `52b080bdc9e3e14507a0b4e57347287dcf73796d`. It supersedes the historical V1 execution description below for this branch only. Main was not used as implementation truth or modified. User authorization: the Public Discovery plan followed by “tamam yap” and “Devam et”. Product spec paths: `docs/MASTER_SPEC.md`, this document, `OPERATOR_ASYNC_EXECUTION.md`; accepted decision: ADR-061.

### Operator flow

1. Choose a Website in **Kamu Keşif**, then **Keşfi çalıştır**. Customer, Brand, Digital Asset, credentials and binding remain in the existing Integration workflow.
2. The operation first reads stored public HTML. If data is absent, older than seven days, corrupt or an error template, it requests public HTML through the existing Website Collection Engine. The operation resumes when that collection finishes; the operator can leave the page.
3. Review the known URL inventory, inspected/missing/stale/unreadable/ineligible counts, original source dates and up to 100 example gap URLs. A bounded inventory is never labelled the whole website. Partial collection remains visible even when some usable pages exist.
4. Review one candidate, inspect its source URLs and stored HTML, edit its value or select an existing destination. Save the decision. The receipt states what actually happened and links to the destination where applicable.
5. Previously accepted/ignored decisions remain intact on repeated discovery. An old approval without an application receipt can be explicitly reviewed and transferred; there is no automatic backfill. Kept scalar conflicts and address-only observations can be reviewed again, preserving receipt history.

### Sources, extraction and cost

- `StoredDiscoverySource` selects latest observations from `website_html_snapshot` and `website_url`, scoped to the Website. `StoredHtmlReader` checks raw-object ownership, raw checksum, decoded content hash and size. Only eligible public HTML is considered; HTTP 200 error templates are rejected through the existing Website page analyzer.
- Stored source age: seven days, with five minutes of clock tolerance. Read bounds: 500 pages, 32 MiB decoded HTML per pass and 5 MiB per stored/decoded object. Existing collector bounds remain unchanged: 5,000 URLs and 2,000,000,000 aggregate response bytes. For an existing inventory, refresh is targeted to at most 100 missing/stale/problem URLs per operation; remaining gaps stay partial and can be addressed by another run or Integration collection.
- Only observed same-site redirects may alias requested/final URLs, with newer direct observations taking precedence. Path case, slash and query identity are preserved. Unrelated redirects never establish ownership. Source dates are retained; processing time is separate.
- Services require Service/Product structured markup, a specific service/product/treatment page heading with body text, or a heading matching an existing active Brand Offering name. Short navigation labels alone are insufficient. This deterministic pass can miss unstructured services; it does not invent missing claims.
- `areaServed` / `serviceArea` statements are service-area candidates. Address/contact text remains source information. A homepage meta description can propose the business summary; descriptions of unrelated subpages do not replace the brand summary.
- Same-value claims combine source provenance. Profile/channel URL identity preserves case-sensitive paths. Accepted/ignored status, edited values and application receipts survive re-observation; the same valid unchanged HTML and known-service input reuses the prior successful Run.
- The basic operation calls neither AI nor paid providers, even if credentials are configured. Historical AI/competitor candidates remain readable and reviewable. It does not run the legacy standalone crawler, the Discovery Analyst or DataForSEO domain-overlap calls.

### Actual destinations

| Candidate | Human action | Persisted destination |
| --- | --- | --- |
| Service / product | Confirm name or map an existing Brand service | Active `BrandOffering` linked to active `ServiceCatalogItem`; receipt stores both IDs; existing priority/name wins |
| Service area | Explicitly confirm coverage; choose existing area or country/city/district | Additive `BrandServiceArea`; other areas and priorities remain intact |
| Physical address, phone, email | Save source information | Reviewed `DiscoveryCandidate` with `observation_only` receipt; address may instead become an explicitly confirmed service area |
| Historical competitor | Review domain | Canonical Competitor Library identity plus provenance; existing roles, classification, notes and relationships remain intact; rejected identities require review in the Library |
| Social profile | Confirm a supported public profile URL | `integration_ready` receipt; searchable, paginated **Keşiften gelen sosyal profiller** in Integrations, filterable by Brand; no automatic Digital Asset or binding |
| Language | Confirm document language code | Website `languages` |
| Business summary / positioning | Keep current or explicitly replace | Existing Brand Context, with a current-value comparison before replacement; a kept conflict is recorded, not reported as applied |

Review is transactional and requires an active authorized operator. Brand and Website identity must agree; selected service/area IDs must belong to that Brand. Archived services/areas are not restored. New stored-source candidates require a still-current, readable source at application time. Historical candidates are clearly labelled as historical and are never relabelled as fresh observations merely because a human reviewed them.

### Async and compatibility

`PublicDiscoveryJob` uses the canonical async parent Run and existing `website-discovery` child Run / Evidence. Per-operation and per-Website locks guard duplicate jobs; collection uses the durable `public-discovery:<operation-id>` idempotency key. Only the public HTTP/HTML and crawl families are requested, with paid enrichment disabled. The auto-discovered `ResumePublicDiscoveryAfterCollection` listener resumes the matching operation after commit. The existing five-minute stale-operation scheduler also recovers a missed terminal collection event. Failed/cancelled collection does not cause an automatic recollection loop.

Candidate pagination replaces the old 80-row ceiling on the dedicated review screen. Legacy Brand/Website previews retain their read-model keys; source summaries now belong to the displayed child Run. No new result entity, plugin framework, database table or dependency is added.

### Verification and remaining work

Verification on 2026-09-07: 70 targeted tests / 365 assertions, Pint, the frontend build and Blade compilation passed. The capability state is recorded in `PRODUCT_CAPABILITY_LEDGER.md`. PHPUnit uses an isolated in-memory SQLite database and synthetic stored HTML / fake HTTP. Automated checks cover stored-source reuse, actual collection planning and resumption, duplicate delivery, canonical transfers, preservation of decisions, corruption/ownership/source freshness, language/profile extraction, limits, TR/EN operator routes and Integration handoff. Two older collector-budget fixtures were corrected to use the collector's already-existing 2 GB constant instead of the legacy standalone crawler's 8 MB constant; production collection limits were not changed.

Real staging PostgreSQL, queue/Supervisor operation, representative customer websites and human visual UAT remain unclaimed. The local PHP runtime lacks `ext-intl`; IDN behaviour requires verification in the deployed runtime. The frontend build's existing bundle-size/font optimization advisories are outside this slice.

Agent-Reach was reviewed as an architectural reference; no runtime, installer, cookie flow or browser automation was installed. Stage 1 does not add web/SERP research, social-post analysis, reviews/mentions, Jina readers or scheduled multi-brand monitoring. Those are subsequent, separately bounded Public Discovery stages. The existing competitor Library and other Website workspaces remain available.

## Historical V1 scope

- Bounded public Website discovery (operator-triggered **Discover public context**)
- SSRF-safe public HTTP retrieval (no JS/browser execution, no login/cookie scraping)
- Canonical Run provenance (`module_id`: `website-discovery`)
- Normalized Discovery Evidence: `website_public_site_summary`, `website_public_page_snapshot`, optional `website_public_competitor_candidates`
- Deterministic public fact extraction + Brand Context **candidates**
- Fact vs Inference trust distinction
- Human **Accept / Edit & Accept / Ignore** via one `discovery_candidates` table
- Public social-profile **link** discovery from the Website only (no social platform crawl)
- Competitor **candidate** discovery via DataForSEO Labs `competitors_domain/live` when configured (cost-guarded); never fabricated by AI; never auto-accepted
- Website Brand Discovery Analyst (`website.brand_discovery_analyst` @ 1.0.0) + route `website.discovery_context` + Skill `brand-context-discovery`
- Human overrides win; no silent Brand Context overwrite

## Still PLANNED (not in V1)

- Full competitor Website comparison / crawl
- Social platform intelligence (posts/followers/engagement)
- Reviews / reputation / public mentions / news monitoring
- General web search capability
- Continuous / scheduled Discovery monitoring
- Capability Router / AdapterRegistry
- Discovery Playbooks
- Semantic retrieval / RAG / embeddings
- Autonomous Recommendations / automatic Tasks from Discovery
- Agent Reach runtime / browser automation

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

## 2. Outside-in Intelligence (**V1 partial — Website public discovery IMPLEMENTED**)

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

## 4. Combined product model (**direction; V1 feeds Evidence + candidates**)

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

## 5. Website without connection (**IMPLEMENTED V1**)

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

## 7. Brand Context discovery (**IMPLEMENTED V1 — candidates + human review**)

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

## 9. Competitor discovery (**IMPLEMENTED V1 — candidates only when DataForSEO configured**)

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

## 13. Discovery Evidence (**IMPLEMENTED V1 — Website-owned types**)

Future Discovery data that affects MoxDOP analysis should enter canonical provenance where appropriate.

Potential future Evidence types may be created by the responsible module or Discovery workflow.

Every significant discovered observation should be attributable to:

- source URL / provider
- `retrieved_at`
- adapter / capability
- Run
- normalization version
- confidence / type if derived

V1 Evidence types are created by Website Discovery. Do not invent parallel Discovery DBs.

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

---

## Explicit non-goals (remain)

- Creating a Discovery Module or Agent Reach Module
- Installing Jina / Exa / social CLIs / browser automation / MCP
- Capability Router runtime
- RAG / embeddings
- Full competitor crawl or social platform scraping
- Autonomous Brand Context overwrite

## 16. Roadmap position (updated)

Discovery Intelligence V1 is **IMPLEMENTED**. Next milestone remains **TO BE SELECTED** among product candidates (Meta Ads, GBP Reputation, Competitor Comparison, Capability Router, Digital Operations Analyst, richer Outcomes, Playbooks). Do **not** auto-select RAG.

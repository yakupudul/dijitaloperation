# MoxDOP Master Product Blueprint

> **Canonical product overview** — what MoxDOP is from the **operator’s** point of view.  
> Not a technical architecture document. Domain detail lives in sibling blueprints under `docs/product/**`.  
> Authority: `docs/MASTER_SPEC.md` wins on conflict; this document elaborates operator intent and information architecture.  
> Canonical application URL: **`/app`**. One application only.

---

## 1. Product purpose

**MoxDOP is Moximu’s Internal Agency Operations OS.**

It is the single place where Moximu staff manage customers, brands, and the digital assets attached to those brands — then connect data, understand what is happening, analyze (human and AI), turn issues into internal work, and verify whether improvement was later observed.

MoxDOP is **not**:

- a SaaS product sold to many agencies
- a customer / client portal
- a multi-tenant workspace platform
- a billing or subscription system
- a tool that writes into Meta, Google Ads, WordPress, GBP, or any external platform

It is an **internal operations system** for agency owners and team members. Customers never log in. The product exists so Moximu can supervise, prove, diagnose, recommend, and execute agency work without scattering truth across spreadsheets, ad UIs, and individual employee memory.

**Core promise to the operator:**  
*I can open a brand, see what we manage, see what we know and how we know it, see what needs attention, turn that into internal tasks, and later see whether associated improvement was observed — without guessing or rewriting the outside world.*

---

## 2. Agency workflow (end-to-end)

The operator mental model is a continuous loop, not a one-shot audit:

```text
Connect providers (agency-level)
→ Import / discover available accounts & properties (import pool)
→ Create or open Customer
→ Create or open Brand under that Customer
→ Register Digital Assets under the Brand
→ Attach imported resources (or public discovery / manual facts) to those assets
→ Understand current state (overview, freshness, coverage)
→ Analyze (human review and/or AI guidance on the same evidence)
→ Findings appear (durable issues / opportunities)
→ Recommendations are drafted or accepted
→ Internal Tasks are created and worked
→ Later collections re-evaluate linked Findings
→ Outcome signal: associated improvement observed (or not) — no causality claim
→ Operational continuity: another employee can take over from History / Decisions
```

**Read → Collect → Analyze → Diagnose → Recommend → Internal task → Observe later.**  
There is **no external write** at any step. Completing a Task never pauses an ad, edits a website, or replies to a review inside the provider.

### Demo customer (canonical example)

Throughout product conversations and demos, use:

| Level | Example |
| --- | --- |
| **Customer** | Atlas Health Group |
| **Brand** | Atlas Dental Ankara |
| **Context** | Dental clinic, Ankara, Türkiye |

Do **not** use Acme or generic placeholder brands in operator-facing copy.

---

## 3. Customer → Brand → Digital Asset model

```text
Customer
  └── Brand
        └── Digital Asset(s)
              └── Connections / Bindings / Discovery sources
```

| Entity | Operator meaning |
| --- | --- |
| **Customer** | The real organization Moximu serves (e.g. Atlas Health Group). Root of the portfolio. Holds contacts and responsible team members. Not a SaaS tenant. |
| **Brand** | A market-facing brand under that customer (e.g. Atlas Dental Ankara). Shared business context for every channel: sector, geography, languages, audience, offerings. Brand is **context**, not a channel. |
| **Digital Asset** | A concrete thing the agency manages for that brand: Meta Ads account, Google Ads account, Website, GBP location, Domain, Hosting, etc. |
| **Connection / Binding** | How MoxDOP reads data *about* an asset. Connections are not assets. GA4 and Search Console attach to the Website asset; they are not separate Digital Assets. |

**Invariants operators must feel in the UI:**

- Every Brand belongs to exactly one Customer.
- Every Digital Asset belongs to exactly one Brand.
- Cross-customer brands do not exist.
- Channel accounts are assets under a brand, not separate “customers.”
- There is no Workspace / tenant switcher — one Moximu installation, one agency.

---

## 4. Connected-data workflow

Connected data is **inside-out**: authenticated, first-party operational data from providers Moximu has authority to read.

### Operator sequence

1. **Integrations (agency-level)** — Admin connects Google, Meta, DataForSEO, AI providers once for the agency. Credentials live at agency level, not per customer.
2. **Connect / authorize / test** — Operator sees honest status: Connected, Configured, Needs attention, Not configured, Disabled.
3. **Import / discover into an import pool** — Provider accounts and properties appear as available resources (e.g. Meta Ad Accounts, Google Ads customers, GSC properties, GA4 properties, GBP locations).
4. **Attach to a Brand’s Digital Asset** — Operator binds a discovered resource to the correct asset under Atlas Dental Ankara (or creates the asset first, then binds).
5. **Data appears** — After successful collection, the asset workspace shows performance, coverage, and analysis surfaces grounded in that binding.

**Authenticate once at agency level → bind many resources to Digital Assets.**

Operators should answer:

- Which services are connected for Moximu?
- Which accounts/properties are available to attach?
- Which Brand asset is this resource attached to?
- Is data flowing, and when was it last updated?

Operators should **not** need to understand internal storage names, credential payload shapes, or binding table IDs.

---

## 5. Unconnected / public-discovery workflow (with provenance)

Not every useful fact requires a connected ad or analytics account. **Outside-in** discovery uses public or approved external signals **before** or **without** first-party connections.

Examples for Atlas Dental Ankara:

- Public website HTML summary and on-page facts
- Public social profile **links** found on the site (not a social platform crawl by default)
- Competitor **candidates** from approved providers (never fabricated, never auto-accepted)
- Later: broader public brand / Maps / search presence (see Future capabilities)

**Rules:**

- Discovery feeds the same Customer → Brand → Asset → Finding pipeline; it does not invent a parallel product.
- Every discovered fact shows **provenance** (source type, when collected, confidence / fact vs inference).
- Humans **Accept / Edit & Accept / Ignore** candidates. Silent overwrite of Brand context is forbidden.
- Public discovery is never presented as connected first-party performance.
- No login/cookie scraping of private areas; no external writes.

---

## 6. Asset lifecycle (domain, SSL, hosting, renewals)

Some Digital Assets are **lifecycle** concerns, not only analytics channels:

| Concern | Operator need |
| --- | --- |
| **Domain** | What domains does this brand own/use? Registrar context? Expiry / renewal dates? DNS/pointing awareness at a product level (not a full DNS control panel). |
| **SSL** | Is HTTPS healthy? Certificate validity window? Obvious misconfiguration signals when Evidence exists. |
| **Hosting** | Where is the site hosted (when known)? Plan/context notes for continuity — not hosting panel automation. |
| **Renewals** | What renews soon (domain, SSL, hosting, critical subscriptions the agency tracks)? Who is responsible inside Moximu? |

Lifecycle exists so a second employee can answer *“what expires next for Atlas Dental Ankara?”* without hunting email threads. It does **not** include purchasing domains, changing DNS, or logging into hosting panels on the operator’s behalf via write APIs.

---

## 7. Human analysis workflow

Humans remain accountable for operational decisions.

Typical human path:

1. Open Brand / asset workspace (e.g. Meta Ads for Atlas Dental Ankara).
2. Glance health, freshness, and top signals.
3. Explore breakdowns and evidence-backed detail.
4. Review Findings (open issues / opportunities with durable identity).
5. Accept, edit, or dismiss Recommendation drafts.
6. Create **internal** Tasks with assignee, due date, and clear work notes.
7. Complete Tasks in MoxDOP; perform real-world changes **outside** MoxDOP (in Meta Ads Manager, WordPress admin, etc.).
8. After later collections, review Outcome signals on completed Tasks.

Human analysis may run **without** AI. Deterministic checks and operator judgment are first-class.

---

## 8. AI analysis workflow

AI assists on the **same Evidence** humans see. It does not get a private data path and does not write externally.

| AI may | AI must not |
| --- | --- |
| Explain Findings in operator language | Invent metrics not present in Evidence |
| Suggest Recommendation drafts | Auto-create Tasks with fake assignees/due dates |
| Highlight priorities and hypotheses clearly labeled as guidance | Claim causal “this will increase ROAS by X%” without Evidence |
| Use Brand context the agency has accepted | Silently overwrite Brand facts from guesses |
| Fail over across configured AI providers with safe provenance | Bypass eligibility / route controls |

**Same evidence, structured guidance, no external writes.**  
Platform-attributed metrics stay labeled as platform signals; they are never silently renamed as verified business revenue.

---

## 9. Finding → Recommendation → Task → Outcome loop

```text
Finding
  → Recommendation
    → Task (human-created)
      → human completes work (outside and/or notes inside)
        → later comparable evaluation of the same Finding
          → Outcome signal on the Task
```

| Concept | Operator meaning |
| --- | --- |
| **Finding** | Durable issue or opportunity on a Digital Asset, with stable identity across collections. |
| **Recommendation** | Proposed internal improvement direction grounded in Evidence / Finding. |
| **Task** | Human work item Moximu commits to. Completing it does not mutate the outside world via MoxDOP. |
| **Outcome** | Observed signal after follow-up collection — **not** a separate Result entity. |

### Language rule (non-negotiable)

Use language like:

> **Associated improvement observed** after this Task was completed (linked Finding no longer present in a successful follow-up evaluation).

Do **not** use:

> This Task fixed the problem / caused the lift / increased conversions.

Outcome is **observed association**, not scientific causal attribution. Task status (open / completed) and Outcome status (e.g. improvement observed / still observed / insufficient evidence) are separate.

---

## 10. Operational continuity / another employee taking over

MoxDOP must make handoff cheap. When a team member is out, another operator opening **Atlas Dental Ankara** should see:

- Who the customer and brand are, and who is responsible
- Which Digital Assets exist and their connection / data health
- Open Findings, Recommendations, and Tasks (with owners and due dates)
- Recent History / Decisions: what was accepted, ignored, completed, and what Outcomes were observed
- Provenance: what is connected data vs public discovery vs manual note

Continuity is a **product requirement**, not a side effect of CRUD. If critical context lives only in Slack or one person’s head, the blueprint is not met.

---

## 11. Information architecture & global navigation

### One application

- Canonical URL: **`/app`**
- Single Filament panel (`app`). Filament is the **implementation shell**, not a second product brand in the UI.
- No separate “Modules” product area in primary navigation for operators.
- No customer-facing site, no second admin product.

### Primary global navigation (operator)

| Area | Role |
| --- | --- |
| **Portfolio** | Customers → Brands → Digital Assets. The day-to-day home for “what do we manage?” |
| **Data / Integrations** | Agency provider connections, import pool, attach flows, service health. |
| **Operations** | Cross-portfolio Findings, Recommendations, Tasks, and activity that needs action today. |

**Settings** is secondary (users/roles, preferences, advanced admin). It must not compete with Portfolio as the daily home.

**Modules** as a primary nav item is **out**. Module enablement, if exposed at all, is admin/settings territory — operators think in customers, brands, assets, and work — not plugin catalogs.

### Progressive attention model (every workspace)

Shared across asset workspaces:

```text
GLANCE → EXPLORE → DECIDE → DEEP DATA
```

Default landing is Glance. Deep data is opt-in. Missing data must never render as fake zero.

---

## 12. Brand workspace

Opening **Atlas Dental Ankara** should feel like the brand’s operations home.

| Area | Answers |
| --- | --- |
| **Overview** | What is this brand? Health snapshot across assets? What needs attention today? Data freshness at brand level? |
| **Digital Assets** | What do we manage (Meta Ads, Google Ads, Website, GBP, Analytics bindings, Domain, Hosting, …)? Status of each? |
| **Findings** | Open / resolved issues and opportunities across assets, prioritized for humans. |
| **Recommendations** | Draft and accepted improvement directions waiting for decision. |
| **Tasks** | Internal work committed for this brand; owners; due dates; outcome signals after completion. |
| **History / Decisions** | What the team decided, accepted, ignored, completed — enough for handoff and auditability without engineering jargon. |

Brand Overview must not invent cross-channel scores when Evidence does not support them.

---

## 13. Primary Digital Asset workspaces

Each asset workspace answers a focused operator question set. Structure stays consistent; metrics differ.

### 13.1 Meta Ads

**Must answer:**

- Which Meta Ad Account is attached to this brand?
- Delivery and spend health for the selected period?
- What is working / stalling at campaign → ad set → ad depth?
- Which creatives and placements matter?
- Open Findings and Recommendations for paid social?
- Data Health / last sync — honest, not decorative?

Platform results stay labeled as Meta-attributed signals, not verified clinic revenue.

### 13.2 Google Ads

**Must answer:**

- Which Google Ads account is attached?
- Spend, delivery, and account access health?
- Campaign / search-term / conversion-action signals present in Evidence?
- Disapprovals, limited assets, or zero-impression budget waste when Evidence supports them?
- Landing URL consistency hints vs Brand Website when both exist?
- Findings → Recommendations → Tasks path clear?

No pause/edit/budget write actions from MoxDOP.

### 13.3 Website

**Must answer:**

- What is the primary domain / URL for Atlas Dental Ankara?
- Technical / on-page / SEO diagnosis status?
- Which site connections exist (WordPress, PageSpeed/Lighthouse, DataForSEO usage, etc.)?
- What Findings block trust, indexability, or conversion clarity?
- Public discovery candidates vs connected facts — clearly separated?

No WordPress content writes.

### 13.4 GBP / Maps

**Must answer:**

- Which location profile is managed?
- NAP completeness and consistency vs Website?
- Hours, categories, phone, website URL gaps?
- Review aggregate signals when Evidence exists?
- Local visibility Findings and internal Tasks?

No review replies or profile edits via MoxDOP.

### 13.5 Analytics (GA4)

**Must answer (as Website-attached capability):**

- Which GA4 property is bound?
- Traffic / engagement / conversion signals available for the period?
- Collection freshness and property access health?
- How Analytics evidence supports Website (and later cross-asset) Findings?

GA4 is **not** a separate Digital Asset type in the core model.

### 13.6 Search Console

**Must answer (as Website-attached capability):**

- Which GSC property is bound?
- Query / page visibility and coverage signals present?
- Indexation or enhancement issues evidenced?
- How GSC evidence feeds SEO Findings and Recommendations?

Search Console is **not** a separate Digital Asset type in the core model.

### 13.7 Domain

**Must answer:**

- Which domain(s) belong to this brand?
- Registrar / ownership notes the agency needs for continuity?
- Expiry / renewal visibility when tracked?
- Obvious DNS/pointing context that affects Website health (product-level, not a DNS console)?

### 13.8 Hosting

**Must answer:**

- Where is the site hosted (when known)?
- Plan / provider notes for handoff?
- Renewal or capacity notes the agency tracks?
- Relationship to Website asset clear?

No hosting control-panel automation.

---

## 14. Integration / import experience

Integrations are a **service control center**, not a generic database CRUD screen.

**Required product behaviors:**

- Provider cards with operator-ready status (Connected / Needs attention / …).
- Provider-specific workspaces (Google ≠ Meta ≠ DataForSEO ≠ AI providers).
- Account-level / resource-level progress: discovery, authorization, import, bind — **named steps**.
- **No fake generic progress bars** that imply work while nothing real is happening.
- Import pool: discovered resources waiting to be attached to Brand assets.
- Attach flow names the Brand and Digital Asset in human language (“Attach to Atlas Dental Ankara → Meta Ads”).

Failure states must say what broke in operator language (“Meta authorization needs attention”) rather than raw exception dumps.

---

## 15. Activity / progress experience

Operators need a truthful activity surface for long-running work (imports, collections, discovery, AI guidance).

**Must show:**

- What started, for which Brand / asset / provider
- Current state: queued, running, succeeded, failed, partial
- When it started / finished
- What became available afterward (e.g. “Meta campaigns imported”, “Website public discovery ready for review”)

**Must not show:**

- Decorative spinners detached from real jobs
- Fake 0–100% bars without measurable stages
- Internal job class names as the primary label

Activity exists so the operator trusts the system while waiting — and so a second employee can see what was already kicked off.

---

## 16. Data Health

Data Health is a first-class operator concept on Brand and asset surfaces.

| Signal | Meaning |
| --- | --- |
| **Good** | Required connections healthy; recent successful collection; coverage adequate for the views shown |
| **Partial** | Some sources missing, truncated, or not yet collected — numbers may be incomplete and must be labeled |
| **Degraded / Needs attention** | Auth failure, repeated collection failure, stale beyond acceptable freshness, or binding broken |

Compact badge on Glance → drawer/detail on demand. Data Health never invents metrics to look “green.” Missing ≠ zero.

---

## 17. Source / provenance rules

Every meaningful fact the operator sees should be attributable to one of:

| Source type | Meaning | Operator treatment |
| --- | --- | --- |
| **Connected provider** | Authenticated first-party read via agency Integration + binding | Highest operational trust for performance truth; show provider + last sync |
| **Public discovery** | Outside-in public / approved external retrieval | Labeled public; candidates need Accept / Edit / Ignore before becoming Brand truth |
| **Detected** | Derived by deterministic rules from Evidence (e.g. CMS detected, SSL state) | Show as detected; allow correction paths where product supports them |
| **Manual** | Entered or overridden by a Moximu operator | Explicitly manual; wins over silent automation |

**Rules:**

- Never blend connected spend metrics with public guesses in one unlabeled number.
- Fact vs inference must stay distinguishable in discovery and AI guidance.
- Provenance remains visible in History / Decisions and Deep Data.
- Credentials never appear in provenance panels, Evidence, or screenshots surfaces.

---

## 18. What the operator should see vs technical details to hide

### Operator should see

- Customer / Brand / Asset names and status
- Connection and Data Health in plain language
- Period, freshness (“Updated 3 hours ago”)
- KPIs and Findings tied to questions they must answer today
- Recommendations and Tasks with owners
- Outcome wording that avoids false causality
- Provenance labels (Connected / Public / Detected / Manual)

### Hide or demote (engineering / debug only)

- Internal table/model names (Run IDs as primary labels, Evidence type enums, ExternalResource, CoreAssetBinding)
- Credential payloads, tokens, developer tokens
- Raw provider JSON dumps as the default view
- Module registry / package names in primary nav
- Stack traces as the only error explanation
- Filament / framework terminology as product language

Translate: Run → “last sync / last collection”; Evidence → “data collected on …”; Binding → “connected account.”

---

## 19. Future capabilities

Directionally in-scope after foundation and vertical slices (not commitments to ship in one pass):

- Richer Public Brand Discovery beyond Website V1
- Cross-asset Brand Intelligence (Website ↔ Ads ↔ GBP consistency packs)
- Digital Asset Lifecycle depth (renewals, SSL, hosting continuity)
- Agency Learning / Outcome memory (what tended to help *similar* situations — still non-causal)
- Instagram / YouTube / CRM Digital Assets following the same read-only pipeline
- Stronger Activity Center and portfolio-wide Operations inbox
- Capability Router / playbooks (planned AI control-plane direction)
- Deeper analyst workspaces per channel under the shared Glance → Explore → Decide → Deep Data standard

Still **explicit non-goals** unless MASTER_SPEC changes: SaaS multi-tenant, client portal, marketplace modules, external write actions, billing.

---

## 20. Demo Mode purpose

**Demo Mode exists so product shape can be approved before real provider wiring is complete.**

Purpose:

- Let stakeholders walk Portfolio → Brand (Atlas Dental Ankara) → asset workspaces → Findings → Recommendations → Tasks → Outcome language using coherent demo data.
- Validate information architecture, navigation, empty/partial states, and operator copy.
- Prove that connected vs public vs manual provenance is understandable.
- Avoid blocking UX approval on OAuth, production tokens, or live ad accounts.

Demo Mode is **not** permission to fake causality, invent “verified revenue” from platform pixels, or imply external writes. When real wiring lands, Demo Mode yields to live Evidence without changing the operator mental model.

**Gate:** Product approval of Demo Mode experience precedes treating a vertical slice as “real” for that channel.

---

## 21. Master capability backlog (vertical slices after demo approval)

Build as **vertical slices**. Each slice must earn **operator acceptance** before the next starts. Demo approval is the prerequisite gate for treating slices as production-intent wiring.

| # | Slice | Operator outcome when accepted |
| --- | --- | --- |
| **1** | **Product shell + Portfolio persistence** | `/app` navigation (Portfolio, Data/Integrations, Operations; Settings secondary). Customers, Brands, Digital Assets persist. Atlas Health Group / Atlas Dental Ankara usable as real records. Hand-off-ready Brand workspace shell. |
| **2** | **Meta end-to-end real** | Agency Meta Integration → discover Ad Accounts → bind to Meta Ads asset → collect → workspace Glance/Explore → Findings/Recommendations/Tasks path on live read-only data. |
| **3** | **Google Ads end-to-end real** | Same pattern for Google Ads account: connect, import, attach, collect, analyze, operate internally without writes. |
| **4** | **Website end-to-end real** | Website asset with diagnosis/connectors path, Evidence → Findings → Recommendations → Tasks, honest Data Health. |
| **5** | **Google Business / Maps end-to-end real** | GBP asset read-only collect and operational loop for local presence. |
| **6** | **Analytics / Search Console** | GA4 + GSC bound to Website; operator sees analytics/search evidence in Website (and brand) workflows with provenance. |
| **7** | **Public Brand Discovery** | Outside-in discovery with Accept/Edit/Ignore, clear provenance, feeding Brand context without silent overwrite. |
| **8** | **Cross-Asset Brand Intelligence** | Deterministic cross-channel packs (e.g. Ads landing vs Website, NAP vs GBP) producing Evidence-backed Findings across assets. |
| **9** | **Digital Asset Lifecycle** | Domain / SSL / hosting / renewals visibility and continuity for operators. |
| **10** | **Agency Learning / Outcome memory** | Memory of observed Outcomes and reusable guidance patterns — still framed as associated observations, never causal guarantees. |

**Sequencing rule:** Do not start slice *N+1* as the primary delivery focus until operators accept slice *N* for the intended workflow. Parallel technical prep is allowed; product acceptance is the gate.

---

## Explicit non-goals (summary)

- SaaS / multi-tenant / Workspace product
- Customer login / client portal
- Billing, plans, quotas as product surface
- External write actions to any provider
- Modules marketplace / ZIP upload as operator journey
- Separate Result entity as the Outcome model
- Decorative dashboards and fake progress
- Acme-style demo naming (use Atlas Health Group / Atlas Dental Ankara)

---

## Related blueprints

| Topic | Document |
| --- | --- |
| Upper authority | `docs/MASTER_SPEC.md` |
| Customer / Brand / Asset / Connection | `CUSTOMER.md`, `BRAND.md`, `DIGITAL_ASSET.md`, `CONNECTION.md` |
| Analysis & Outcome | `ANALYSIS_PIPELINE.md`, `OPERATIONAL_OUTCOME_LOOP.md` |
| Operator UX standard | `OPERATOR_WORKSPACE_DESIGN_STANDARD.md` |
| Integrations UX | `integrations/WORKSPACE.md` |
| Discovery | `DISCOVERY_INTELLIGENCE.md` |
| Channel modules | `meta-ads/*`, `google-ads/*`, `website/*`, `google-business-profile/*` |
| Cross-asset | `cross-asset/CROSS_ASSET_ANALYSIS.md` |
| Index | `docs/product/INDEX.md` |

---

*End of Master Product Blueprint.*

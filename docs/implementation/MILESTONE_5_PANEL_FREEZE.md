# Milestone 5 — Panel Design Freeze & Post-Freeze Backend Roadmap

Status reference for the frozen `/app` operator panel. UI work after this document requires product-level justification.

## Capability Reality Matrix

| Capability | Classification | Notes |
|---|---|---|
| Customer | PARTIAL | Demo portfolio + session state; relationship/services/requests demo-backed |
| Brand | PARTIAL | Demo catalog + business context session overrides |
| Brand Context | DEMO | Offerings/audiences/markets/goals in fixtures |
| Public Discovery | DEMO | Deterministic discovery candidates |
| Files | REAL | OperatorFile persistence, upload/download/auth |
| Website | PARTIAL | Specialist workspace + demo health/content; Site Connector pairing demo |
| Website Infrastructure | DEMO | Domain/DNS/hosting/CDN presented as Website sections |
| WordPress Site Connector | PARTIAL | Demo package + pairing UX; not production-installable |
| GBP | DEMO | Local visibility/reviews/competitors fixtures |
| Google Ads | DEMO / PARTIAL | Specialist IA complete; metrics from fixtures (provider import paths exist elsewhere) |
| Meta Ads | DEMO / PARTIAL | Specialist IA complete; import UX demo-backed |
| GA4 | DEMO / PARTIAL | First-class asset + evidence provider role; fixtures |
| Search Console | DEMO | Measured Search Console semantics preserved in copy |
| Instagram | DEMO | Lightweight Overview/Profile/Operations/Setup |
| Service Scope | DEMO | Session/fixture commercial scope |
| Goals | DEMO | Primary/conversion goals in fixtures |
| Opportunities | DEMO | Fixture list + DemoState status |
| Findings | PARTIAL | Demo findings + status overrides; fingerprint concept remains product truth |
| Recommendations | DEMO | Fixture recommendations + accept/create-task |
| Work | DEMO | Tasks/requests/reviews/approvals/QA via DemoState |
| Client Requests | DEMO | Customer Requests workspace + Work views |
| Approvals | DEMO | Approval states in session |
| Playbooks | DEMO | Catalog + knowledge fields; Settings → Operations |
| Recurring Reviews | DEMO | Due list + completion actions |
| QA | DEMO | QA required / approve flows |
| Capacity | DEMO | Transparent thresholds, not a score |
| Activity | DEMO | Timeline fixtures + scoped filters |
| Operational Outcomes | DEMO | Observed after work — no automatic causation |
| Business Outcomes | DEMO | Brand aggregate outcomes + Demo overrides |
| Reports / Value Story | DEMO | Deterministic assembly; no PDF/email delivery |
| AI Control Plane | PARTIAL | Route provider order editable under `/app`; credentials in Integrations |
| Agents | PARTIAL | Full read-only catalog under `/app/settings/ai/agents` (code registry) |
| Skills | PARTIAL | Full read-only catalog under `/app/settings/ai/skills` (code registry) |
| Notifications | DEMO | Deterministic bell when DB empty; preferences in Settings |
| Google Integration hub/detail (`/app/integrations`) | REAL (backend state) | Prompt 13: `GoogleIntegrationReadModel` / Core* — OAuth/discovery/bind/collect still PARTIAL |
| Google connector workspaces | DEMO / PARTIAL | Fixture control plane until Prompt 15/16 |

## Post-Freeze Backend Roadmap

### P0 — required to operate for real

1. **Core domain persistence (Customer / Brand / Digital Asset / Service Scope / Goals)**  
   - current state: PARTIAL/DEMO  
   - frozen UI: Portfolio + Brand Business  
   - missing: durable models beyond demo fixtures  
   - dependency: Eloquent schemas aligned to frozen IA  
   - why: everything else hangs off stable identities  
   - order: first

2. **Agency operations persistence (Findings, Opportunities, Recommendations, Work, Requests, Approvals, QA, Reviews)**  
   - current state: DEMO  
   - frozen UI: Operations + Work + Brand Operations  
   - missing: tables + fingerprint + ownership + due dates  
   - dependency: core domain IDs  
   - why: daily operator loop cannot stay session-only  
   - order: immediately after core domain

3. **Provider collection for scoped assets (read-only)**  
   - current state: DEMO/PARTIAL  
   - frozen UI: specialist Digital Asset workspaces  
   - missing: scheduled collection into Evidence  
   - dependency: Integrations bindings + credentials  
   - why: Evidence → Finding/Opportunity must be real  
   - order: after bindings are durable

### P1 — high-value operational capability

4. **Website / Site Connector production path**  
   - current: PARTIAL demo package  
   - frozen UI: Website Setup + Integrations Site Connectors  
   - missing: signed install, collection jobs, CMS evidence  
   - why: Website is central estate asset

5. **AI execution behind Control Plane / Agents / Skills**  
   - current: registries + Demo outputs  
   - frozen UI: Settings → AI & Intelligence  
   - missing: grounded runs, eligibility, fallback logging  
   - why: Growth synthesis without inventing omniscient agents

6. **Activity + Notifications persistence**  
   - current: DEMO  
   - frozen UI: Activity + bell  
   - missing: meaningful event stream, preference-driven delivery  
   - why: scale without noise

### P2 — meaningful expansion

7. **Reporting / export** — snapshot persistence, PDF, authenticated share (no fake Send today)  
8. **Business outcome imports** — manual/CSV/CRM normalize (not a CRM product)  
9. **Automation / scheduling** — recurring reviews, collection, notification cadence with human control

### P3 — optional / later

10. Production hardening (audit, rate limits, observability)  
11. Deeper Instagram provider capability only if real API support exists  
12. Write-capable provider actions remain out of default product posture

## Provider capability matrix (summary)

| Asset | Already real | Partial | Demo | Needed API | Default posture | Evidence produced |
|---|---|---|---|---|---|---|
| Website | Files/auth patterns | Connector pairing UX | Health/content/perf | Site Connector + crawlers | Read | Technical/content checks |
| GBP | — | — | Full specialist IA | Google Business Profile | Read | Profile, reviews, local visibility |
| Google Ads | Integration scaffolding | Import UX | Metrics/campaigns | Google Ads API | Read | Campaign/search/asset evidence |
| Meta Ads | Integration scaffolding | Import UX | Creatives/funnel | Meta Marketing API | Read | Delivery/creative evidence |
| GA4 | Integration scaffolding | Binding UX | Measurement/journeys | Google Analytics Data API | Read | Events/acquisition evidence |
| GSC | Integration scaffolding | Binding UX | Queries/indexing | Search Console API | Read | Query/page/index evidence |
| Instagram | — | — | Lightweight | Instagram Graph (later) | Read | Profile/ops only |

## AI capability matrix (summary)

- Agents / Skills / Routes / Allowed Evidence / Allowed & Forbidden Operations / Output Contract / Success Criteria / Eligibility / Fallback / Grounding are preserved in registries.
- `/app` exposes Control Plane editing + full Agent/Skill browse detail.
- Routine operator AI administration must not require `/system`.
- Execution remains non-autonomous for provider writes.

## Post-freeze product backlog (not required for completeness)

| Idea | Potential value | Why deferred |
|---|---|---|
| Global Approvals sidebar | Faster triage for large teams | Work segments already cover Approvals |
| Brand-level Files primary tab | Familiarity | Canonical Files + scoped action is enough |
| Numeric Brand Health Score | Quick scan | Opaque ranking violates product truth |
| Autonomous campaign pause/publish | Ops speed | External write posture forbidden by default |
| Full CRM / Billing modules | Commercial expansion | Outside agency digital operations north star |

## Freeze statement

The current MoxDOP operator panel information architecture and core product workflows are frozen. New operator features should require a clear product-level justification. The next development phase should primarily implement and harden the real backend capabilities behind the frozen product surfaces.

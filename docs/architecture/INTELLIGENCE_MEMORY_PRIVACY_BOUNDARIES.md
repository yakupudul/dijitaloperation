# Intelligence Memory Privacy Boundaries

> Prompt 51 — Brand isolation, cross-brand rules, Sector release gate, re-identification, Skill customer-data prohibition.

Related: layer contract · implementation architecture.

---

## 1. Brand isolation

- Brand Memory is **Customer-tenant confidential**.
- Access requires authorized Customer scope **and** exact Brand ID match.
- Brand A Memory is **never** resolvable for Brand B execution.
- Same Customer + different Brands ⇒ **still isolated**.
- Same sector / service / DigitalAsset type ⇒ **does not** grant Brand Memory access.
- Stable Brand ID remains owner across renames.
- Agency internal access follows existing workspace/permission model (do not assume all employees see all Customers).

Tests: `IntelligenceMemoryArchitectureTest` Brand/Customer isolation cases.

---

## 2. Cross-brand rules

| Pattern | Status |
| --- | --- |
| Raw Brand Memory sharing | **FORBIDDEN** |
| Similar-customer / nearest-neighbor Brand Memory | **FORBIDDEN** |
| Shared cross-customer vector namespace | **FORBIDDEN** |
| “Show what other dental clients do” via Brand Memory | **FORBIDDEN** |
| Privacy-qualified Sector aggregate (future) | **ALLOWED route** only after Prompt 53 gate |

---

## 3. Sector release gate

Interface: `App\Contracts\IntelligenceMemory\SectorLearningPrivacyGate`.

Prompt 51 stub (`DeferredSectorLearningPrivacyGate`) enforces:

| Block | Disposition |
| --- | --- |
| Missing sector identity | `blocked_sector_unknown` |
| AI-inferred sector attempt | Forbidden at `SectorIdentityRef` construction |
| Identifying keys in candidate (`brand_id`, names, URL, campaign, keyword, notes, …) | `blocked_raw_customer_data` |
| Raw provider/Evidence flags | `blocked_raw_customer_data` |
| One Brand cohort | `blocked_one_brand_insufficient` |
| Otherwise (until Prompt 53) | `blocked_pipeline_not_implemented` |

**No** magic minimum cohort integer in Prompt 51.  
**No** `privacy_score` number — explicit PASS/BLOCK + reasons only.

Usable Sector artifact requires Prompt 53: cohort policy, contribution bounding, aggregation method version, re-identification review.

Consumer-facing Sector payloads must **never** include contributor Customer/Brand IDs or names.  
`SectorPrivacyGateDecision.safeMetadata` rejects those keys at construction.

Restricted internal lineage (audit/deletion), if added later, stays outside normal consumer/Agent retrieval contracts.

---

## 4. Re-identification constraints

High-risk combinations (exact spend/leads, rare specialty, tiny city, short period, unique campaign/URL/keyword, free text) must not ship as consumer Sector Memory without Prompt 53 qualification.

MoxDOP cohort aggregates are **cohort observations**, not automatic industry standards.

---

## 5. Skill Memory customer-data prohibition

`SkillMemoryCustomerDataGuard` rejects payloads containing customer/brand identifiers, domains/URLs, campaign/keyword performance fields, or embedded `customer_id`/`brand_id` literals.

Skill Memory may reference:

- Skill Definition signatures/versions
- Playbook revisions
- Primary references / Prompt 48 research provenance

Skill Memory must **not**:

- Duplicate Skill/Playbook corpora as a second mutable truth
- Auto-ingest Brand Experience or Sector Learning
- Accept Agent outputs as general knowledge

---

## 6. Write poisoning boundaries

| Input | Trusted Memory write? |
| --- | --- |
| AI / Agent structured result | **NO** (candidate only) |
| Website text | **NO** automatic Skill Memory |
| Customer request / review / task notes | **NO** Sector/Skill automatic |
| Task complete / QA pass / Recommendation accept | **NO** implied success Memory |
| Operator explicit Experience action | Future Brand Experience only (P52) |

`IntelligenceMemoryAccessPolicy::assertWriteAllowed` denies Agent/AI/listener direct writes in Prompt 51.

---

## 7. Mixed privacy class rejection

An artifact cannot be ambiguously “Sector + Brand-specific.” Layers are exclusive privacy classes.

---

## 8. Caching (future)

If caching is introduced, keys must include layer + scope + policy version. No cross-tenant cache payloads without isolation.

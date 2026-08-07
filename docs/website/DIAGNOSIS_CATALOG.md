# Website Diagnosis Catalog

> **Gate:** Required before Website Diagnosis implementation (ADR-031, `docs/product/website/DIAGNOSIS.md`).  
> **Scope:** Catalog definitions and authoritative references only — no connectors, jobs, or detection code.

## File metadata

| Field | Value |
| --- | --- |
| `catalog_id` | `website-diagnosis` |
| `version` | `0.1.0` |
| `status` | `draft-starter` |
| `locale` | `en` (finding templates; product UI may localize later) |
| `derived_from` | Open web standards (IETF RFCs, W3C), Google Search documentation, and common crawl/audit practice |
| `ai_rules` | **None** — all detection and recommendation rules in this catalog are deterministic |
| `related` | ADR-031; `docs/product/website/DIAGNOSIS.md`; MASTER_SPEC §12.1 |

## Catalog contract

Each catalog item is a stable diagnosis definition. Implementation must evaluate items only when required evidence is present; findings are never guessed without evidence.

### Required fields (per item)

| Field | Meaning |
| --- | --- |
| `id` | Stable kebab-case identifier; used in fingerprints / source_module mapping later |
| `category` | Finding category bucket (e.g. `availability`, `transport`, `indexability`, `on-page`) |
| `purpose` | Why this check exists for agency operators |
| `required_evidence` | Evidence types that must exist before the rule may fire |
| `optional_evidence` | Evidence that may raise confidence or refine the finding when present |
| `detection_rule` | Deterministic condition description (not code, not AI heuristics) |
| `severity` | Initial severity when the rule matches: `critical` \| `high` \| `medium` \| `low` \| `info` |
| `confidence` | Initial confidence when only required evidence is available: `high` \| `medium` \| `low` |
| `fingerprint` | Deterministic Finding identity contract: which normalized evidence parts join with `id` (same fingerprint → upsert across runs) |
| `finding_output` | `title` + `summary` templates; placeholders use `{name}` substitution from evidence |
| `recommendation_logic` | Brief deterministic rule to generate recommendation text from the same evidence |
| `source_dependency` | Which additional evidence or connection increases confidence / completeness |

### Evidence types referenced in this catalog

Logical evidence labels (normalized later by collectors; not API field dumps):

| Evidence type | Typical contents |
| --- | --- |
| `http_fetch` | Request URL, final URL, status code, response headers, timing, error class |
| `redirects` | Ordered hop list for an HTTP(S) fetch: `start_url`, `final_url`, `hop_count`, `hops` (`url`, `status`, `location`), `upgraded_to_https_same_host`, optional `error_class`. No response bodies. |
| `tls_info` | Normalized peer certificate observation for a host: `host`, `present`, `subject_common_name`, `issuer_common_name`, `valid_from` (ISO8601 UTC), `valid_to` (ISO8601 UTC / notAfter), `observed_at` (ISO8601 UTC), `fetch_method` (`php_stream` \| `curl`), optional `error_class`, optional `san_hosts` when available. No private keys or raw certificate dumps. |
| `robots` | Normalized robots.txt observation: `robots_url`, `effective_url`, `status_code`, `present` (true when HTTP `200` with a body string), `body` (UTF-8 text, truncated at 64 KiB), `body_truncated`, `parse_ok`, `has_user_agent_group`, `sitemap_urls`, `status_or_error`, optional `error_class`, optional `reason_code` (`fetch_5xx` \| `connection` \| `malformed`). No unrelated page HTML. |
| `sitemap` | Normalized sitemap fetch outcome for candidate URL(s): `host`, `tried_urls` (deterministic candidate list), `candidates_from_robots` (bool), `sitemap_url` (decisive/last candidate), `effective_url`, `status_code`, `present` (true when HTTP `200` with a body string), `parse_ok`, `root_element` (`urlset` \| `sitemapindex` \| null), `url_count` (child `url` or `sitemap` entries when parse_ok; else null), `body_truncated`, optional truncated `body` (UTF-8, max 64 KiB, only stored for `200` responses), `last_outcome` (`ok` \| `connection` \| `status_5xx` \| `status_404` \| `status_410` \| `malformed_xml` \| other `status_{code}`), optional `error_class`, optional `reason_code` (`fetch_failure` \| `not_found` \| `malformed`). No unrelated page HTML. |
| `page_html` | Normalized primary HTML observation: `final_url`, `status_code` (`200`), optional `content_type`, `head_html` (UTF-8 head excerpt, truncated at 64 KiB), `head_truncated`, `head_complete`, `canonical_hrefs`, `absolute_canonical_hrefs`, `relative_canonical_hrefs`, `canonical_state` (`missing` \| `absolute_single` \| `relative_only` \| `conflict_multiple` \| `conflict_mixed` \| `conflict_mismatch`), `telephone_candidates` (deduped raw strings from `tel:` hrefs, `itemprop=telephone` text, and JSON-LD `"telephone"` values; max 20), `postal_address_candidates` (structured objects from JSON-LD/`itemprop` PostalAddress fields with `street_address`, `locality`, `region`, `postal_code`, `country`, `formatted`; max 10; no full page dump). No full unrelated asset dumps. |

### Evaluation invariants

1. **No catalog → no diagnosis implementation** (product invariant).
2. **Deterministic rules first**; AI may later explain findings but must not invent catalog detections.
3. **No external write**; evidence is read-only observation of the public web surface.
4. Severity/confidence may be adjusted only by rules stated here (richer evidence → higher confidence), not by ad-hoc code.
5. **`recommendation_logic` is runtime-consumed** by `App\Support\WebsiteDiagnosisCatalog` when Website Diagnosis upserts Recommendations for Findings (ADR-031). Do not hard-code parallel recommendation copy outside this catalog.

---

## Catalog items

### 1. `reachability-http`

| Field | Value |
| --- | --- |
| **id** | `reachability-http` |
| **category** | `availability` |
| **purpose** | Confirm the website primary URL answers over HTTP(S) so later checks have a live target. |
| **required_evidence** | `http_fetch` for the asset primary URL (or configured start URL) |
| **optional_evidence** | `redirects` (clarifies whether failure is at an intermediate hop) |
| **detection_rule** | Fire when the fetch ends in a transport failure (DNS failure, TCP/TLS connect failure, timeout) **or** the final HTTP status is `5xx`, **or** no HTTP response is obtained. Do **not** treat `4xx` alone as unreachable (that is a separate status-code concern). Aligns with HTTP semantics for server error vs client error ([RFC 9110](https://www.rfc-editor.org/rfc/rfc9110)). |
| **severity** | `critical` |
| **confidence** | `high` (when `http_fetch` records a concrete error class or final status) |
| **fingerprint** | `sha256( "{id}\|url={normalized_start_url}" )` where `normalized_start_url` is the asset primary/start URL with lowercased scheme+host, default path `/` trimmed to empty, and query preserved. Status code, effective URL, and error class are **not** part of the fingerprint (same unreachable target upserts across runs). |
| **finding_output** | **title:** `Website not reachable` · **summary:** `Primary URL {start_url} did not return a successful HTTP response (outcome: {error_or_status}).` |
| **recommendation_logic** | If DNS/connect/timeout → recommend verifying DNS, hosting uptime, and firewall/CDN allowlists for the fetch origin. If final status `5xx` → recommend checking origin/CDN error logs and recent deploys. Include `{final_url}` when it differs from `{start_url}`. |
| **source_dependency** | Confidence stays high with a single conclusive `http_fetch`. `redirects` evidence increases diagnostic completeness (which hop failed) but does not change the reachability verdict. |

**Authoritative sources:** [RFC 9110 — HTTP Semantics](https://www.rfc-editor.org/rfc/rfc9110) (status classes); operational reachability is the prerequisite for all subsequent catalog items.

---

### 2. `https-tls-validity`

| Field | Value |
| --- | --- |
| **id** | `https-tls-validity` |
| **category** | `transport` |
| **purpose** | Detect missing, expired, or soon-to-expire TLS so visitors and crawlers are not served an insecure or untrusted HTTPS endpoint. |
| **required_evidence** | `tls_info` for the hostname of the primary URL (HTTPS port/probe for that host); `http_fetch` for the primary URL when available |
| **optional_evidence** | `redirects` (HTTP→HTTPS upgrade path); hostname/SAN coverage details inside `tls_info` |
| **detection_rule** | Fire when any of: (a) peer certificate cannot be obtained (`present=false` / certificate error class); (b) certificate `valid_to` (`notAfter`) is in the past relative to `observed_at`; (c) certificate `valid_to` is within **7 days** after `observed_at` (renewal risk); (d) when SAN/CN data is present, the requested host is not covered. Untrusted-chain and hostname-mismatch remain in-scope when those failure classes are observed in `tls_info.error_class`. Certificate identity and validity follow TLS 1.2+ practice and PKIX ([RFC 5280](https://www.rfc-editor.org/rfc/rfc5280), [RFC 9110](https://www.rfc-editor.org/rfc/rfc9110) HTTPS). |
| **severity** | `high` for missing/expired/not-yet-valid/hostname mismatch/untrusted chain; `medium` when the certificate is still valid but expires within 7 days |
| **confidence** | `high` |
| **fingerprint** | `sha256( "{id}\|host={normalized_host}" )` where `normalized_host` is the lowercased hostname from the primary URL (no port). Validity dates, issuer, and error class are **not** part of the fingerprint (same host upserts across runs). |
| **finding_output** | **title:** `HTTPS/TLS certificate problem` · **summary:** `TLS for host {host} failed validation ({tls_failure_reason}); certificate notAfter={not_after}.` |
| **recommendation_logic** | If missing → install a publicly trusted certificate for `{host}`. If expired/not-yet-valid/expiring within 7 days → renew or replace the certificate before/after expiry and verify chain completeness. If hostname mismatch → install a cert whose SAN includes `{host}` (and `www` variant if used). If untrusted chain → include intermediates / use a publicly trusted CA. |
| **source_dependency** | Requires successful collection of `tls_info`. When only plaintext HTTP is available and HTTPS never negotiates, confidence remains high for “HTTPS broken/unavailable” based on `tls_info` / `http_fetch` TLS error class. Optional `redirects` showing no HTTP→HTTPS upgrade increases related transport completeness but is evaluated under `redirect-http-to-https`. |

**Authoritative sources:** [RFC 5280](https://www.rfc-editor.org/rfc/rfc5280) (PKIX); [RFC 8446](https://www.rfc-editor.org/rfc/rfc8446) (TLS 1.3); Google Search — [Secure sites with HTTPS](https://developers.google.com/search/docs/appearance/https).

---

### 3. `redirect-http-to-https`

| Field | Value |
| --- | --- |
| **id** | `redirect-http-to-https` |
| **category** | `transport` |
| **purpose** | Ensure plain HTTP entry points upgrade to HTTPS so mixed insecure entry does not persist. |
| **required_evidence** | `http_fetch` for the `http://` form of the primary host; `redirects` hop list for that fetch |
| **optional_evidence** | `tls_info` (confirms HTTPS target is usable after upgrade) |
| **detection_rule** | Given an `http://` start URL whose host matches the asset domain: fire when the final URL after redirects is still `http:` **or** when there is no redirect to an `https:` URL with the same host (or declared canonical host). Treat a single hop `301`/`308` (or `302`/`307`) to `https://` same host as pass. Do **not** fire when the HTTP entrypoint fetch fails with a transport/`connection` error class (inconclusive upgrade path; reachability covers availability). Redirect status meanings per [RFC 9110 §15.4](https://www.rfc-editor.org/rfc/rfc9110#name-redirection-3xx). |
| **severity** | `medium` |
| **confidence** | `high` |
| **fingerprint** | `sha256( "{id}\|host={normalized_host}" )` where `normalized_host` is the lowercased hostname from the HTTP start URL (no port). Final URL, hop count, and status codes are **not** part of the fingerprint (same host upserts across runs). |
| **finding_output** | **title:** `HTTP does not upgrade to HTTPS` · **summary:** `Request to {start_url} ended at {final_url} without a stable HTTPS upgrade ({hop_count} redirect hop(s)).` |
| **recommendation_logic** | Recommend configuring the origin/CDN to redirect all `http://` URLs to `https://` with a permanent redirect (`301` or `308`), preserving path and query unless a deliberate host normalization applies. If HTTPS is broken, fix `https-tls-validity` first. |
| **source_dependency** | Confidence increases when `tls_info` shows a valid certificate on the HTTPS target (upgrade is both present and trustworthy). Without `tls_info`, the missing-upgrade finding still stands from `redirects` alone. |

**Authoritative sources:** [RFC 9110](https://www.rfc-editor.org/rfc/rfc9110) (redirects); Google — [Secure sites with HTTPS](https://developers.google.com/search/docs/appearance/https).

---

### 4. `robots-txt-availability`

| Field | Value |
| --- | --- |
| **id** | `robots-txt-availability` |
| **category** | `indexability` |
| **purpose** | Establish whether crawlers can obtain a robots exclusion file and whether it is well-formed enough to apply. |
| **required_evidence** | `robots` for `https://{host}/robots.txt` (or HTTP equivalent if HTTPS unavailable); supporting `http_fetch` status for that URL |
| **optional_evidence** | `sitemap` (cross-check Sitemap: directives declared in robots) |
| **detection_rule** | Fire **medium** when fetch returns final status `5xx` or transport failure for `/robots.txt`. Fire **low** when body is present but contains no `User-agent` group yet includes non-empty non-comment text that cannot be parsed as a robots group (malformed). Do **not** fire solely because `404` — absence of robots.txt means “no robots restrictions” per common crawler practice and the Robots Exclusion Protocol. Disallow semantics follow [RFC 9309](https://www.rfc-editor.org/rfc/rfc9309). |
| **severity** | `medium` (fetch/server failure) / `low` (malformed body) — record the matching case in evidence-derived reason code |
| **confidence** | `high` for status/transport outcomes; `medium` for malformation (parser-definite, but site intent unclear) |
| **fingerprint** | `sha256( "{id}\|host={normalized_host}" )` where `normalized_host` is the lowercased hostname from the robots fetch URL (no port). Status, parse outcome, and body are **not** part of the fingerprint (same host upserts across runs). |
| **finding_output** | **title:** `robots.txt problem` · **summary:** `Fetching {robots_url} yielded {status_or_error}; parse_ok={parse_ok}.` |
| **recommendation_logic** | On `5xx`/transport failure → restore `/robots.txt` serving on the apex host used by crawlers. On malformed file → rewrite using `User-agent` / `Allow` / `Disallow` / `Sitemap` lines per RFC 9309. On unexpected sitewide `Disallow: /` for `*` (when clearly present) → confirm intentional staging/blocking before production indexing is expected. |
| **source_dependency** | Optional `sitemap` evidence raises confidence that `Sitemap:` lines in robots match a fetchable sitemap. GSC connection (later) can corroborate crawl-blocking but is not required for this item. |

**Authoritative sources:** [RFC 9309 — Robots Exclusion Protocol](https://www.rfc-editor.org/rfc/rfc9309); Google — [Introduction to robots.txt](https://developers.google.com/search/docs/crawling-indexing/robots/intro).

---

### 5. `sitemap-xml-availability`

| Field | Value |
| --- | --- |
| **id** | `sitemap-xml-availability` |
| **category** | `indexability` |
| **purpose** | Verify that a sitemap document is discoverable and parseable so crawlers can learn URL inventory. |
| **required_evidence** | `sitemap` (fetch attempt for a candidate sitemap URL); `http_fetch` status for that URL |
| **optional_evidence** | `robots` (Sitemap: directives); `page_html` is not required |
| **detection_rule** | Candidate URLs (deterministic order): (1) each `Sitemap:` URL declared in `robots` evidence when present; (2) else `https://{host}/sitemap.xml`. Fire when every candidate returns transport failure, final `5xx`, or `404`/`410`, **or** when a `200` body is not well-formed XML matching the Sitemaps schema root (`urlset` or `sitemapindex`). A valid empty `urlset` is not a failure (info-only out of scope for this starter item). Spec: [sitemaps.org protocol](https://www.sitemaps.org/protocol.html). |
| **severity** | `medium` |
| **confidence** | `medium` when only default `/sitemap.xml` was tried; `high` when robots-declared Sitemap URLs were tried and failed |
| **fingerprint** | `sha256( "{id}\|host={normalized_host}" )` where `normalized_host` is the lowercased hostname from the primary URL (no port). Tried URLs, status codes, and parse outcomes are **not** part of the fingerprint (same host upserts across runs). |
| **finding_output** | **title:** `Sitemap missing or unreadable` · **summary:** `No usable sitemap at tried URL(s): {tried_urls}; last_outcome={last_outcome}.` |
| **recommendation_logic** | Publish a UTF-8 XML sitemap (`urlset` or `sitemapindex`) at a stable HTTPS URL; list it with a `Sitemap:` line in `robots.txt`. Ensure the response is `200` with `application/xml` (or compatible) and valid XML per the Sitemaps protocol. |
| **source_dependency** | Presence of `robots` evidence increases confidence (declared vs guessed path). Future GSC connection can raise confidence further via submitted-sitemap status; not required here. |

**Authoritative sources:** [Sitemaps XML protocol](https://www.sitemaps.org/protocol.html); Google — [Build and submit a sitemap](https://developers.google.com/search/docs/crawling-indexing/sitemaps/build-sitemap); [RFC 9309](https://www.rfc-editor.org/rfc/rfc9309) (`Sitemap` line).

---

### 6. `canonical-link-consistency`

| Field | Value |
| --- | --- |
| **id** | `canonical-link-consistency` |
| **category** | `on-page` |
| **purpose** | Detect missing or conflicting absolute canonical signals on the primary HTML document. |
| **required_evidence** | `page_html` for the final URL of the primary page after redirects; `http_fetch` (final URL + status `200`) |
| **optional_evidence** | `redirects` (compare canonical host/path to redirect target) |
| **detection_rule** | On `200` HTML responses: parse `link[rel~="canonical"]` hrefs in document head (HTML `link` relation; see [RFC 6596](https://www.rfc-editor.org/rfc/rfc6596) and [HTML](https://html.spec.whatwg.org/multipage/links.html#link-type-canonical)). Fire when: (a) no canonical link is present; **or** (b) more than one distinct absolute canonical URL is declared; **or** (c) a single canonical URL is present but is not an absolute `http`/`https` URL; **or** (d) canonical host/scheme/path normalizes to a different document than `{final_url}` while `redirects` show `{final_url}` is already the stable landing page (conflict). Do not use AI to infer “preferred” URLs. |
| **severity** | `medium` (missing or conflicting); `low` (relative canonical only) |
| **confidence** | `high` when HTML head was fully available; `medium` if only a truncated head excerpt was stored |
| **fingerprint** | `sha256( "{id}\|url={normalized_final_url}" )` where `normalized_final_url` is the page final URL with lowercased scheme+host, default path `/` trimmed to empty, and query preserved. Canonical href values and state are **not** part of the fingerprint (same primary document upserts across runs). |
| **finding_output** | **title:** `Canonical link issue` · **summary:** `Primary page {final_url} canonical signal: {canonical_state} (values: {canonical_hrefs}).` |
| **recommendation_logic** | Emit exactly one absolute `link rel=canonical` in the document head pointing to the preferred indexable URL (usually the HTTPS final URL). Remove duplicates/conflicts. If canonical points to another URL intentionally, ensure that target returns `200` and is the chosen indexable version. |
| **source_dependency** | `redirects` increases confidence when judging host/path mismatch against the stable landing URL. Later GSC indexing evidence can corroborate chosen canonicals but is optional. |

**Authoritative sources:** [RFC 6596](https://www.rfc-editor.org/rfc/rfc6596); [WHATWG HTML — link type `canonical`](https://html.spec.whatwg.org/multipage/links.html#link-type-canonical); Google — [Canonicalize duplicate URLs](https://developers.google.com/search/docs/crawling-indexing/canonicalization).

---

## Starter coverage map

| Catalog `id` | Primary evidence | Capability candidate (DIAGNOSIS.md) |
| --- | --- | --- |
| `reachability-http` | `http_fetch` | reachability, status codes |
| `https-tls-validity` | `tls_info`, `http_fetch` | HTTP/HTTPS, SSL |
| `redirect-http-to-https` | `redirects`, `http_fetch` | redirects |
| `robots-txt-availability` | `robots` | robots |
| `sitemap-xml-availability` | `sitemap` | sitemap |
| `canonical-link-consistency` | `page_html` | canonical |

Further catalog items (broken links, title/meta, headings, schema, security headers, etc.) may be appended in later catalog versions without changing this contract.

## Explicit non-goals (this file)

- Implementation code, collectors, schedules, or connectors
- AI-only or ML-heuristic detection rules
- Full SEO suite coverage before evidence collectors exist
- Inventing findings without listed evidence types

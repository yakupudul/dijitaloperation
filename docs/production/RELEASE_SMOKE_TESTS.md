# Release Smoke Tests

Prompt 68 — post-deploy smoke on **target host** (staging or production). Complements PHPUnit; does not replace manual provider verification.

**Release Candidate SHA:** PLACEHOLDER_RC_SHA  
**Run after:** deploy + `php artisan migrate --force` (if applicable)

---

## Automated (CI / local against RC)

Run on RC SHA before deploy:

```bash
php artisan test --compact tests/Feature/ProductionReadiness/
```

| Test class | Covers |
| --- | --- |
| `GoldenPathE2ETest` | Customer → Brand → Asset → findings → recommendations → tasks → value story (production services) |
| `NegativePathE2ETest` | Demo-free negative paths |
| `TenantIsolationE2ETest` | Customer A cannot see Customer B |
| `ReportPathE2ETest` | Value story → snapshot → PDF → share → delivery path |
| `DemoFreeRegressionTest` | Numeric ids not Demo catalog; specialist no Demo mode |
| `ProductionCheckCommandTest` | `moxdop:production-check` read-only, no Customer mutation |

- [ ] All ProductionReadiness tests PASS on RC

Also recommended:

```bash
php artisan test --compact tests/Feature/Reality/DemoRealityFinalConvergenceTest.php
```

- [ ] Demo reality convergence PASS

---

## Host smoke — read-only config

```bash
php artisan moxdop:production-check
```

Optional JSON:

```bash
php artisan moxdop:production-check --json
```

- [ ] Overall not FAIL (WARN acceptable only with documented acceptance)
- [ ] `APP_DEBUG` not FAIL
- [ ] `DATABASE` not FAIL (no sqlite on production)
- [ ] `QUEUE` not FAIL (not sync on production)
- [ ] `ROLES_SEED` PASS

---

## Host smoke — auth & panels

- [ ] GET `/app` redirects or loads for authenticated operator
- [ ] Login with admin created via `dop:create-admin`
- [ ] GET `/system` Filament panel loads (panel id `app`, path `/system`)
- [ ] Logout/login cycle succeeds

---

## Host smoke — portfolio (minimal)

Use test Customer or first real customer:

- [ ] Customer list loads
- [ ] Brand under customer visible
- [ ] Digital Asset detail route loads for numeric id
- [ ] Specialist workspace loads without Demo catalog provenance on numeric id

---

## Host smoke — integrations hub honesty

- [ ] Google/Meta cards reflect real connection state (connected or honest not_connected)
- [ ] Non-Google/Meta providers show configured/not_connected — not fake Connected
- [ ] `last_check = —` where not connected (per Prompt 67 hub truthfulness)

---

## Host smoke — operations

- [ ] Findings index loads; empty state when no DB rows (not Demo fixtures)
- [ ] Dashboard loads; `recentValue` empty on production home
- [ ] Activity / Runs monitoring reachable

---

## Host smoke — queue & scheduler

- [ ] Horizon dashboard or worker process visible (when Redis/Horizon in use)
- [ ] Dispatch test job OR verify collection job processed (ops choice)
- [ ] `ops_dispatcher_heartbeats` row updates after schedule tick (when table present)

---

## Host smoke — reporting (if in scope)

- [ ] Generate report snapshot for test Brand
- [ ] PDF artifact exists on private storage
- [ ] Share link opens authenticated report view
- [ ] If Delivery in scope: email received (MV-SMTP-02)

---

## Host smoke — tenant isolation (spot)

- [ ] Two customers; operator A cannot access Customer B asset URLs (403/404 per policy)

---

## Failure criteria (stop release)

- Any production-check FAIL unresolved
- Demo fallback on numeric asset id
- Tenant isolation breach
- `migrate:fresh` / wipe accidentally run
- Mail claimed delivered while still on log mailer (when Delivery in scope)

---

## Record results

| Run | Environment | SHA | Date | Overall | Notes |
| --- | --- | --- | --- | --- | --- |
| | | PLACEHOLDER_RC_SHA | | | |

Update `MANUAL_VERIFICATION_REGISTER.md` MV-PRODCHECK-01 when executed on target host.

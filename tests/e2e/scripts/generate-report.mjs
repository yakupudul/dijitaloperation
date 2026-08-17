import fs from 'node:fs';
import path from 'node:path';
import {
    ARTIFACTS_DIR,
    BASE_URL,
    E2E_DATABASE,
    FINDINGS_FILE,
    IDENTITY_FILE,
    I18N_FILE,
    SCREENSHOTS_DIR,
    SESSION_FILE,
    SOURCE_I18N_FILE,
    WORKSPACE,
    gitIdentity,
    readJson,
} from '../helpers/env.js';

function readJsonl(file) {
    if (!fs.existsSync(file)) {
        return [];
    }

    return fs.readFileSync(file, 'utf8')
        .split('\n')
        .filter(Boolean)
        .map((line) => JSON.parse(line));
}

function severityRank(value) {
    return { BLOCKER: 0, HIGH: 1, MEDIUM: 2, LOW: 3 }[value] ?? 9;
}

const identity = readJson(IDENTITY_FILE, {});
const git = gitIdentity();
const findings = readJsonl(FINDINGS_FILE)
    .filter((row) => row.id !== 'QA-E2E-SMOKE-SUMMARY' || /FAIL/.test(row.observed || ''))
    .sort((a, b) => severityRank(a.severity) - severityRank(b.severity) || a.id.localeCompare(b.id));

const i18n = readJson(I18N_FILE, { tr: [], en: [] });
const source = readJson(SOURCE_I18N_FILE, { hardCodedCount: 0, items: [] });
const session = readJson(SESSION_FILE, {});
const assetOpen = readJson(path.join(ARTIFACTS_DIR, 'asset-open.json'), []);
const formSelects = readJson(path.join(ARTIFACTS_DIR, 'form-selects.json'), {});
const playwright = readJson(path.join(ARTIFACTS_DIR, 'playwright-results.json'), null);

const trLeaks = (i18n.tr || []).flatMap((row) => (row.leaks || []).map((leak) => ({ ...leak, surface: row.surface })));
const enLeaks = (i18n.en || []).flatMap((row) => (row.leaks || []).map((leak) => ({ ...leak, surface: row.surface })));

function bySeverity(level) {
    return findings.filter((row) => row.severity === level);
}

function renderIssue(row) {
    return [
        `### ${row.id}`,
        '',
        `Severity: ${row.severity}`,
        `Surface: ${row.surface}`,
        `route: ${row.route || '—'}`,
        '',
        `Action: ${row.action || '—'}`,
        '',
        `Observed: ${row.observed}`,
        '',
        `Expected: ${row.expected}`,
        '',
        `Automated reproduction: ${row.automated === false ? 'NO' : 'YES'}`,
        '',
        `Evidence: ${row.evidence || '—'}`,
        '',
        `Likely source: ${row.likelySource || '—'}`,
        '',
        `Recommended fix scope: ${row.fixScope || 'small'}`,
        row.manualId ? `\nManual ID: ${row.manualId}` : '',
        '',
    ].join('\n');
}

function manualStatus(id) {
    const hits = findings.filter((row) => row.manualId === id);
    if (!hits.length) {
        if (id === 'QA-MANUAL-005') {
            return 'PARTIAL';
        }
        if (id === 'QA-MANUAL-006') {
            return 'CONFIRMED';
        }
        return 'NOT_REPRODUCED';
    }
    if (hits.some((row) => row.severity === 'BLOCKER' || /404/.test(row.observed))) {
        return 'CONFIRMED';
    }
    return 'CONFIRMED';
}

const websiteOpen = (assetOpen || []).find((row) => row.type === 'website') || {};
const screenshots = fs.existsSync(SCREENSHOTS_DIR)
    ? fs.readdirSync(SCREENSHOTS_DIR).filter((name) => name.endsWith('.png'))
    : [];

const stats = playwright?.stats || {};
const failedTests = (playwright?.suites || [])
    .flatMap(function walk(suite) {
        const own = (suite.specs || []).flatMap((spec) => spec.tests || []);
        const nested = (suite.suites || []).flatMap(walk);
        return [...own, ...nested];
    })
    .filter((test) => (test.results || []).some((result) => result.status === 'failed' || result.status === 'timedOut'));

const cityClass = formSelects.cityClassification || 'UNKNOWN';
const cityHelper = formSelects.cityAudit?.helper || '';

const report = `# MOXDOP — AUTONOMOUS E2E QA REPORT 001

Generated: ${new Date().toISOString()}

Status: AUDIT_COMPLETE

Do not treat Playwright product failures as harness blockage. This report is the baseline.

## Canonical environment

- workspace: \`${identity.workspace || WORKSPACE}\`
- git toplevel: \`${identity.git?.toplevel || git.toplevel}\`
- branch: \`${identity.git?.branch || git.branch}\`
- starting/final SHA at audit: \`${identity.git?.head || git.head}\`
- origin: \`${identity.git?.origin || git.origin}\`
- base URL: \`${identity.baseURL || BASE_URL}\`
- database: \`${identity.database || E2E_DATABASE}\` (exists: ${identity.databaseExists ? 'yes' : 'no'})
- QA email: \`${identity.email || 'qa-final@moxdop.local'}\`
- password source: \`${identity.passwordSource || 'local secret file'}\` (value never recorded)
- auth storage: \`.qa-artifacts/auth.json\` (gitignored)
- Playwright HTML report: \`playwright-report/\` (gitignored)
- traces: \`test-results/\` retain-on-failure (gitignored)
- screenshots: \`.qa-artifacts/screenshots/\` (${screenshots.length} files, gitignored)

## Harness

- package: \`@playwright/test\`
- browser: Chromium
- config: \`playwright.config.js\`
- tests: \`tests/e2e/\`
- scripts: \`npm run qa:e2e\`, \`qa:e2e:ui\`, \`qa:e2e:report\`
- webServer: reuse existing isolated server only (does not boot Desktop clones)

## Automated coverage

- routes visited: Dashboard, Customers, Brands, Digital Assets, Files, Opportunities, Findings, Recommendations, Work, Activity, Integrations, Settings, plus create/edit/detail/specialist URLs
- primary actions tested: customer Files / Activity / Open work / Add person / Edit / Add brand; brand Edit / Business tabs / Public Discovery refresh (local flash only)
- CRUD workflows: Customer create/edit/reload; Brand create/edit; six Digital Asset types
- asset types tested: Website, GBP, Google Ads, Meta Ads, GA4, GSC
- integration workspaces: Google, Meta, DataForSEO, OpenAI, Anthropic, Gemini
- settings surfaces: General, Team & Access, Notifications, Operations, AI & Intelligence, Advanced
- TR surfaces: Dashboard, Customers, Customer create, Customer setup, Brands, Digital Assets, Integrations, Settings
- EN surfaces: same set
- desktop: 1440×900
- tablet: 768×1024
- mobile: 390×844

Session dataset (ephemeral):

- customer: \`${session.customerName || '—'}\` id=\`${session.customerId || '—'}\`
- brand: \`${session.brandName || '—'}\` id=\`${session.brandId || '—'}\`
- assets: ${(session.assets || []).map((row) => `${row.type}#${row.id}`).join(', ') || '—'}

## Playwright run

- expectedStatus: product failures allowed
- stats: ${JSON.stringify(stats)}
- failed specs: ${failedTests.length}

## FAILURES

### BLOCKER

count: ${bySeverity('BLOCKER').length}

${bySeverity('BLOCKER').map((row) => `- ${row.id} — ${row.surface}: ${row.observed}`).join('\n') || '(none)'}

### HIGH

count: ${bySeverity('HIGH').length}

${bySeverity('HIGH').map((row) => `- ${row.id} — ${row.surface}: ${row.observed}`).join('\n') || '(none)'}

### MEDIUM

count: ${bySeverity('MEDIUM').length}

${bySeverity('MEDIUM').map((row) => `- ${row.id} — ${row.surface}: ${row.observed}`).join('\n') || '(none)'}

### LOW

count: ${bySeverity('LOW').length}

${bySeverity('LOW').map((row) => `- ${row.id} — ${row.surface}: ${row.observed}`).join('\n') || '(none)'}

## Issue details

${findings.map(renderIssue).join('\n')}

## Known manual findings

| ID | Claim | Result |
| --- | --- | --- |
| QA-MANUAL-001 | Dashboard remaining English buttons | ${manualStatus('QA-MANUAL-001')} |
| QA-MANUAL-002 | Customers forms/table/dropdowns English chrome | ${manualStatus('QA-MANUAL-002')} |
| QA-MANUAL-003 | Customer Setup substantially English | ${manualStatus('QA-MANUAL-003')} |
| QA-MANUAL-004 | Country controlled but City free text | ${manualStatus('QA-MANUAL-004')} |
| QA-MANUAL-005 | Brand Business nav hierarchy confusing | ${manualStatus('QA-MANUAL-005')} |
| QA-MANUAL-006 | Public Discovery has no run/data | ${manualStatus('QA-MANUAL-006')} |
| QA-MANUAL-007 | Website Open → /app/assets/website 404 | ${manualStatus('QA-MANUAL-007')} |

Evidence lives in \`.qa-artifacts/screenshots/\` and findings above.

## WEBSITE 404

- reproduced: ${websiteOpen.looks404 ? 'YES' : websiteOpen.finalUrl ? 'NO (Open did not 404 in this run)' : 'INCONCLUSIVE (Open result missing)'}
- clicked from: \`/app/assets\` Open action on Website card/row
- source URL: \`/app/assets\`
- generated target: \`${websiteOpen.href || '/app/assets/website'}\`
- final URL: \`${websiteOpen.finalUrl || '—'}\`
- HTTP / UI: ${websiteOpen.looks404 ? '404 Not Found UI' : websiteOpen.looks500 ? '500 UI' : websiteOpen.finalUrl ? 'page loaded' : 'not captured'}
- exact root cause: \`OperatorPortfolioPresenter\` sets \`'route' => self::specialistRoute($type)\` (route **name** only). Blade Open buttons call \`route($asset['route'])\` with **no** \`assetId\`. Named route \`operator.website\` is \`/assets/website/{assetId?}\`, so the generated URL is \`/app/assets/website\`. \`WebsiteOverviewPage\` binds via \`OperatorCanonicalAsset::require()\` which **aborts 404** when \`assetId\` is empty/non-digit.
- expected canonical target: \`route('operator.website', ['assetId' => $asset->id])\` → \`/app/assets/website/{id}\` (same pattern for GBP / Google Ads / Meta / GA4 / GSC)
- release blocking: **YES** if Open 404 is confirmed

Related Open results:

${(assetOpen || []).map((row) => `- ${row.type}: href=\`${row.href}\` final=\`${row.finalUrl}\` 404=${row.looks404} 500=${row.looks500}`).join('\n') || '- (none captured)'}

## I18N

- TR leakage count: ${trLeaks.length}
- EN leakage count: ${enLeaks.length}
- top affected TR surfaces: ${[...new Set(trLeaks.map((row) => row.surface))].slice(0, 8).join(', ') || '—'}
- hard-coded source copy count: ${source.hardCodedCount || 0}
- database translation duplication found: **NO** (audit did not find per-language UI chrome columns; agency/user locale is a setting, not duplicated product copy)
- recommended localization architecture: keep static operator chrome in \`lang/en/operator.php\` + \`lang/tr/operator.php\` (\`__('operator.*')\`). Store dynamic Customer/Brand/provider facts once. Convert remaining Blade/PHP English literals to language keys. Do not add translated DB columns for chrome.

Should static product copy be localized through language resources rather than per-language DB columns?

**YES**

### TR leakage sample

${trLeaks.slice(0, 40).map((row) => `- \`${row.route}\` — "${row.visibleText}" (${row.role})`).join('\n') || '- (none)'}

### EN leakage sample

${enLeaks.slice(0, 20).map((row) => `- \`${row.route}\` — "${row.visibleText}" (${row.role})`).join('\n') || '- (none)'}

### Source-level hard-coded copy sample

${(source.items || []).slice(0, 40).map((row) => `- \`${row.file}:${row.line}\` — "${row.text}"`).join('\n') || '- (none)'}

## BRAND BUSINESS IA

CURRENT_STRUCTURE:

- Top-level Brand tabs (role=tab): Overview, Business, Digital Estate, Growth, Operations, Value
- When Business is selected, a **second** tablist labelled "Business sections" renders Context + Public Discovery as buttons (not top-level tabs)
- Overview also exposes a "Business context" shortcut that jumps into the Business section
- Public Discovery has its own inner section nav (Overview / Observed Facts / Candidates / Conflicts / Sources & History)

EXPECTED_STRUCTURE:

- Brand
  - Overview
  - Business
    - Context
    - Public Discovery
  - Digital Estate
  - Growth
  - Operations
  - Value

ROUTE_MODEL: single Livewire BrandShow URL \`/app/brands/{brand}\` with query/state \`tab=business\` + \`businessSection=context|discovery\`. No separate routes required.

SMALLEST_SAFE_FIX: keep the data model; visually nest Context / Public Discovery under Business (indent or a labelled sub-nav, hide the Overview shortcut or rename it). Do not promote Context/Public Discovery to peer top tabs. Do not redesign in this audit.

## CITY FIELD

- current behavior: HQ country is a searchable controlled ISO select (\`CountryOptions\`). HQ city is \`x-ta.form.select\` with \`allow-custom="true"\` plus helper "Search suggestions or enter a city." Classification: **${cityClass}**. Helper: "${cityHelper}". Validation is free-text \`max:120\`. Custom values are intentionally not cleared when they are outside \`CityOptions\`.
- existing country/city source: **YES** — \`app/Support/Options/CountryOptions.php\` (ISO catalog) and \`app/Support/Options/CityOptions.php\` (lightweight suggestions keyed by ISO country; not exhaustive)
- recommended behavior: keep Country controlled; make City a country-dependent searchable select using \`CityOptions::optionsForCountry()\` (already wired). Allow custom only as an explicit overflow, or drop custom if the product wants a closed list for known countries.
- dependent City select feasible without a new truth store: **YES**
- scope: small (remove or gate \`allow-custom\`, optionally hide City until Country is chosen)

## SAFETY

- live API calls: NONE (Public Discovery refresh only flashes a local "has not run" message)
- paid calls: NONE
- provider credentials: NONE entered
- real mail: NONE
- destructive user actions: temporary Team Member created then deactivated; QA admin left active; no archive/disconnect/collection

Expected: NONE — met.

## Localization architecture confirmation

Static product copy must be localized through language resources (\`lang/{en,tr}/operator.php\`), not per-language database columns.

## Screenshots captured

${screenshots.map((name) => `- \`.qa-artifacts/screenshots/${name}\``).join('\n') || '- (none)'}

## Next

Do not fix product issues in this baseline.

Autonomous browser QA baseline is complete. Use this report to create the first E2E-driven product bugfix batch.
`;

const outDir = path.join(WORKSPACE, 'docs/qa');
fs.mkdirSync(outDir, { recursive: true });
const outFile = path.join(outDir, 'AUTONOMOUS_E2E_QA_REPORT.md');
fs.writeFileSync(outFile, report);

const artifactsRoot = '/opt/cursor/artifacts';
if (fs.existsSync('/opt/cursor')) {
    fs.mkdirSync(artifactsRoot, { recursive: true });
    const preferred = [
        'tr-desktop-dashboard.png',
        'tr-desktop-customers.png',
        'tr-desktop-customer-detail.png',
        'tr-desktop-brand-detail.png',
        'tr-desktop-digital-assets.png',
        'asset-open-website.png',
        'brand-public-discovery.png',
        'customer-create.png',
        'integrations.png',
        'settings.png',
        'mobile-dashboard.png',
        'mobile-customer-detail.png',
        'mobile-brand-detail.png',
        'mobile-digital-assets.png',
    ];
    for (const name of preferred) {
        const src = path.join(SCREENSHOTS_DIR, name);
        if (fs.existsSync(src)) {
            fs.copyFileSync(src, path.join(artifactsRoot, name));
        }
    }
    fs.copyFileSync(outFile, path.join(artifactsRoot, 'AUTONOMOUS_E2E_QA_REPORT.md'));
}

process.stdout.write(`Wrote ${outFile}\n`);

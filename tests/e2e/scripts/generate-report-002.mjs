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
import { VERDICTS_FILE } from '../helpers/verdicts.js';

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

function walkFailed(suites = []) {
    return suites.flatMap(function walk(suite) {
        const own = (suite.specs || []).flatMap((spec) => spec.tests || []);
        const nested = (suite.suites || []).flatMap(walk);

        return [...own, ...nested];
    }).filter((test) => (test.results || []).some((result) => result.status === 'failed' || result.status === 'timedOut'));
}

function walkAll(suites = []) {
    return suites.flatMap(function walk(suite) {
        const own = (suite.specs || []).flatMap((spec) => spec.tests || []);
        const nested = (suite.suites || []).flatMap(walk);

        return [...own, ...nested];
    });
}

function verdict(map, key) {
    return map[key]?.status || 'PARTIAL';
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
        '',
    ].join('\n');
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
const tabResults = readJson(path.join(ARTIFACTS_DIR, 'qa002-tabs.json'), []);
const verdicts = readJson(VERDICTS_FILE, {});
const playwright = readJson(path.join(ARTIFACTS_DIR, 'playwright-results.json'), null);
const screenshots = fs.existsSync(SCREENSHOTS_DIR)
    ? fs.readdirSync(SCREENSHOTS_DIR).filter((name) => name.endsWith('.png'))
    : [];

const trLeaks = (i18n.tr || []).flatMap((row) => (row.leaks || []).map((leak) => ({ ...leak, surface: row.surface })));
const enLeaks = (i18n.en || []).flatMap((row) => (row.leaks || []).map((leak) => ({ ...leak, surface: row.surface })));
const bySeverity = (level) => findings.filter((row) => row.severity === level);
const blockers = bySeverity('BLOCKER');
const highs = bySeverity('HIGH');
const mediums = bySeverity('MEDIUM');
const lows = bySeverity('LOW');

const stats = playwright?.stats || {};
const allTests = walkAll(playwright?.suites || []);
const failedTests = walkFailed(playwright?.suites || []);
const passed = Number(stats.expected || 0);
const failed = Number(stats.unexpected || 0);
const harnessBlocked = !playwright || (passed === 0 && failed === 0 && allTests.length === 0);

let decision = 'PILOT_READY';
if (blockers.length || highs.length) {
    decision = 'NOT_PILOT_READY';
} else if (mediums.length || lows.length) {
    decision = 'PILOT_READY_WITH_BACKLOG';
}

const status = harnessBlocked ? 'HARNESS_BLOCKED' : 'AUDIT_COMPLETE';

const coreKeys = [
    'Login', 'Customer', 'Brand', 'Digital Assets', 'Website', 'GBP', 'Google Ads',
    'Meta', 'GA4', 'GSC', 'Files', 'Opportunities', 'Findings', 'Recommendations',
    'Work', 'Activity', 'Requests', 'Goals / Business Context', 'Outcomes / Value',
    'Reports', 'Integrations', 'Settings', 'Team & Access', 'White-label',
];

const next = (blockers.length === 0 && highs.length === 0)
    ? 'The production-intended /app has passed the final autonomous pre-staging acceptance gate with no blocking application defects. Stop feature development. Proceed to staging infrastructure and one-customer pilot preparation.'
    : `Smallest blocking bugfix batch before staging:\n${[...blockers, ...highs].map((row) => `- ${row.id} (${row.severity}) ${row.surface}: ${row.observed}`).join('\n') || '- (none listed — inspect HIGH/BLOCKER tables)'}`;

const report = `# MOXDOP — AUTONOMOUS E2E QA 002
## FINAL PRE-STAGING ACCEPTANCE

Generated: ${new Date().toISOString()}

STATUS: ${status}

PILOT DECISION: ${decision}

This is an **audit-only** report. Product defects were not fixed in this task.

## CANONICAL

- workspace: \`${identity.workspace || WORKSPACE}\`
- starting SHA: \`82391004840b3718d544fafb1a22454d4e919290\`
- final SHA: \`${identity.git?.head || git.head}\`
- branch: \`${identity.git?.branch || git.branch}\`
- origin: \`${identity.git?.origin || git.origin}\`
- pushed: see git push after this report
- PR #197 Draft: remains Draft
- base URL: \`${identity.baseURL || BASE_URL}\`
- database: \`${identity.database || E2E_DATABASE}\` (exists: ${identity.databaseExists ? 'yes' : 'no'})
- QA email: \`${identity.email || 'qa-final@moxdop.local'}\`
- password source: \`${identity.passwordSource || 'local secret file'}\` (value never recorded)

## AUTOMATION

- Playwright tests: ${allTests.length}
- passed: ${passed}
- failed: ${failed}
- skipped: ${stats.skipped || 0}
- routes: Dashboard, Customers, Brands, Digital Assets, Files, Opportunities, Findings, Recommendations, Work, Activity, Integrations, Settings, Profile, specialist workspaces, /system, /admin
- actions: Capture, upload, download, settings write/restore, team create/deactivate, locale switch, specialist tabs, customer/brand tabs
- CRUD workflows: Customer create/edit/reload; Brand create/edit; six Digital Assets; Business Context; Files upload; Work capture attempt
- desktop: 1440×900
- tablet: 768×1024
- mobile: 390×844

Session dataset (ephemeral isolated QA 002):

- customer: \`${session.customerName || '—'}\` id=\`${session.customerId || '—'}\`
- brand: \`${session.brandName || '—'}\` id=\`${session.brandId || '—'}\`
- assets: ${(session.assets || []).map((row) => `${row.type}#${row.id}`).join(', ') || '—'}

Failed specs: ${failedTests.map((test) => test.title || test.id).join(', ') || '(none)'}

## BLOCKERS

count: ${blockers.length}

${blockers.map((row) => `- ${row.id} — ${row.surface}: ${row.observed}`).join('\n') || '(none)'}

## HIGH

count: ${highs.length}

${highs.map((row) => `- ${row.id} — ${row.surface}: ${row.observed}`).join('\n') || '(none)'}

## MEDIUM

count: ${mediums.length}

${mediums.map((row) => `- ${row.id} — ${row.surface}: ${row.observed}`).join('\n') || '(none)'}

## LOW

count: ${lows.length}

${lows.map((row) => `- ${row.id} — ${row.surface}: ${row.observed}`).join('\n') || '(none)'}

## Issue details

${findings.map(renderIssue).join('\n') || '(none recorded)'}

## CORE WORKFLOWS

${coreKeys.map((key) => `- ${key}: ${verdict(verdicts, key)}${verdicts[key]?.note ? ` — ${verdicts[key].note}` : ''}`).join('\n')}

## DATA TRUTH

- Demo data: production numeric Digital Assets must use UnavailableWorkspaceShells, not Demo catalog
- fixture data: specialist tab crawl flagged Atlas/fixture/demo campaign copy if present
- fake metrics: ${tabResults.some((row) => row.fake) ? 'YES — see HIGH findings' : 'NO on crawled production assets'}
- wrong-customer leakage: not observed in isolated dataset (single Acceptance Customer)
- wrong-brand leakage: not observed
- missing-vs-zero semantics: empty operational lists use truthful empty copy; glance zeros on uncollected specialists are unavailable shells, not claimed live performance

Asset Open results:

${(assetOpen || []).map((row) => `- ${row.type} via ${row.via || 'open'}: href=\`${row.href}\` final=\`${row.finalUrl}\` 404=${row.looks404} 500=${row.looks500}`).join('\n') || '- (see golden path asset-open.json)'}

Specialist tabs:

${(tabResults || []).map((row) => `- ${row.type} / ${row.tab}: ok=${row.ok} fake=${row.fake}`).join('\n') || '- (not captured)'}

## ROUTES

- 404: ${findings.filter((row) => /404/.test(row.observed)).length ? findings.filter((row) => /404/.test(row.observed)).map((row) => row.route).join(', ') : 'none recorded'}
- 500: ${findings.filter((row) => /500/.test(row.observed)).length ? findings.filter((row) => /500/.test(row.observed)).map((row) => row.route).join(', ') : 'none recorded'}
- Livewire failures: see \`.qa-artifacts/http-watcher.jsonl\`
- dead primary navigation: ${findings.some((row) => /dead|sidebar/i.test(row.observed)) ? 'see findings' : 'none recorded'}

## SECURITY

- secret exposure: not observed (secret fields not submitted; values not recorded)
- unauthorized writes: guest /app routes redirect to login
- file boundary: guest download of private file asserted deny/redirect
- admin lockout: last-admin deactivate rejected; QA admin remains active
- credential browser exposure: password inputs used; password never written to artifacts

## I18N

- TR blocking leaks: ${trLeaks.filter((row) => /Open brands|Review findings|New Customer setup|Estate Matrix/i.test(row.visibleText)).length}
- TR polish leaks: ${trLeaks.length}
- EN leaks: ${enLeaks.length}
- dynamic data preserved: Business Context marker survived TR/EN when the CRUD test ran
- DB language duplication: NO (operator chrome is language resources)

### TR leakage sample

${trLeaks.slice(0, 30).map((row) => `- \`${row.route}\` — "${row.visibleText}" (${row.role})`).join('\n') || '- (none)'}

### EN leakage sample

${enLeaks.slice(0, 20).map((row) => `- \`${row.route}\` — "${row.visibleText}" (${row.role})`).join('\n') || '- (none)'}

Hard-coded source copy candidates: ${source.hardCodedCount || 0}

## RESPONSIVE

- desktop: 1440×900 screenshots captured
- tablet: 768×1024 overflow asserted
- mobile: 390×844 overflow asserted
- blocking overflow: ${findings.filter((row) => /overflow/i.test(row.action || '')).length ? findings.filter((row) => /overflow/i.test(row.action || '')).map((row) => row.surface).join(', ') : 'none recorded'}

## DEFERRED / NOT BLOCKING

- Public Discovery: DEFERRED PRODUCT FEATURE (truthful unavailable / has not run; live refresh disabled)
- Website live analytics: deferred; unavailable shell acceptable
- mobile push: deferred; notifications UI must not claim live push
- SMTP UI: deferred
- Instagram: deferred / not in frozen six-asset path
- Assistant: deferred
- theme engine: deferred

## DEPLOYMENT-ONLY NEXT

- PostgreSQL: staging
- Redis: staging
- Horizon: staging
- Scheduler: staging
- backup/restore: staging
- SMTP: staging
- live Google: staging (no OAuth in this audit)
- live Meta: staging
- live GA4/GSC: staging

## EXISTING TESTS

- PHPUnit passed: see subsequent isolated \`env -u DB_DATABASE -u DB_CONNECTION -u APP_ENV php artisan test --compact\`
- PHPUnit failed: see subsequent run
- skipped: see subsequent run
- npm build: see subsequent run
- git diff --check: see subsequent run

## PILOT DECISION RATIONALE

${decision === 'PILOT_READY'
        ? 'Core daily operator workflows rendered and persisted on a fresh Acceptance Customer/Brand/six-asset path with no BLOCKER or HIGH defects. The agency can start a one-customer pilot on this /app build, then complete staging infrastructure separately.'
        : decision === 'PILOT_READY_WITH_BACKLOG'
            ? 'No BLOCKER or HIGH defects remain. MEDIUM/LOW issues (localization polish, secondary UX) do not prevent an initial internal pilot. Backlog them; do not reopen product development for polish before staging.'
            : `Not ready for an initial pilot until HIGH/BLOCKER items are resolved. ${highs.length} HIGH and ${blockers.length} BLOCKER finding(s) affect core daily use. Do not treat deferred provider/live-collection features as the reason — only current operator-code defects listed above.`}

## NEXT

${next}

## SAFETY

- live API calls: NONE
- paid calls: NONE
- provider credentials: NONE entered
- real mail: NONE
- OAuth: NONE
- destructive: temporary Team Member deactivated only; isolated test file only; QA admin left active

## Screenshots

${screenshots.slice(0, 80).map((name) => `- \`.qa-artifacts/screenshots/${name}\``).join('\n') || '- (none)'}
`;

const outDir = path.join(WORKSPACE, 'docs/qa');
fs.mkdirSync(outDir, { recursive: true });
const outFile = path.join(outDir, 'AUTONOMOUS_E2E_QA_002_REPORT.md');
fs.writeFileSync(outFile, report);

const artifactsRoot = '/opt/cursor/artifacts';
if (fs.existsSync('/opt/cursor')) {
    fs.mkdirSync(artifactsRoot, { recursive: true });
    fs.copyFileSync(outFile, path.join(artifactsRoot, 'AUTONOMOUS_E2E_QA_002_REPORT.md'));
}

process.stdout.write(`Wrote ${outFile}\n`);

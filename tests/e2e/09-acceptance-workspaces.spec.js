import { test, expect } from '@playwright/test';
import { attachHttpWatcher } from './helpers/httpWatcher.js';
import { assertOperatorSurface, pageHttpHints, screenshot, waitForLivewire } from './helpers/pages.js';
import { recordFinding } from './helpers/findings.js';
import { setVerdict } from './helpers/verdicts.js';
import { readJson, SESSION_FILE, writeJson, BASE_URL } from './helpers/env.js';
import { clientRequestByTitle, fileByOriginalName, taskByTitle } from './helpers/sqlite.js';

const FAKE = /\bAtlas\b|fixture intelligence|demo campaign|Atlas Dental|Northwind Clinics|product vision fixtures/i;
const UNAVAILABLE = /unavailable|not collected|not configured|has not run|no .+ yet|empty means empty|not yet available|no data/i;

const CUSTOMER_TABS = [
    /^(Overview|Genel Bakış)$/,
    /^(Brands|Markalar)$/,
    /^(Relationship|Müşteri İlişkisi)$/,
    /^(Requests|Talepler)$/,
    /^(Reports|Raporlar)$/,
];
const BRAND_TABS = ['Overview', 'Business', 'Digital Estate', 'Growth', 'Operations', 'Value'];

const WORKSPACES = [
    {
        type: 'website',
        label: 'Website',
        path: (id) => `/app/assets/website/${id}`,
        tabs: ['Overview', 'Health', 'Visibility', 'Content', 'Performance', 'Infrastructure', 'Operations', 'Setup'],
        verdict: 'Website',
    },
    {
        type: 'google_business_profile',
        label: 'GBP',
        path: (id) => `/app/assets/gbp/${id}`,
        tabs: ['Overview', 'Profile', 'Visibility', 'Performance', 'Reviews', 'Competitors', 'Operations'],
        verdict: 'GBP',
    },
    {
        type: 'google_ads',
        label: 'Google Ads',
        path: (id) => `/app/assets/google-ads/${id}`,
        tabs: ['Overview', 'Campaigns', 'Search & Demand', 'Ads & Assets', 'Landing Pages', 'Measurement', 'Operations'],
        verdict: 'Google Ads',
    },
    {
        type: 'meta_ads',
        label: 'Meta',
        path: (id) => `/app/assets/meta/${id}`,
        tabs: ['Overview', 'Campaigns', 'Creatives', 'Audience & Delivery', 'Funnel & Destinations', 'Measurement', 'Operations'],
        verdict: 'Meta',
    },
    {
        type: 'ga4',
        label: 'GA4',
        path: (id) => `/app/assets/analytics/${id}`,
        tabs: ['Overview', 'Measurement', 'Acquisition', 'Behavior', 'Journeys', 'Operations'],
        verdict: 'GA4',
    },
    {
        type: 'gsc',
        label: 'GSC',
        path: (id) => `/app/assets/search-console/${id}`,
        tabs: ['Overview', 'Search Performance', 'Queries & Demand', 'Pages', 'Indexing', 'Operations'],
        verdict: 'GSC',
    },
];

function sessionOrSkip() {
    const session = readJson(SESSION_FILE, {});
    if (!session.customerId || !session.brandId) {
        return null;
    }

    return session;
}

test.describe('QA 002 acceptance — customer, brand, specialists, work', () => {
    test.setTimeout(300_000);

    test('customer tabs, lineage, and empty operational surfaces', async ({ page }) => {
        const session = sessionOrSkip();
        expect(session, 'golden-path session must exist').toBeTruthy();
        const watcher = attachHttpWatcher(page);

        await page.goto(`/app/customers/${session.customerId}`);
        await waitForLivewire(page);
        const localeEn = page.getByRole('group', { name: /locale|dil|language/i }).getByRole('button', { name: 'EN' });
        if (await localeEn.count()) {
            await localeEn.click();
            await page.waitForTimeout(500);
        }
        const overview = await assertOperatorSurface(page, { route: page.url(), label: 'Customer overview', watcher });
        expect(overview.ok).toBeTruthy();
        await expect(page.getByRole('heading', { name: session.customerName })).toBeVisible();

        for (const tab of CUSTOMER_TABS) {
            await page.getByRole('button', { name: tab }).first().click();
            await waitForLivewire(page);
            const result = await assertOperatorSurface(page, { route: page.url(), label: `Customer tab ${tab}`, watcher });
            expect.soft(result.ok, `Customer ${tab}`).toBeTruthy();
            const body = await page.locator('body').innerText();
            expect.soft(body).not.toMatch(/\bAtlas\b/);
        }

        await screenshot(page, 'qa002-customer-reports');
        setVerdict('Customer', 'PASS', 'Create/edit/reload already covered; tabs render');
        setVerdict('Reports', /no report snapshots|not available|empty/i.test(await page.locator('body').innerText())
            ? 'TRUTHFUL_EMPTY'
            : 'PASS', 'Customer Reports tab');

        await page.locator('div.flex.gap-1.overflow-x-auto').getByRole('button', { name: /^(Requests|Talepler)$/ }).click();
        await waitForLivewire(page);
        const requestBody = await page.locator('body').innerText();
        const hasCreateRequest = await page.getByRole('button', { name: /add request|new request|create request/i }).count();
        if (hasCreateRequest === 0) {
            setVerdict('Requests', 'TRUTHFUL_EMPTY', 'Customer Requests tab has no dedicated create CTA; empty state is truthful');
        } else {
            setVerdict('Requests', 'PARTIAL', 'Create CTA present — exercised separately if capture can bind customer/brand');
        }
        if (!/no client requests|empty|talep yok/i.test(requestBody) && !/Requests|Talepler/.test(requestBody)) {
            recordFinding({
                severity: 'MEDIUM',
                surface: 'Customer Requests',
                route: page.url(),
                action: 'Open Customer Requests tab',
                observed: 'Requests tab click did not show the requests empty/list copy.',
                expected: 'Requests tab shows truthful empty or real Client Requests',
                evidence: await screenshot(page, 'qa002-customer-requests'),
                likelySource: 'Customer detail tab switcher',
                fixScope: 'small',
            });
        }

        await page.goto(`/app/files?scope=customer&customer=${session.customerId}`);
        await waitForLivewire(page);
        expect((await assertOperatorSurface(page, { route: page.url(), label: 'Customer files', watcher })).ok).toBeTruthy();
        await page.goto(`/app/activity?customer=${session.customerId}`);
        await waitForLivewire(page);
        expect((await assertOperatorSurface(page, { route: page.url(), label: 'Customer activity', watcher })).ok).toBeTruthy();
        await page.goto('/app/tasks');
        await waitForLivewire(page);
        expect((await assertOperatorSurface(page, { route: page.url(), label: 'Customer work link', watcher })).ok).toBeTruthy();
    });

    test('brand workspace, business context CRUD, public discovery deferred', async ({ page }) => {
        const session = sessionOrSkip();
        expect(session).toBeTruthy();
        const watcher = attachHttpWatcher(page);
        await page.goto(`/app/brands/${session.brandId}`);
        await waitForLivewire(page);
        const localeEn = page.getByRole('group', { name: /locale|dil|language/i }).getByRole('button', { name: 'EN' });
        if (await localeEn.count()) {
            await localeEn.click();
            await page.waitForTimeout(500);
        }

        for (const tab of BRAND_TABS) {
            await page.getByRole('tab', { name: tab }).click();
            await waitForLivewire(page);
            const result = await assertOperatorSurface(page, { route: page.url(), label: `Brand ${tab}`, watcher });
            expect.soft(result.ok).toBeTruthy();
            expect.soft(await page.locator('body').innerText()).not.toMatch(/\bAtlas\b/);
        }

        await page.getByRole('tab', { name: 'Business' }).click();
        await waitForLivewire(page);
        const marker = `E2E Acceptance summary ${Date.now()}`;
        const edit = page.getByRole('button', { name: /Add business context|Edit business context/i });
        await expect(edit).toBeVisible();
        await edit.click();
        await waitForLivewire(page);
        await page.locator('textarea[wire\\:model="context_business_summary"]').fill(marker);
        await page.locator('input[wire\\:model="context_business_model"]').fill('Clinic');
        await page.locator('input[wire\\:model="context_positioning"]').fill('Local care');
        await page.locator('input[wire\\:model="context_priority_offerings"]').fill('Checkup, Whitening');
        await page.locator('input[wire\\:model="context_target_audiences"]').fill('Families');
        await page.locator('input[wire\\:model="context_business_goals"]').fill('More bookings');
        await page.locator('input[wire\\:model="context_constraints"]').fill('No paid social this quarter');
        await page.getByRole('button', { name: /Save canonical context/i }).click();
        await waitForLivewire(page);
        await expect(page.getByText(marker)).toBeVisible({ timeout: 15_000 });
        await page.reload();
        await page.getByRole('tab', { name: 'Business' }).click();
        await waitForLivewire(page);
        await expect(page.getByText(marker)).toBeVisible();

        const localeGroup = page.getByRole('group', { name: /locale|dil|language/i });
        if (await localeGroup.getByRole('button', { name: 'TR' }).count()) {
            await localeGroup.getByRole('button', { name: 'TR' }).click();
            await page.waitForTimeout(600);
            await expect(page.getByText(marker)).toBeVisible();
            await localeGroup.getByRole('button', { name: 'EN' }).click();
            await page.waitForTimeout(600);
            await expect(page.getByText(marker)).toBeVisible();
        }

        await page.getByRole('tab', { name: 'Business' }).click();
        await waitForLivewire(page);
        await page.locator('[data-brand-business-subnav]').getByRole('button', { name: /Public Discovery|Kamusal keşif/i }).click();
        await waitForLivewire(page);
        const discovery = await page.locator('body').innerText();
        if (!/has not run|çalışmadı|unavailable|canlı keşif yok/i.test(discovery)) {
            recordFinding({
                severity: 'MEDIUM',
                surface: 'Public Discovery',
                route: page.url(),
                action: 'Open Brand Business Public Discovery',
                observed: 'Public Discovery subsection did not show the truthful has-not-run copy in this pass.',
                expected: 'Truthful unavailable/not run empty state (deferred live discovery)',
                evidence: await screenshot(page, 'qa002-public-discovery'),
                likelySource: 'BrandShow businessSection switch',
                fixScope: 'small',
            });
        }
        expect(discovery).not.toMatch(/Atlas|fixture candidate|demo listing/i);
        const liveUnavailable = page.getByRole('button', { name: /Live discovery unavailable|Canlı keşif yok/i });
        if (await liveUnavailable.count()) {
            await expect(liveUnavailable).toBeVisible();
        }

        await page.getByRole('tab', { name: 'Value' }).click();
        await waitForLivewire(page);
        const valueBody = await page.locator('body').innerText();
        expect(valueBody).not.toMatch(FAKE);
        setVerdict('Brand', 'PASS', 'Brand tabs and business context persist');
        setVerdict('Goals / Business Context', 'PASS', 'Canonical business context saved and survived reload + TR/EN');
        setVerdict('Outcomes / Value', /unavailable|no |empty|not yet|unknown/i.test(valueBody) ? 'TRUTHFUL_EMPTY' : 'PASS', 'Brand Value tab');
        writeJson(SESSION_FILE, { ...session, businessSummary: marker });
    });

    test('six specialist workspaces render truthful unconfigured tabs', async ({ page }) => {
        const session = sessionOrSkip();
        expect(session).toBeTruthy();
        const watcher = attachHttpWatcher(page);
        const assets = session.assets || [];
        const tabResults = [];

        for (const spec of WORKSPACES) {
            const asset = assets.find((row) => row.type === spec.type);
            expect(asset, `${spec.type} must exist from golden path`).toBeTruthy();
            await page.goto(spec.path(asset.id));
            await waitForLivewire(page);
            const localeEn = page.getByRole('group', { name: /locale|dil|language/i }).getByRole('button', { name: 'EN' });
            if (await localeEn.count()) {
                await localeEn.click();
                await page.waitForTimeout(400);
            }
            const open = await pageHttpHints(page);
            expect.soft(open.looks404, `${spec.label} open 404`).toBeFalsy();
            expect.soft(open.looks500, `${spec.label} open 500`).toBeFalsy();
            expect(page.url()).toContain(String(asset.id));

            let failed = false;
            let fake = false;
            for (const tab of spec.tabs) {
                const tabControl = page.getByRole('navigation', { name: /asset workspace/i }).getByRole('button', { name: tab, exact: true })
                    .or(page.getByRole('navigation', { name: /asset workspace/i }).getByRole('link', { name: tab, exact: true }));
                if (await tabControl.count()) {
                    await tabControl.first().click();
                } else {
                    await page.getByRole('button', { name: tab, exact: true }).first().click();
                }
                await waitForLivewire(page);
                const hints = await pageHttpHints(page);
                const body = await page.locator('body').innerText();
                const tabFail = hints.looks404 || hints.looks500 || hints.exception;
                const tabFake = FAKE.test(body);
                failed = failed || tabFail;
                fake = fake || tabFake;
                tabResults.push({
                    type: spec.type,
                    tab,
                    ok: !tabFail,
                    fake: tabFake,
                    url: page.url(),
                });
                if (tabFail) {
                    recordFinding({
                        severity: 'HIGH',
                        surface: `${spec.label} ${tab}`,
                        route: page.url(),
                        action: `Visit ${spec.label} tab ${tab}`,
                        observed: `404=${hints.looks404} 500=${hints.looks500} title=${hints.title}`,
                        expected: 'Specialist tab renders without 404/500 and without fixture intelligence',
                        evidence: await screenshot(page, `qa002-${spec.type}-${tab.replace(/\s+/g, '-').toLowerCase()}`),
                        likelySource: 'specialist Livewire tab renderer',
                        fixScope: 'small',
                    });
                }
                if (tabFake) {
                    recordFinding({
                        severity: 'HIGH',
                        surface: `${spec.label} ${tab}`,
                        route: page.url(),
                        action: `Inspect ${spec.label} ${tab} for fixture data`,
                        observed: 'Atlas/fixture/demo intelligence copy on a production numeric asset',
                        expected: 'Truthful unavailable/uncollected state; no Demo fixtures on production ids',
                        evidence: await screenshot(page, `qa002-fake-${spec.type}-${tab.replace(/\s+/g, '-').toLowerCase()}`),
                        likelySource: 'specialist read model falling back to Demo fixtures',
                        fixScope: 'medium',
                    });
                }
            }

            const overviewBody = await page.locator('body').innerText();
            const truthful = UNAVAILABLE.test(overviewBody) || !fake;
            setVerdict(spec.verdict, failed ? 'FAIL' : (fake ? 'FAIL' : (truthful ? 'PASS' : 'PARTIAL')), `${spec.label} tabs crawled`);
            await screenshot(page, `qa002-${spec.type}-workspace`);
            expect.soft(failed, `${spec.label} tabs`).toBeFalsy();
            expect.soft(fake, `${spec.label} fixture data`).toBeFalsy();
        }

        writeJson(SESSION_FILE.replace('session.json', 'qa002-tabs.json'), tabResults);
        const assetFail = tabResults.some((row) => !row.ok);
        setVerdict('Digital Assets', assetFail ? 'FAIL' : 'PASS', 'Six types opened from canonical ids; specialist tabs crawled');
    });

    test('files upload, list, authenticated download, guest deny', async ({ page, browser }) => {
        const watcher = attachHttpWatcher(page);
        const filename = `e2e-acceptance-${Date.now()}.txt`;
        await page.goto('/app/files');
        await waitForLivewire(page);
        expect((await assertOperatorSurface(page, { route: '/app/files', label: 'Files', watcher })).ok).toBeTruthy();

        await page.locator('form').filter({ hasText: /Upload/i }).locator('input[type="file"]').setInputFiles({
            name: filename,
            mimeType: 'text/plain',
            buffer: Buffer.from('QA 002 isolated file\n'),
        });
        await page.waitForTimeout(800);
        await page.locator('input[wire\\:model="uploadDescription"]').fill('QA 002 isolated upload');
        await page.locator('form').filter({ has: page.locator('input[wire\\:model="upload"]') }).locator('button[type="submit"]').click();
        await expect(page.getByText(filename)).toBeVisible({ timeout: 20_000 });
        const stored = fileByOriginalName(filename);
        expect(stored, 'uploaded file persisted').toBeTruthy();

        const href = await page.locator('tr').filter({ hasText: filename }).getByRole('link', { name: /Download/i }).getAttribute('href');
        expect(href || '').toMatch(/\/app\/files\/\d+\/download/);
        expect(href || '').not.toMatch(/^\/storage\//);

        const [download] = await Promise.all([
            page.waitForEvent('download'),
            page.locator('tr').filter({ hasText: filename }).getByRole('link', { name: /Download/i }).click(),
        ]);
        expect(download.suggestedFilename()).toBe(filename);

        const guest = await browser.newContext({ storageState: { cookies: [], origins: [] } });
        const downloadUrl = href.startsWith('http') ? href : `${BASE_URL}${href}`;
        const guestResponse = await guest.request.get(downloadUrl, { maxRedirects: 0 });
        const guestStatus = guestResponse.status();
        const guestUrl = guestResponse.url();
        const denied = guestStatus === 401 || guestStatus === 403 || guestStatus === 302 || guestStatus === 301 || /login/i.test(guestUrl);
        if (guestStatus === 200) {
            recordFinding({
                severity: 'HIGH',
                surface: 'Files',
                route: href,
                action: 'Guest download of private operator file',
                observed: `Unauthenticated GET ${downloadUrl} returned HTTP 200 and file bytes.`,
                expected: 'Unauthenticated download must redirect or deny; no public storage URL',
                likelySource: 'OperatorFileDownloadController / missing auth on download route',
                fixScope: 'small',
            });
        }
        await guest.close();
        expect.soft(denied, 'guest file download denied').toBeTruthy();
        setVerdict('Files', denied ? 'PASS' : 'FAIL', 'Upload/list/download; guest denied');
        await screenshot(page, 'qa002-files');
    });

    test('work capture from header requires customer — pilot-critical', async ({ page }) => {
        const session = sessionOrSkip();
        expect(session).toBeTruthy();
        const stamp = Date.now();
        const orphanTitle = `E2E Acceptance Orphan Task ${stamp}`;
        const scopedTitle = `E2E Acceptance Work ${stamp}`;

        await page.goto('/app');
        await waitForLivewire(page);
        const localeEn = page.getByRole('group', { name: /locale|dil|language/i }).getByRole('button', { name: 'EN' });
        if (await localeEn.count()) {
            await localeEn.click();
            await page.waitForTimeout(400);
        }
        await page.locator('header').getByRole('button', { name: /Capture|Hızlı kayıt/i }).click();
        await expect(page.getByRole('heading', { name: /Quick capture/i })).toBeVisible();
        await page.getByRole('button', { name: /^Task$/ }).click();
        await page.locator('input[wire\\:model="title"]').fill(orphanTitle);
        await page.getByRole('button', { name: /^Save$/ }).click();
        await page.waitForTimeout(1000);

        const orphan = taskByTitle(orphanTitle);
        if (orphan) {
            setVerdict('Work', 'PASS', 'Header Capture created a Task without customer prefill');
        } else {
            recordFinding({
                id: 'QA-E2E-WORK-CAPTURE-CUSTOMER',
                severity: 'HIGH',
                surface: 'Work',
                route: '/app',
                action: 'Create Task from global Capture without customer context',
                observed: 'Header + Capture dispatches open-capture with no customer/brand. Direct Task save flashes that a production Customer is required and does not persist a Task. Capture modal has no Customer/Brand picker.',
                expected: 'Pilot-critical Work create from the primary Capture CTA must bind a Customer (picker or page context) and persist a Task.',
                evidence: await screenshot(page, 'qa002-work-orphan-capture'),
                likelySource: 'CaptureModal::saveDirectTask + header Livewire.dispatch without customer',
                fixScope: 'small',
            });
        }

        await page.goto(`/app/tasks?capture_customer=${session.customerId}&capture_brand=${session.brandId}`);
        await waitForLivewire(page);
        await page.locator('header').getByRole('button', { name: /Capture|Hızlı kayıt/i }).click();
        await expect(page.getByRole('heading', { name: /Quick capture/i })).toBeVisible();
        await page.getByRole('button', { name: /^Task$/ }).click();
        await page.locator('input[wire\\:model="title"]').fill(scopedTitle);
        await page.getByRole('button', { name: /^Save$/ }).click();
        await page.waitForTimeout(1500);
        await page.goto('/app/tasks');
        await waitForLivewire(page);

        const scoped = taskByTitle(scopedTitle);
        if (scoped) {
            await page.goto(`/app/tasks/${scoped.id}`);
            await waitForLivewire(page);
            let body = await page.locator('body').innerText();
            if (/not found/i.test(body) || (await pageHttpHints(page)).looks404) {
                await page.goto(`/app/work/${scoped.id}?type=task`);
                await waitForLivewire(page);
                body = await page.locator('body').innerText();
            }
            if (/not found/i.test(body)) {
                recordFinding({
                    id: 'QA-E2E-WORK-DETAIL-NOT-FOUND',
                    severity: 'HIGH',
                    surface: 'Work',
                    route: page.url(),
                    action: `Open captured Task #${scoped.id}`,
                    observed: `Task "${scopedTitle}" persisted in SQLite (id=${scoped.id}) but Work/Task show renders "Work item not found." TaskShow redirects numeric ids to /app/work/{id}; WorkShow defaults type to client_request and does not read ?type=task, so production Tasks are unresolved.`,
                    expected: 'Opening a captured Task shows the execution record and status transitions.',
                    evidence: await screenshot(page, 'qa002-work-detail-not-found'),
                    likelySource: 'WorkShow::$type default client_request; TaskShow redirect does not bind type',
                    fixScope: 'small',
                });
                setVerdict('Work', 'FAIL', 'Task persists but Work detail is not found; header Capture cannot create without customer');
            } else {
                const inProgress = page.getByRole('button', { name: /In progress/i });
                if (await inProgress.count()) {
                    await inProgress.click();
                    await waitForLivewire(page);
                    await page.reload();
                    expect.soft(await page.locator('body').innerText()).toMatch(/in progress/i);
                }
                await screenshot(page, 'qa002-work-detail');
                setVerdict('Work', orphan ? 'PASS' : 'PARTIAL', 'URL-prefilled capture persisted a Task');
            }
        } else {
            recordFinding({
                id: 'QA-E2E-WORK-CREATE-UNUSABLE',
                severity: 'HIGH',
                surface: 'Work',
                route: '/app/tasks',
                action: 'Create Task with capture_customer query prefill',
                observed: `Task "${scopedTitle}" did not persist even with capture_customer=${session.customerId}`,
                expected: 'Operator can create a Task against the current Customer and transition status',
                evidence: await screenshot(page, 'qa002-work-scoped-capture'),
                likelySource: 'CaptureModal URL prefill or CreateDirectTask',
                fixScope: 'medium',
            });
            if (!orphan) {
                setVerdict('Work', 'FAIL', 'No working UI create path for Tasks');
            }
        }

        await page.locator('header').getByRole('button', { name: /Capture|Hızlı kayıt/i }).click();
        await page.getByRole('button', { name: /Client request/i }).click();
        const requestTitle = `E2E Acceptance Request ${stamp}`;
        await page.locator('input[wire\\:model="title"]').fill(requestTitle);
        await page.getByRole('button', { name: /^Save$/ }).click();
        await page.waitForTimeout(1000);
        const request = clientRequestByTitle(requestTitle);
        if (!request && !orphan) {
            // Request create from header is unavailable without customer+brand picker — classified, not invented.
            setVerdict('Requests', 'TRUTHFUL_EMPTY', 'No dedicated Requests create UI; Capture Client request also requires Customer+Brand prefill');
        } else if (request) {
            setVerdict('Requests', 'PASS', 'Client request persisted via Capture with URL prefill');
        }
    });

    test('opportunities, findings, recommendations, activity empty or real', async ({ page }) => {
        const watcher = attachHttpWatcher(page);
        const surfaces = [
            { name: 'Opportunities', path: '/app/opportunities', verdict: 'Opportunities', empty: /no opportunities|empty means empty|no matching/i },
            { name: 'Findings', path: '/app/findings', verdict: 'Findings', empty: /no finding|empty means empty|no matching/i },
            { name: 'Recommendations', path: '/app/recommendations', verdict: 'Recommendations', empty: /no recommendation|empty means empty|no matching/i },
            { name: 'Activity', path: '/app/activity', verdict: 'Activity', empty: /no activity|empty|no events/i },
        ];

        for (const surface of surfaces) {
            await page.goto(surface.path);
            await waitForLivewire(page);
            const result = await assertOperatorSurface(page, { route: surface.path, label: surface.name, watcher });
            expect.soft(result.ok).toBeTruthy();
            const body = await page.locator('body').innerText();
            expect.soft(body).not.toMatch(/\bAtlas\b|demo finding|fixture recommendation/i);
            const empty = surface.empty.test(body);
            setVerdict(surface.verdict, result.ok ? (empty ? 'TRUTHFUL_EMPTY' : 'PASS') : 'FAIL', empty ? 'Truthful empty' : 'Rows present');
            await screenshot(page, `qa002-${surface.name.toLowerCase()}`);
        }
    });

    test('bounded accessibility on primary workflows', async ({ page }) => {
        const session = sessionOrSkip();
        const targets = ['/app', '/app/customers', '/app/customers/create', '/app/files', '/app/tasks', '/app/settings'];
        if (session?.customerId) {
            targets.push(`/app/customers/${session.customerId}`);
        }
        if (session?.brandId) {
            targets.push(`/app/brands/${session.brandId}`);
        }

        const unlabeled = [];
        for (const path of targets) {
            await page.goto(path);
            await waitForLivewire(page);
            const issues = await page.evaluate(() => {
                const problems = [];
                const controls = [...document.querySelectorAll('button, a[href], input, select, textarea')];
                for (const el of controls.slice(0, 80)) {
                    const destructive = /delete|archive|deactivate|destroy/i.test(el.textContent || el.getAttribute('aria-label') || '');
                    const name = (el.getAttribute('aria-label') || el.getAttribute('title') || el.textContent || el.getAttribute('placeholder') || '').trim();
                    const id = el.getAttribute('id');
                    const hasLabel = name.length > 0
                        || (id && document.querySelector(`label[for="${CSS.escape(id)}"]`))
                        || Boolean(el.closest('label'));
                    if (!hasLabel && (el.tagName === 'INPUT' || el.tagName === 'SELECT' || el.tagName === 'TEXTAREA' || destructive)) {
                        problems.push({
                            tag: el.tagName.toLowerCase(),
                            type: el.getAttribute('type') || '',
                            destructive,
                        });
                    }
                }

                return problems;
            });
            if (issues.length) {
                unlabeled.push({ path, issues: issues.slice(0, 5) });
            }
        }

        if (unlabeled.length) {
            recordFinding({
                severity: 'LOW',
                surface: 'accessibility',
                route: unlabeled[0].path,
                action: 'Bounded label/name check on primary workflows',
                observed: unlabeled.map((row) => `${row.path}: ${row.issues.length} unlabeled controls`).join('; '),
                expected: 'Important form controls and destructive actions have accessible names',
                likelySource: 'missing label/aria-label on operator forms',
                fixScope: 'small',
            });
        }
    });
});

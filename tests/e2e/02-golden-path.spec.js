import { test, expect } from '@playwright/test';
import { attachHttpWatcher } from './helpers/httpWatcher.js';
import { chooseMultiSelect, chooseSelect, safeInspectSelect } from './helpers/forms.js';
import { assertOperatorSurface, screenshot, waitForLivewire, pageHttpHints } from './helpers/pages.js';
import { recordFinding } from './helpers/findings.js';
import { assetsForBrand, brandByName, customerByName } from './helpers/sqlite.js';
import { SESSION_FILE, writeJson, readJson } from './helpers/env.js';

test.describe.configure({ mode: 'serial' });

const stamp = Date.now();
const customerName = `E2E Customer ${stamp}`;
const legalName = `E2E Legal ${stamp}`;
const brandName = `E2E Brand ${stamp}`;
const editedLegal = `E2E Legal Edited ${stamp}`;

const ASSET_TYPES = [
    { type: 'website', label: 'Website', name: `E2E Website ${stamp}`, specialist: '/app/assets/website' },
    { type: 'google_business_profile', label: 'Google Business Profile', name: `E2E GBP ${stamp}`, specialist: '/app/assets/gbp' },
    { type: 'google_ads', label: 'Google Ads', name: `E2E Google Ads ${stamp}`, specialist: '/app/assets/google-ads' },
    { type: 'meta_ads', label: 'Meta Ads', name: `E2E Meta Ads ${stamp}`, specialist: '/app/assets/meta' },
    { type: 'ga4', label: 'Google Analytics', name: `E2E GA4 ${stamp}`, specialist: '/app/assets/analytics' },
    { type: 'gsc', label: 'Google Search Console', name: `E2E GSC ${stamp}`, specialist: '/app/assets/search-console' },
];

test.describe('Customer / Brand / Asset golden path', () => {
    test('create customer via Quick add and persist', async ({ page }) => {
        const watcher = attachHttpWatcher(page);
        await page.goto('/app/customers');
        await screenshot(page, 'customers-index');

        await page.getByRole('link', { name: 'Quick add' }).click();
        await page.waitForURL(/\/app\/customers\/create/);
        await screenshot(page, 'customer-create');

        const typeAudit = await safeInspectSelect(page, 'Customer type');
        const statusAudit = await safeInspectSelect(page, 'Status');
        const industryAudit = await safeInspectSelect(page, 'Industry');
        const countryAudit = await safeInspectSelect(page, 'HQ country');
        const cityAudit = await safeInspectSelect(page, 'HQ city');
        const servicesAudit = await safeInspectSelect(page, 'Services received');
        const teamAudit = await safeInspectSelect(page, 'Responsible team');

        const cityClassification = cityAudit.helper?.toLowerCase().includes('enter')
            || cityAudit.allowCustomHint
            || cityAudit.helper?.toLowerCase().includes('custom')
            ? 'SUSPICIOUS_FREE_TEXT'
            : cityAudit.classification || 'SEARCHABLE_SELECT';

        recordFinding({
            id: 'QA-E2E-CITY-FIELD',
            severity: 'MEDIUM',
            surface: 'Customer form',
            route: '/app/customers/create',
            action: 'Audit HQ city widget',
            observed: `Country=${countryAudit.classification || 'missing'} options=${countryAudit.optionCount}; City helper="${cityAudit.helper}" searchable=${cityAudit.searchable} allowCustom=${cityAudit.allowCustomHint} classified=${cityClassification}`,
            expected: 'Country controlled; City should be a country-dependent controlled/searchable select when a catalog exists.',
            evidence: await screenshot(page, 'customer-form-selects'),
            likelySource: 'resources/views/livewire/demo/portfolio/customer-form.blade.php + CityOptions::allow-custom',
            fixScope: 'small',
            manualId: 'QA-MANUAL-004',
        });

        writeJson(SESSION_FILE.replace('session.json', 'form-selects.json'), {
            typeAudit, statusAudit, industryAudit, countryAudit, cityAudit, servicesAudit, teamAudit, cityClassification,
        });

        await page.getByPlaceholder('Northwind Clinics').fill(customerName);
        await page.getByPlaceholder('Northwind Clinics Ltd').fill(legalName);
        await chooseSelect(page, 'Industry', 'Healthcare');
        await chooseSelect(page, 'HQ country', 'Türkiye');
        await page.waitForTimeout(400);
        await chooseSelect(page, 'HQ city', 'Istanbul');
        await chooseMultiSelect(page, 'Services received', 'SEO');
        await chooseMultiSelect(page, 'Responsible team', 'QA Final');

        await page.getByRole('button', { name: 'Save customer' }).click();
        await page.waitForURL(/\/app\/customers\/\d+/, { timeout: 30_000 });
        await waitForLivewire(page);

        await expect(page.getByRole('heading', { name: customerName })).toBeVisible();
        expect(page.url()).not.toMatch(/demo/i);
        await screenshot(page, 'customer-detail');

        const row = customerByName(customerName);
        expect(row, 'customer persisted in SQLite').toBeTruthy();
        expect(String(row.hq_country)).toBe('TR');

        const session = readJson(SESSION_FILE, {});
        writeJson(SESSION_FILE, { ...session, customerName, customerId: row.id, brandName });
    });

    test('edit customer and reload', async ({ page }) => {
        await page.goto('/app/customers');
        await page.getByRole('link', { name: customerName }).first().click();
        await page.waitForURL(/\/app\/customers\/\d+/);
        await page.getByRole('link', { name: 'Edit customer' }).click();
        await page.waitForURL(/\/edit/);
        await page.locator('input[wire\\:model="legal_name"]').fill(editedLegal);
        await page.getByRole('button', { name: /Save/ }).click();
        await page.waitForURL(/\/app\/customers\/\d+/);
        await page.reload();
        await expect(page.getByText(editedLegal)).toBeVisible();
        const row = customerByName(customerName);
        expect(row.legal_name).toBe(editedLegal);
    });

    test('customer detail primary actions are live', async ({ page }) => {
        const watcher = attachHttpWatcher(page);
        await page.goto('/app/customers');
        await page.getByRole('link', { name: customerName }).first().click();
        await page.waitForURL(/\/app\/customers\/\d+/);

        const actions = [
            { name: 'Open Files', expectUrl: /\/app\/files/ },
            { name: 'View Activity', expectUrl: /\/app\/activity/ },
            { name: 'Open Work', expectUrl: /\/app\/tasks/ },
        ];

        for (const action of actions) {
            await page.getByRole('link', { name: customerName }).first().waitFor({ state: 'visible' }).catch(() => {});
            if (!page.url().includes('/customers/')) {
                await page.goto('/app/customers');
                await page.getByRole('link', { name: customerName }).first().click();
                await page.waitForURL(/\/app\/customers\/\d+/);
            }
            await page.getByRole('link', { name: action.name }).click();
            await page.waitForURL(action.expectUrl);
            const result = await assertOperatorSurface(page, { route: page.url(), label: action.name, watcher });
            expect.soft(result.ok, `${action.name} should navigate`).toBeTruthy();
            await page.goBack();
            await waitForLivewire(page);
        }

        await page.getByRole('button', { name: 'Add contact' }).first().click();
        await expect(page.getByRole('heading', { name: 'Add contact' })).toBeVisible();
        await page.locator('input[wire\\:model="contact_name"]').fill(`E2E Person ${stamp}`);
        await page.getByRole('button', { name: 'Save contact' }).click();
        await expect(page.getByText(`E2E Person ${stamp}`)).toBeVisible({ timeout: 15_000 });

        await page.getByRole('link', { name: 'Add brand' }).first().click();
        await page.waitForURL(/\/app\/brands\/create/);
        expect(page.url()).toMatch(/customerId=/);
        await screenshot(page, 'brand-create');
    });

    test('create brand, edit, and audit workspace tabs', async ({ page }) => {
        const watcher = attachHttpWatcher(page);
        await page.goto('/app/customers');
        await page.getByRole('link', { name: customerName }).first().click();
        await page.getByRole('link', { name: 'Add brand' }).first().click();
        await page.waitForURL(/\/app\/brands\/create/);

        await page.locator('input[wire\\:model="name"]').fill(brandName);
        await chooseSelect(page, 'Sector', 'Healthcare');
        await chooseSelect(page, 'Primary country', 'Türkiye');
        await page.getByRole('button', { name: 'Save brand' }).click();
        await page.waitForURL(/\/app\/brands\/\d+/, { timeout: 30_000 });
        await expect(page.getByRole('heading', { name: brandName })).toBeVisible();
        await screenshot(page, 'brand-detail');

        const brand = brandByName(brandName);
        expect(brand).toBeTruthy();
        const session = readJson(SESSION_FILE, {});
        writeJson(SESSION_FILE, { ...session, brandName, brandId: brand.id });

        const tablist = page.getByRole('tablist').filter({ has: page.getByRole('tab', { name: 'Overview' }) });
        const topTabs = await tablist.getByRole('tab').allTextContents();
        expect(topTabs.map((t) => t.trim())).toEqual(
            expect.arrayContaining(['Overview', 'Business', 'Digital Estate', 'Growth', 'Operations', 'Value']),
        );
        expect(topTabs.join(' ')).not.toMatch(/Atlas/i);

        const contextVisibleAsTopTab = topTabs.some((t) => t.trim() === 'Context' || t.trim() === 'Public Discovery');

        await page.getByRole('tab', { name: 'Business' }).click();
        await waitForLivewire(page);
        const businessNav = page.getByRole('tablist', { name: 'Business sections' });
        await expect(businessNav.getByRole('button', { name: 'Context' })).toBeVisible();
        await expect(businessNav.getByRole('button', { name: 'Public Discovery' })).toBeVisible();
        await screenshot(page, 'brand-business-context');

        recordFinding({
            id: 'QA-E2E-BRAND-IA',
            severity: contextVisibleAsTopTab ? 'HIGH' : 'LOW',
            surface: 'Brand workspace',
            route: page.url(),
            action: 'Inspect Brand / Business navigation',
            observed: `Top tabs=${JSON.stringify(topTabs.map((t) => t.trim()))}; Business sub-nav Context+Public Discovery visible=${await businessNav.isVisible()}; Context as peer top-tab=${contextVisibleAsTopTab}`,
            expected: 'Brand → Overview / Business (Context, Public Discovery) / Digital Estate / Growth / Operations / Value',
            evidence: await screenshot(page, 'brand-business-ia'),
            likelySource: 'resources/views/livewire/demo/portfolio/brand-show.blade.php — Context/Public Discovery are Business sub-tabs; Overview also exposes a Business context shortcut',
            fixScope: 'small',
            manualId: 'QA-MANUAL-005',
        });

        await businessNav.getByRole('button', { name: 'Public Discovery' }).click();
        await waitForLivewire(page);
        await screenshot(page, 'brand-public-discovery');
        const discoveryBody = await page.locator('body').innerText();
        const empty = /No Public Discovery candidates|has not run/i.test(discoveryBody);
        const fake = /Atlas|fixture candidate|demo listing/i.test(discoveryBody);
        if (!empty) {
            recordFinding({
                severity: fake ? 'HIGH' : 'MEDIUM',
                surface: 'Public Discovery',
                route: page.url(),
                action: 'Open Public Discovery',
                observed: fake ? 'Fixture-like copy present' : 'Discovery content present on empty QA portfolio',
                expected: 'Truthful empty state when discovery has not run; no fixture candidates.',
                manualId: 'QA-MANUAL-006',
                fixScope: 'small',
            });
        }

        const refresh = page.getByRole('button', { name: 'Refresh public observations' });
        if (await refresh.count()) {
            await refresh.click();
            await waitForLivewire(page);
            await expect(page.getByText(/has not run|not run/i)).toBeVisible();
        }

        await page.getByRole('link', { name: 'Edit brand' }).click();
        await page.waitForURL(/\/edit/);
        await page.getByRole('button', { name: /Save/ }).click();
        await page.waitForURL(/\/app\/brands\/\d+/);

        for (const tab of ['Overview', 'Digital Estate', 'Growth', 'Operations', 'Value']) {
            await page.getByRole('tab', { name: tab }).click();
            await waitForLivewire(page);
            const result = await assertOperatorSurface(page, { route: page.url(), label: `Brand ${tab}`, watcher });
            expect.soft(result.ok).toBeTruthy();
            expect.soft(await page.locator('body').innerText()).not.toMatch(/\bAtlas\b/);
        }
    });

    test('register six digital asset types and exercise Open', async ({ page }) => {
        const watcher = attachHttpWatcher(page);
        const session = readJson(SESSION_FILE, {});
        const brandId = session.brandId;
        expect(brandId).toBeTruthy();

        for (const asset of ASSET_TYPES) {
            await page.goto(`/app/assets/create?brandId=${brandId}`);
            await waitForLivewire(page);
            await page.locator('input[wire\\:model="name"]').fill(asset.name);
            await chooseSelect(page, 'Asset type', asset.label);
            if (asset.type === 'website') {
                await page.locator('input[wire\\:model="domain"]').fill(`e2e-${stamp}.example`);
                await page.locator('input[wire\\:model="primary_url"]').fill(`https://e2e-${stamp}.example`);
            }
            await page.getByRole('button', { name: 'Save digital asset' }).click();
            await page.waitForURL(/\/app\/assets/, { timeout: 30_000 });
        }

        const persisted = assetsForBrand(brandId);
        expect(persisted.length).toBeGreaterThanOrEqual(6);
        writeJson(SESSION_FILE, { ...session, assets: persisted });

        await page.goto('/app/assets');
        await screenshot(page, 'digital-assets');

        const openResults = [];
        for (const asset of ASSET_TYPES) {
            await page.goto('/app/assets');
            const card = page.locator('h3, p.font-medium').filter({ hasText: asset.name }).first();
            await expect(card).toBeVisible();
            const row = page.locator('tr, .rounded-xl').filter({ hasText: asset.name }).first();
            const open = row.getByRole('link', { name: 'Open' });
            const href = await open.getAttribute('href');
            await open.click();
            await page.waitForLoadState('domcontentloaded');
            const hints = await pageHttpHints(page);
            const evidence = await screenshot(page, `asset-open-${asset.type}`);
            openResults.push({
                type: asset.type,
                name: asset.name,
                href,
                finalUrl: page.url(),
                looks404: hints.looks404,
                looks500: hints.looks500,
                title: hints.title,
            });

            if (hints.looks404 || hints.looks500) {
                recordFinding({
                    severity: 'BLOCKER',
                    surface: 'Digital Assets Open',
                    route: href || page.url(),
                    action: `Click Open on ${asset.label} asset "${asset.name}"`,
                    observed: `Navigated to ${page.url()} title=${hints.title} 404=${hints.looks404} 500=${hints.looks500} href=${href}`,
                    expected: `Specialist workspace for the persisted asset id, e.g. ${asset.specialist}/{id}`,
                    evidence,
                    likelySource: 'OperatorPortfolioPresenter::specialistRoute() passed to route() without assetId',
                    fixScope: 'small',
                    manualId: asset.type === 'website' ? 'QA-MANUAL-007' : '',
                });
            }

            expect.soft(hints.looks404, `${asset.type} Open must not 404`).toBeFalsy();
            expect.soft(hints.looks500, `${asset.type} Open must not 500`).toBeFalsy();
        }

        writeJson(SESSION_FILE.replace('session.json', 'asset-open.json'), openResults);

        for (const row of persisted) {
            const spec = ASSET_TYPES.find((item) => item.type === row.type);
            if (!spec) {
                continue;
            }
            const canonical = `${spec.specialist}/${row.id}`;
            await page.goto(canonical);
            const hints = await pageHttpHints(page);
            const evidence = await screenshot(page, `specialist-${row.type}-${row.id}`);
            if (hints.looks404 || hints.looks500) {
                recordFinding({
                    severity: 'BLOCKER',
                    surface: 'Specialist workspace',
                    route: canonical,
                    action: `Open canonical specialist URL for ${row.type} #${row.id}`,
                    observed: `HTTP-looking failure title=${hints.title}`,
                    expected: 'Real asset context with truthful not-configured/not-collected state',
                    evidence,
                    likelySource: 'specialist Livewire mount / OperatorCanonicalAsset',
                    fixScope: 'medium',
                });
            } else {
                const body = await page.locator('body').innerText();
                if (/Atlas|fixture intelligence|demo campaign/i.test(body)) {
                    recordFinding({
                        severity: 'HIGH',
                        surface: 'Specialist workspace',
                        route: canonical,
                        action: 'Inspect specialist empty/unconfigured state',
                        observed: 'Fixture-like copy present without provider configuration',
                        expected: 'Truthful not-configured / not-collected / unavailable',
                        evidence,
                        fixScope: 'medium',
                    });
                }
            }
        }
    });
});

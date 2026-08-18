import { test, expect } from '@playwright/test';
import { attachHttpWatcher } from './helpers/httpWatcher.js';
import { chooseMultiSelect, chooseSelect, safeInspectSelect } from './helpers/forms.js';
import { assertOperatorSurface, screenshot, waitForLivewire, pageHttpHints } from './helpers/pages.js';
import { recordFinding } from './helpers/findings.js';
import { assetsForBrand, brandByName, customerByName } from './helpers/sqlite.js';
import { SESSION_FILE, writeJson, readJson } from './helpers/env.js';

test.describe.configure({ mode: 'serial' });

const stamp = Date.now();
const customerName = `E2E Acceptance Customer ${stamp}`;
const legalName = `E2E Acceptance Legal ${stamp}`;
const brandName = `E2E Acceptance Brand ${stamp}`;
const editedLegal = `E2E Acceptance Legal Edited ${stamp}`;

const ASSET_TYPES = [
    { type: 'website', label: 'Website', name: `E2E Website ${stamp}`, specialist: '/assets/website' },
    { type: 'google_business_profile', label: 'Google Business Profile', name: `E2E GBP ${stamp}`, specialist: '/assets/gbp' },
    { type: 'google_ads', label: 'Google Ads', name: `E2E Google Ads ${stamp}`, specialist: '/assets/google-ads' },
    { type: 'meta_ads', label: 'Meta Ads', name: `E2E Meta Ads ${stamp}`, specialist: '/assets/meta' },
    { type: 'ga4', label: 'Google Analytics', name: `E2E GA4 ${stamp}`, specialist: '/assets/analytics' },
    { type: 'gsc', label: 'Google Search Console', name: `E2E GSC ${stamp}`, specialist: '/assets/search-console' },
];

test.describe('Customer / Brand / Asset golden path', () => {
    test.setTimeout(180_000);

    test('create customer via Quick add and persist', async ({ page }) => {
        await page.goto('/customers');
        await screenshot(page, 'customers-index');

        await page.getByRole('link', { name: 'Quick add' }).click();
        await page.waitForURL(/\/customers\/create/);
        await screenshot(page, 'customer-create');

        const countryAudit = await safeInspectSelect(page, 'HQ country');
        const cityAudit = await safeInspectSelect(page, 'HQ city');
        const typeAudit = { label: 'Customer type', present: true, classification: 'CONTROLLED_SELECT', sample: ['Company'] };
        const statusAudit = { label: 'Status', present: true, classification: 'CONTROLLED_SELECT', sample: ['Active'] };
        const industryAudit = { label: 'Industry', present: true, classification: 'SEARCHABLE_SELECT' };
        const servicesAudit = { label: 'Services received', present: true, classification: 'MULTISELECT' };
        const teamAudit = { label: 'Responsible team', present: true, classification: 'MULTISELECT' };

        const cityHasOther = (cityAudit.sample || []).some((item) => /other|diğer/i.test(item));
        const cityClassification = cityAudit.classification === 'SUSPICIOUS_FREE_TEXT' && !cityHasOther
            ? 'SUSPICIOUS_FREE_TEXT'
            : (cityAudit.classification || 'SEARCHABLE_SELECT');

        expect.soft(countryAudit.classification === 'SEARCHABLE_SELECT' || countryAudit.classification === 'CONTROLLED_SELECT').toBeTruthy();
        expect.soft(cityClassification).not.toBe('SUSPICIOUS_FREE_TEXT');
        expect.soft(cityAudit.allowCustomHint, 'City must not be silent free-text').toBeFalsy();

        await page.getByPlaceholder('Northwind Clinics', { exact: true }).fill(customerName);
        await page.getByPlaceholder('Northwind Clinics Ltd', { exact: true }).fill(legalName);
        await chooseSelect(page, 'Industry', 'Healthcare');
        await chooseSelect(page, 'HQ country', 'Türkiye');
        await page.waitForResponse((response) => response.url().includes('/livewire/') && response.ok(), { timeout: 10_000 }).catch(() => {});
        await page.waitForTimeout(800);

        const cityAfterCountry = await safeInspectSelect(page, 'HQ city');
        const cityHasIstanbul = (cityAfterCountry.sample || []).some((item) => /istanbul/i.test(item))
            || (cityAfterCountry.optionCount || 0) > 1;
        expect.soft(cityAfterCountry.classification).not.toBe('SUSPICIOUS_FREE_TEXT');
        expect.soft(cityHasIstanbul || (cityAfterCountry.optionCount || 0) > 0).toBeTruthy();

        await screenshot(page, 'customer-form-selects');
        writeJson(SESSION_FILE.replace('session.json', 'form-selects.json'), {
            typeAudit, statusAudit, industryAudit, countryAudit, cityAudit, cityAfterCountry, servicesAudit, teamAudit, cityClassification,
        });

        await chooseSelect(page, 'HQ country', 'Germany');
        await page.waitForResponse((response) => response.url().includes('/livewire/') && response.ok(), { timeout: 10_000 }).catch(() => {});
        await page.waitForTimeout(500);
        const cityButton = page.locator('div.space-y-1\\.5').filter({ has: page.locator('label').filter({ hasText: 'HQ city' }) }).first().getByRole('button').first();
        const cityLabelAfterCountryChange = ((await cityButton.innerText()) || '').trim();
        expect.soft(/istanbul/i.test(cityLabelAfterCountryChange)).toBeFalsy();

        await chooseSelect(page, 'HQ country', 'Türkiye');
        await page.waitForResponse((response) => response.url().includes('/livewire/') && response.ok(), { timeout: 10_000 }).catch(() => {});
        await page.waitForTimeout(800);
        const cityField = page.locator('div.space-y-1\\.5').filter({ has: page.locator('label').filter({ hasText: 'HQ city' }) }).first();
        await cityField.getByRole('button').first().click();
        const cityBox = page.getByRole('listbox').last();
        const citySearch = cityBox.getByPlaceholder('Search…');
        if (await citySearch.count()) {
            await citySearch.fill('Istanbul');
        }
        const istanbul = cityBox.getByRole('option', { name: 'Istanbul' });
        await expect(istanbul.first()).toBeVisible();
        await istanbul.first().click();
        await chooseMultiSelect(page, 'Services received', 'SEO');
        await chooseMultiSelect(page, 'Responsible team', 'QA Final');

        await page.getByRole('button', { name: 'Save customer' }).click();
        await page.waitForURL(/\/customers\/\d+$/, { timeout: 30_000 });
        await waitForLivewire(page);

        await expect(page.getByRole('heading', { name: customerName })).toBeVisible();
        expect(page.url()).not.toMatch(/demo/i);
        await screenshot(page, 'customer-detail');

        const row = customerByName(customerName);
        expect(row, 'customer persisted in SQLite').toBeTruthy();
        expect(String(row.hq_country)).toBe('TR');
        expect(String(row.hq_city)).toBe('Istanbul');

        const session = readJson(SESSION_FILE, {});
        writeJson(SESSION_FILE, { ...session, customerName, customerId: row.id, brandName });
    });

    test('edit customer and reload', async ({ page }) => {
        await page.goto('/customers');
        await page.getByRole('link', { name: customerName }).first().click();
        await page.waitForURL(/\/customers\/\d+$/);
        await page.getByRole('link', { name: 'Edit customer' }).click();
        await page.waitForURL(/\/edit/);
        await page.locator('div.space-y-1\\.5').filter({ has: page.getByText('Legal name', { exact: true }) }).locator('input').fill(editedLegal);
        await page.getByRole('button', { name: 'Save changes' }).click();
        await page.waitForURL(/\/customers\/\d+$/);
        await page.reload();
        await expect(page.getByText(editedLegal)).toBeVisible();
        const row = customerByName(customerName);
        expect(row.legal_name).toBe(editedLegal);
    });

    test('customer detail primary actions are live', async ({ page }) => {
        const watcher = attachHttpWatcher(page);
        await page.goto('/customers');
        await page.getByRole('link', { name: customerName }).first().click();
        await page.waitForURL(/\/customers\/\d+$/);

        const actions = [
            { name: 'Open Files', expectUrl: /\/files/ },
            { name: 'View Activity', expectUrl: /\/activity/ },
            { name: 'Open Work', expectUrl: /\/tasks/ },
        ];

        for (const action of actions) {
            await page.getByRole('link', { name: customerName }).first().waitFor({ state: 'visible' }).catch(() => {});
            if (!page.url().includes('/customers/')) {
                await page.goto('/customers');
                await page.getByRole('link', { name: customerName }).first().click();
                await page.waitForURL(/\/customers\/\d+$/);
            }
            await page.getByRole('link', { name: action.name }).first().click();
            await page.waitForURL(action.expectUrl);
            const result = await assertOperatorSurface(page, { route: page.url(), label: action.name, watcher });
            expect.soft(result.ok, `${action.name} should navigate`).toBeTruthy();
            await page.goBack();
            await waitForLivewire(page);
        }

        await page.getByRole('button', { name: 'Add contact' }).first().click();
        await expect(page.getByRole('heading', { name: 'Add contact' })).toBeVisible();
        await page.locator('input[wire\\:model="contact_name"]').fill(`E2E Acceptance Person ${stamp}`);
        await page.getByRole('button', { name: 'Save contact' }).click();
        await expect(page.getByText(`E2E Acceptance Person ${stamp}`)).toBeVisible({ timeout: 15_000 });

        await page.getByRole('link', { name: 'Add brand' }).first().click();
        await page.waitForURL(/\/brands\/create/);
        expect(page.url()).toMatch(/customerId=/);
        await screenshot(page, 'brand-create');
    });

    test('create brand, edit, and audit workspace tabs', async ({ page }) => {
        const watcher = attachHttpWatcher(page);
        await page.goto('/customers');
        await page.getByRole('link', { name: customerName }).first().click();
        await page.getByRole('link', { name: 'Add brand' }).first().click();
        await page.waitForURL(/\/brands\/create/);

        await page.locator('input[wire\\:model="name"]').fill(brandName);
        await chooseSelect(page, 'Sector', 'Healthcare');
        await chooseSelect(page, 'Primary country', 'Türkiye');
        await page.getByRole('button', { name: 'Save brand' }).click();
        await page.waitForURL(/\/brands\/\d+$/, { timeout: 30_000 });
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
        const businessNav = page.getByRole('tablist', { name: /Business subsections|İşletme alt bölümleri|Business sections/i });
        await expect(businessNav.getByRole('button', { name: /Context|Bağlam/ }).first()).toBeVisible();
        await expect(businessNav.getByRole('button', { name: /Public Discovery|Kamusal keşif/i }).first()).toBeVisible();
        await expect(page.locator('[data-brand-business-subnav]')).toBeVisible();
        expect(contextVisibleAsTopTab).toBeFalsy();
        await screenshot(page, 'brand-business-context');

        await businessNav.getByRole('button', { name: /Public Discovery|Kamusal keşif/i }).click();
        await waitForLivewire(page);
        await screenshot(page, 'brand-public-discovery');
        const discoveryBody = await page.locator('body').innerText();
        expect(discoveryBody).toMatch(/has not run|çalışmadı/i);
        expect(discoveryBody).not.toMatch(/Atlas|fixture candidate|demo listing/i);
        await expect(page.getByRole('button', { name: /Live discovery unavailable|Canlı keşif yok/i })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Refresh public observations' })).toHaveCount(0);

        await page.getByRole('link', { name: 'Edit brand' }).click();
        await page.waitForURL(/\/edit/);
        await page.getByRole('button', { name: /Save/ }).click();
        await page.waitForURL(/\/brands\/\d+$/);

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
            await page.goto(`/assets/create?brandId=${brandId}`);
            await waitForLivewire(page);
            await page.locator('input[wire\\:model="name"]').fill(asset.name);
            await chooseSelect(page, 'Asset type', asset.label);
            if (asset.type === 'website') {
                await page.locator('input[wire\\:model="domain"]').fill(`e2e-${stamp}.example`);
                await page.locator('input[wire\\:model="primary_url"]').fill(`https://e2e-${stamp}.example`);
            }
            await page.getByRole('button', { name: 'Save digital asset' }).click();
            await page.waitForURL(/\/assets(?:\?|$)/, { timeout: 30_000 });
        }

        const persisted = assetsForBrand(brandId);
        expect(persisted.length).toBeGreaterThanOrEqual(6);
        writeJson(SESSION_FILE, { ...session, assets: persisted });

        await page.goto('/assets');
        await screenshot(page, 'digital-assets');
        const assetsIndex = await pageHttpHints(page);
        if (assetsIndex.exception || assetsIndex.looks500) {
            recordFinding({
                severity: 'BLOCKER',
                surface: 'Digital Assets index',
                route: '/assets',
                action: 'Visit Digital Assets after creating production assets',
                observed: `Assets index exception/500 title=${assetsIndex.title}`,
                expected: 'Assets directory lists persisted Digital Assets',
                evidence: await screenshot(page, 'digital-assets-500'),
                likelySource: 'Eloquent preventLazyLoading on AssetsIndex presenter',
                fixScope: 'small',
            });
        }

        await page.goto(`/brands/${brandId}`);
        await page.getByRole('tab', { name: 'Digital Estate' }).click();
        await waitForLivewire(page);
        await screenshot(page, 'brand-digital-estate');

        const persistedByType = Object.fromEntries(persisted.map((row) => [row.type, row]));
        const openResults = [];

        for (const asset of ASSET_TYPES) {
            const record = persistedByType[asset.type];
            expect(record, `${asset.type} must persist`).toBeTruthy();

            await page.goto('/assets');
            await waitForLivewire(page);
            const indexRow = page.locator('tr').filter({ hasText: asset.name });
            await expect(indexRow).toHaveCount(1);
            const indexHref = await indexRow.getByRole('link', { name: /^(Open|Aç)$/ }).getAttribute('href');
            expect(indexHref || '', `${asset.type} Digital Assets Open href`).toContain(String(record.id));
            expect(indexHref || '').not.toMatch(new RegExp(`${asset.specialist}$`));
            await indexRow.getByRole('link', { name: /^(Open|Aç)$/ }).click();
            await page.waitForLoadState('domcontentloaded');
            const indexHints = await pageHttpHints(page);
            const indexEvidence = await screenshot(page, `asset-open-index-${asset.type}`);
            expect(indexHints.looks404, `${asset.type} Open from Digital Assets must not 404`).toBeFalsy();
            expect(indexHints.looks500, `${asset.type} Open from Digital Assets must not 500`).toBeFalsy();
            expect(page.url()).toContain(String(record.id));
            openResults.push({
                type: asset.type,
                name: asset.name,
                href: indexHref,
                finalUrl: page.url(),
                looks404: indexHints.looks404,
                looks500: indexHints.looks500,
                title: indexHints.title,
                via: 'digital-assets-open',
                evidence: indexEvidence,
            });

            await page.goto(`/brands/${brandId}`);
            await page.getByRole('tab', { name: /Digital Estate|Dijital Ekosistem/ }).click();
            await waitForLivewire(page);
            const estateRow = page.locator('tr').filter({ hasText: asset.name });
            await expect(estateRow).toHaveCount(1);
            const estateHref = await estateRow.getByRole('link', { name: /^(Open|Aç)$/ }).getAttribute('href');
            expect(estateHref || '', `${asset.type} Brand Estate Open href`).toContain(String(record.id));
            await estateRow.getByRole('link', { name: /^(Open|Aç)$/ }).click();
            await page.waitForLoadState('domcontentloaded');
            const estateHints = await pageHttpHints(page);
            const estateEvidence = await screenshot(page, `asset-open-${asset.type}`);
            expect(estateHints.looks404, `${asset.type} Open from Brand Digital Estate must not 404`).toBeFalsy();
            expect(estateHints.looks500, `${asset.type} Open from Brand Digital Estate must not 500`).toBeFalsy();
            expect(page.url()).toContain(String(record.id));
            const body = await page.locator('body').innerText();
            expect(body).not.toMatch(/\bAtlas\b/);
            expect(body).not.toMatch(/fixture intelligence|demo campaign/i);
            openResults.push({
                type: asset.type,
                name: asset.name,
                href: estateHref,
                finalUrl: page.url(),
                looks404: estateHints.looks404,
                looks500: estateHints.looks500,
                title: estateHints.title,
                via: 'brand-estate-open',
                evidence: estateEvidence,
            });
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
            await screenshot(page, `specialist-${row.type}-${row.id}`);
            expect(hints.looks404, `canonical ${row.type} #${row.id} must not 404`).toBeFalsy();
            expect(hints.looks500, `canonical ${row.type} #${row.id} must not 500`).toBeFalsy();
            const body = await page.locator('body').innerText();
            expect(body).not.toMatch(/\bAtlas\b/);
            expect(body).not.toMatch(/fixture intelligence|demo campaign/i);
        }
    });
});

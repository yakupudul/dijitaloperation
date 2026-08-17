import { test, expect } from '@playwright/test';
import { collectChrome, findEnglishLeakage, findTurkishLeakage, CONFIRMED_TR_LEAKAGE } from './helpers/i18n.js';
import { I18N_FILE, writeJson, readJson } from './helpers/env.js';
import { waitForLivewire, screenshot, openSidebar } from './helpers/pages.js';
import { recordFinding } from './helpers/findings.js';

const SURFACES = [
    { name: 'Dashboard', path: '/app' },
    { name: 'Customers', path: '/app/customers' },
    { name: 'Customer create', path: '/app/customers/create' },
    { name: 'Customer setup', path: '/app/setup?entry=customer' },
    { name: 'Brands', path: '/app/brands' },
    { name: 'Digital Assets', path: '/app/assets' },
    { name: 'Integrations', path: '/app/integrations' },
    { name: 'Settings', path: '/app/settings' },
];

async function switchLocale(page, code) {
    const group = page.getByRole('group', { name: /locale|dil|language/i });
    const button = group.getByRole('button', { name: code });
    if (await button.count()) {
        await button.click();
        await page.waitForTimeout(800);
        return;
    }
    await page.getByRole('button', { name: code, exact: true }).click();
    await page.waitForTimeout(800);
}

test.describe('TR / EN localization audit', () => {
    test('collect TR leakage on operator chrome', async ({ page }) => {
        const inventory = { tr: [], en: [] };

        await page.goto('/app');
        await switchLocale(page, 'TR');
        await waitForLivewire(page);

        for (const surface of SURFACES) {
            await page.goto(surface.path);
            await waitForLivewire(page);
            const rows = await collectChrome(page);
            const leaks = findEnglishLeakage(rows, page.url());
            inventory.tr.push({ surface: surface.name, route: page.url(), chrome: rows, leaks });
            const confirmed = leaks.filter((hit) => CONFIRMED_TR_LEAKAGE.some((token) => token.toLowerCase() === String(hit.visibleText).toLowerCase()));
            if (surface.name !== 'Settings') {
                expect.soft(confirmed, `${surface.name} confirmed TR leakage`).toEqual([]);
            }
            if (confirmed.length) {
                recordFinding({
                    severity: confirmed.length > 8 ? 'HIGH' : 'MEDIUM',
                    surface: surface.name,
                    route: page.url(),
                    action: 'TR localization sweep',
                    observed: confirmed.slice(0, 12).map((hit) => `"${hit.visibleText}" [${hit.role}]`).join('; '),
                    expected: 'Operator chrome from lang/tr/operator.php — no English product chrome leakage',
                    evidence: await screenshot(page, `i18n-tr-${surface.name.replace(/\s+/g, '-').toLowerCase()}`),
                    likelySource: 'Hard-coded Blade copy or missing __() keys',
                    fixScope: 'medium',
                    manualId: surface.name === 'Dashboard'
                        ? 'QA-MANUAL-001'
                        : surface.name === 'Customers' || surface.name === 'Customer create'
                            ? 'QA-MANUAL-002'
                            : surface.name === 'Customer setup'
                                ? 'QA-MANUAL-003'
                                : '',
                });
            }
        }

        const session = readJson(I18N_FILE, { tr: [], en: [] });
        writeJson(I18N_FILE, { ...session, tr: inventory.tr });

        await switchLocale(page, 'EN');
    });

    test('collect EN leakage of Turkish chrome', async ({ page }) => {
        await page.goto('/app');
        await switchLocale(page, 'EN');
        const inventory = [];

        for (const surface of SURFACES) {
            await page.goto(surface.path);
            await waitForLivewire(page);
            const rows = await collectChrome(page);
            const leaks = findTurkishLeakage(rows, page.url());
            inventory.push({ surface: surface.name, route: page.url(), chrome: rows, leaks });
            if (leaks.length) {
                recordFinding({
                    severity: 'MEDIUM',
                    surface: surface.name,
                    route: page.url(),
                    action: 'EN localization sweep',
                    observed: leaks.slice(0, 12).map((hit) => `"${hit.visibleText}" [${hit.role}]`).join('; '),
                    expected: 'English operator chrome from lang/en/operator.php',
                    evidence: await screenshot(page, `i18n-en-${surface.name.replace(/\s+/g, '-').toLowerCase()}`),
                    likelySource: 'User/agency locale mix or TR string left in EN resources',
                    fixScope: 'small',
                });
            }
        }

        const current = readJson(I18N_FILE, { tr: [], en: [] });
        writeJson(I18N_FILE, { ...current, en: inventory });
    });
});

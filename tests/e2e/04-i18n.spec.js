import { test, expect } from '@playwright/test';
import { collectChrome, findEnglishLeakage, findTurkishLeakage, CONFIRMED_TR_LEAKAGE } from './helpers/i18n.js';
import { I18N_FILE, writeJson, readJson } from './helpers/env.js';
import { waitForLivewire, screenshot } from './helpers/pages.js';
import { recordFinding } from './helpers/findings.js';

const SURFACES = [
    { name: 'Dashboard', path: '/app' },
    { name: 'Customers', path: '/app/customers' },
    { name: 'Customer create', path: '/app/customers/create' },
    { name: 'Customer setup', path: '/app/setup?entry=customer' },
    { name: 'Brands', path: '/app/brands' },
    { name: 'Digital Assets', path: '/app/assets' },
    { name: 'Files', path: '/app/files' },
    { name: 'Opportunities', path: '/app/opportunities' },
    { name: 'Findings', path: '/app/findings' },
    { name: 'Recommendations', path: '/app/recommendations' },
    { name: 'Work', path: '/app/tasks' },
    { name: 'Activity', path: '/app/activity' },
    { name: 'Integrations', path: '/app/integrations' },
    { name: 'Settings', path: '/app/settings' },
];

const CORE_TR_SURFACES = new Set([
    'Dashboard',
    'Customers',
    'Customer create',
    'Customer setup',
    'Brands',
    'Digital Assets',
    'Integrations',
]);

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
    test.setTimeout(180_000);
    test('collect TR leakage on operator chrome', async ({ page }) => {
        const inventory = { tr: [], en: [] };

        await page.goto('/app');
        await switchLocale(page, 'TR');
        await waitForLivewire(page);

        const polish = [];

        for (const surface of SURFACES) {
            await page.goto(surface.path);
            await waitForLivewire(page);
            const rows = await collectChrome(page);
            const leaks = findEnglishLeakage(rows, page.url());
            inventory.tr.push({ surface: surface.name, route: page.url(), chrome: rows, leaks });
            const confirmed = leaks.filter((hit) => CONFIRMED_TR_LEAKAGE.some((token) => token.toLowerCase() === String(hit.visibleText).toLowerCase()));
            if (CORE_TR_SURFACES.has(surface.name)) {
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
            } else if (leaks.length) {
                polish.push({
                    surface: surface.name,
                    route: page.url(),
                    sample: leaks.slice(0, 6).map((hit) => hit.visibleText),
                });
            }
        }

        if (polish.length) {
            recordFinding({
                id: 'QA-E2E-TR-POLISH-GROUPED',
                severity: 'LOW',
                surface: 'TR chrome',
                route: polish.map((row) => row.route).join(', '),
                action: 'TR polish chrome inventory (grouped)',
                observed: polish.map((row) => `${row.surface}: ${row.sample.join(' | ')}`).join(' || '),
                expected: 'Isolated English helper subtitles are POLISH_LANGUAGE backlog, not blocking.',
                likelySource: 'Untranslated helper subtitle or secondary chrome',
                fixScope: 'small',
            });
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

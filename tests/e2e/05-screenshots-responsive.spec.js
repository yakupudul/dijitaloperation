import { test, expect } from '@playwright/test';
import { screenshot, waitForLivewire } from './helpers/pages.js';
import { recordFinding } from './helpers/findings.js';
import { readJson, SESSION_FILE } from './helpers/env.js';

const DESKTOP = { width: 1440, height: 900 };
const TABLET = { width: 768, height: 1024 };
const MOBILE = { width: 390, height: 844 };

async function overflowReport(page) {
    return page.evaluate(() => {
        const doc = document.documentElement;
        const overflowing = doc.scrollWidth > doc.clientWidth + 2;
        const sidebar = document.getElementById('operator-sidebar');
        const sidebarBox = sidebar ? sidebar.getBoundingClientRect() : null;
        const main = document.querySelector('main') || document.body;
        const mainBox = main.getBoundingClientRect();
        const overlapping = sidebarBox && window.innerWidth < 1280
            ? false
            : sidebarBox
                ? sidebarBox.right > mainBox.left + 8 && sidebarBox.width > 100
                : false;

        return {
            scrollWidth: doc.scrollWidth,
            clientWidth: doc.clientWidth,
            overflowing,
            innerWidth: window.innerWidth,
            overlappingSidebar: overlapping && window.innerWidth >= 1280,
        };
    });
}

test.describe('Visual evidence and responsive QA', () => {
    test('capture representative TR desktop screenshots', async ({ page }) => {
        await page.setViewportSize(DESKTOP);
        await page.goto('/app');
        await page.getByRole('button', { name: 'TR', exact: true }).click();
        await page.waitForTimeout(600);

        await screenshot(page, 'tr-desktop-dashboard');
        await page.goto('/app/customers');
        await screenshot(page, 'tr-desktop-customers');
        await page.goto('/app/customers/create');
        await screenshot(page, 'tr-desktop-customer-create');
        await page.goto('/app/brands');
        await screenshot(page, 'tr-desktop-brands');
        await page.goto('/app/assets');
        await screenshot(page, 'tr-desktop-digital-assets');
        await page.goto('/app/integrations');
        await screenshot(page, 'tr-desktop-integrations');
        await page.goto('/app/settings');
        await screenshot(page, 'tr-desktop-settings');

        const session = readJson(SESSION_FILE, {});
        if (session.customerId) {
            await page.goto(`/app/customers/${session.customerId}`);
            await screenshot(page, 'tr-desktop-customer-detail');
        }
        if (session.brandId) {
            await page.goto(`/app/brands/${session.brandId}`);
            await screenshot(page, 'tr-desktop-brand-detail');
            await page.getByRole('tab', { name: /Business|İş/ }).click();
            await waitForLivewire(page);
            await screenshot(page, 'tr-desktop-business-context');
            await page.getByRole('button', { name: /Public Discovery|Kamusal keşif|Keşif/i }).click();
            await waitForLivewire(page);
            await screenshot(page, 'tr-desktop-public-discovery');
        }
        if (session.assets?.[0]) {
            const website = session.assets.find((row) => row.type === 'website');
            if (website) {
                await page.goto(`/app/assets/website/${website.id}`);
                await screenshot(page, 'tr-desktop-website-workspace');
            }
        }
    });

    test('tablet and mobile overflow checks', async ({ page }) => {
        const session = readJson(SESSION_FILE, {});
        const targets = [
            { name: 'dashboard', path: '/app' },
            { name: 'customers', path: '/app/customers' },
            { name: 'digital-assets', path: '/app/assets' },
        ];
        if (session.customerId) {
            targets.push({ name: 'customer-detail', path: `/app/customers/${session.customerId}` });
        }
        if (session.brandId) {
            targets.push({ name: 'brand-detail', path: `/app/brands/${session.brandId}` });
        }

        for (const viewport of [
            { name: 'tablet', size: TABLET },
            { name: 'mobile', size: MOBILE },
        ]) {
            await page.setViewportSize(viewport.size);
            for (const target of targets) {
                await page.goto(target.path);
                await waitForLivewire(page);
                await screenshot(page, `${viewport.name}-${target.name}`);
                const report = await overflowReport(page);
                expect.soft(report.overflowing, `${viewport.name} ${target.name} document overflow scrollWidth=${report.scrollWidth} clientWidth=${report.clientWidth}`).toBeFalsy();
            }
        }
    });
});

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
    test.setTimeout(180_000);
    test('capture representative TR desktop screenshots', async ({ page }) => {
        await page.setViewportSize(DESKTOP);
        await page.goto('/');
        await page.getByRole('button', { name: 'TR', exact: true }).click();
        await page.waitForTimeout(600);

        await screenshot(page, 'tr-desktop-dashboard');
        await page.goto('/customers');
        await screenshot(page, 'tr-desktop-customers');
        await page.goto('/customers/create');
        await screenshot(page, 'tr-desktop-customer-create');
        await page.goto('/brands');
        await screenshot(page, 'tr-desktop-brands');
        await page.goto('/assets');
        await screenshot(page, 'tr-desktop-digital-assets');
        await page.goto('/integrations');
        await screenshot(page, 'tr-desktop-integrations');
        await page.goto('/settings');
        await screenshot(page, 'tr-desktop-settings');

        const session = readJson(SESSION_FILE, {});
        if (session.customerId) {
            await page.goto(`/customers/${session.customerId}`);
            await screenshot(page, 'tr-desktop-customer-detail');
        }
        if (session.brandId) {
            await page.goto(`/brands/${session.brandId}`);
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
                await page.goto(`/assets/website/${website.id}`);
                await screenshot(page, 'tr-desktop-website-workspace');
            }
        }

        await page.getByRole('button', { name: 'EN', exact: true }).click();
        await page.waitForTimeout(400);
    });

    test('tablet and mobile overflow checks', async ({ page }) => {
        const session = readJson(SESSION_FILE, {});
        const targets = [
            { name: 'dashboard', path: '/' },
            { name: 'customers', path: '/customers' },
            { name: 'brands', path: '/brands' },
            { name: 'digital-assets', path: '/assets' },
            { name: 'work', path: '/tasks' },
            { name: 'integrations', path: '/integrations' },
            { name: 'settings', path: '/settings' },
        ];
        if (session.customerId) {
            targets.push({ name: 'customer-detail', path: `/customers/${session.customerId}` });
        }
        if (session.brandId) {
            targets.push({ name: 'brand-detail', path: `/brands/${session.brandId}` });
        }
        const website = (session.assets || []).find((row) => row.type === 'website');
        if (website) {
            targets.push({ name: 'website', path: `/assets/website/${website.id}` });
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
                if (report.overflowing) {
                    recordFinding({
                        severity: 'HIGH',
                        surface: `${viewport.name} ${target.name}`,
                        route: target.path,
                        action: 'Document overflow check',
                        observed: `scrollWidth=${report.scrollWidth} clientWidth=${report.clientWidth}`,
                        expected: 'No document-level horizontal overflow. Contained table scrolling is acceptable.',
                        evidence: `.qa-artifacts/screenshots/${viewport.name}-${target.name}.png`,
                        likelySource: 'layout / min-width on operator chrome',
                        fixScope: 'small',
                    });
                }
                expect.soft(report.overflowing, `${viewport.name} ${target.name} document overflow scrollWidth=${report.scrollWidth} clientWidth=${report.clientWidth}`).toBeFalsy();
            }
        }
    });
});

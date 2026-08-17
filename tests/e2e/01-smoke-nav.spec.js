import { test, expect } from '@playwright/test';
import { attachHttpWatcher } from './helpers/httpWatcher.js';
import { assertOperatorSurface, openSidebar, screenshot } from './helpers/pages.js';
import { recordFinding } from './helpers/findings.js';

const SURFACES = [
    { name: 'Dashboard', path: '/app' },
    { name: 'Customers', path: '/app/customers' },
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

test.describe('Smoke crawl — frozen operator surfaces', () => {
    test('sidebar routes render without 404/500', async ({ page }) => {
        const watcher = attachHttpWatcher(page);
        const visited = [];

        await page.goto('/app');
        await expect(page.locator('#operator-sidebar')).toBeVisible();

        for (const surface of SURFACES) {
            await openSidebar(page, surface.name);
            await page.waitForURL(new RegExp(surface.path.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
            const result = await assertOperatorSurface(page, {
                route: surface.path,
                label: surface.name,
                watcher,
            });
            visited.push({
                name: surface.name,
                path: surface.path,
                finalUrl: page.url(),
                ok: result.ok,
                title: result.hints.title,
            });
            expect.soft(result.ok, `${surface.name} should render`).toBeTruthy();
            expect.soft(result.hints.exception).toBeFalsy();
        }

        await screenshot(page, 'smoke-settings');
        const failed = visited.filter((row) => !row.ok);
        if (failed.length) {
            recordFinding({
                id: 'QA-E2E-SMOKE-SUMMARY',
                severity: 'BLOCKER',
                surface: 'sidebar',
                route: '/app',
                action: 'Visit all canonical sidebar routes',
                observed: failed.map((row) => `${row.name} FAIL ${row.finalUrl}`).join('; '),
                expected: 'All frozen operator surfaces render.',
                evidence: 'see fail-* screenshots',
                likelySource: 'route table',
                fixScope: 'small',
            });
        }
    });
});

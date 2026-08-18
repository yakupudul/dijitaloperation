import { test, expect } from '@playwright/test';
import { attachHttpWatcher } from './helpers/httpWatcher.js';
import { assertOperatorSurface, openSidebar, screenshot, waitForLivewire } from './helpers/pages.js';
import { recordFinding } from './helpers/findings.js';

const SURFACES = [
    { name: 'Dashboard', path: '/' },
    { name: 'Customers', path: '/customers' },
    { name: 'Brands', path: '/brands' },
    { name: 'Digital Assets', path: '/assets' },
    { name: 'Files', path: '/files' },
    { name: 'Opportunities', path: '/opportunities' },
    { name: 'Findings', path: '/findings' },
    { name: 'Recommendations', path: '/recommendations' },
    { name: 'Work', path: '/tasks' },
    { name: 'Activity', path: '/activity' },
    { name: 'Integrations', path: '/integrations' },
    { name: 'Settings', path: '/settings' },
];

test.describe('Smoke crawl — frozen operator surfaces', () => {
    test('sidebar routes render without 404/500', async ({ page }) => {
        const watcher = attachHttpWatcher(page);
        const visited = [];

        await page.goto('/');
        await expect(page.locator('#operator-sidebar')).toBeVisible();

        for (const surface of SURFACES) {
            await openSidebar(page, surface.name);
            const escaped = surface.path.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            await page.waitForURL(new RegExp(`${escaped}(?:\\?|#|$)`));
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

            if (!result.ok) {
                await page.goto('/');
                await waitForLivewire(page);
            }
        }

        await screenshot(page, 'smoke-settings');
        const failed = visited.filter((row) => !row.ok);
        if (failed.length) {
            recordFinding({
                id: 'QA-E2E-SMOKE-SUMMARY',
                severity: 'BLOCKER',
                surface: 'sidebar',
                route: '/',
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

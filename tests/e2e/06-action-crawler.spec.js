import { test, expect } from '@playwright/test';
import { attachHttpWatcher } from './helpers/httpWatcher.js';
import { pageHttpHints, screenshot, waitForLivewire } from './helpers/pages.js';
import { recordFinding } from './helpers/findings.js';

const DANGEROUS = /(delete|archive|disconnect|revoke|remove key|remove credential|clear credential|deactivate|destroy|uninstall|send mail|collect now|backfill|authorize|oauth|generate ai|refresh seo|refresh public)/i;

test.describe('Bounded safe action crawler', () => {
    test('follow internal operator links without destructive actions', async ({ page }) => {
        const watcher = attachHttpWatcher(page);
        const visited = new Set();
        const queue = ['/app', '/app/customers', '/app/brands', '/app/assets', '/app/integrations', '/app/settings'];
        const failures = [];
        const limit = 40;

        while (queue.length && visited.size < limit) {
            const next = queue.shift();
            if (!next || visited.has(next)) {
                continue;
            }
            visited.add(next);

            await page.goto(next);
            await waitForLivewire(page);
            const hints = await pageHttpHints(page);
            if (hints.looks404 || hints.looks500 || hints.exception) {
                const evidence = await screenshot(page, `crawler-fail-${visited.size}`);
                failures.push({ url: page.url(), from: next, title: hints.title });
                recordFinding({
                    severity: hints.looks404 ? 'BLOCKER' : 'HIGH',
                    surface: 'action crawler',
                    route: page.url(),
                    action: `Navigate to ${next}`,
                    observed: `title=${hints.title} 404=${hints.looks404} 500=${hints.looks500}`,
                    expected: 'Internal operator URL renders',
                    evidence,
                    likelySource: 'href generation without resource id or missing route',
                    fixScope: 'small',
                });
                continue;
            }

            const hrefs = await page.$$eval('a[href]', (anchors) => anchors.map((a) => a.getAttribute('href') || ''));
            for (const href of hrefs) {
                if (!href.startsWith('/app')) {
                    continue;
                }
                if (href.includes('/download') || href.includes('authorize')) {
                    continue;
                }
                const url = href.split('#')[0];
                if (!visited.has(url) && queue.length + visited.size < limit) {
                    queue.push(url);
                }
            }

            const buttons = page.getByRole('button');
            const count = await buttons.count();
            for (let i = 0; i < Math.min(count, 12); i += 1) {
                const button = buttons.nth(i);
                const name = ((await button.innerText()) || '').trim();
                if (!name || DANGEROUS.test(name)) {
                    continue;
                }
                if (/save|submit|add operator|create/i.test(name)) {
                    continue;
                }
                if (['EN', 'TR', '⋯'].includes(name)) {
                    continue;
                }
            }
        }

        expect.soft(failures, 'crawler 404/500 list').toEqual([]);
        expect(visited.size).toBeGreaterThan(5);
    });
});

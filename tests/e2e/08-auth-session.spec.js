import { test, expect } from '@playwright/test';
import { attachHttpWatcher } from './helpers/httpWatcher.js';
import { assertOperatorSurface, pageHttpHints, screenshot, waitForLivewire } from './helpers/pages.js';
import { recordFinding } from './helpers/findings.js';
import { setVerdict } from './helpers/verdicts.js';
import { BASE_URL, E2E_EMAIL, loadPassword } from './helpers/env.js';

test.describe('Auth, session, and legacy routes', () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    test('guest is denied protected /app routes without an auth loop', async ({ page }) => {
        const watcher = attachHttpWatcher(page);
        await page.goto('/app/customers');
        await waitForLivewire(page);
        expect(page.url()).toMatch(/\/app\/login/);
        const login = await pageHttpHints(page);
        expect(login.looks500).toBeFalsy();
        expect(login.exception).toBeFalsy();

        await page.goto('/app/tasks');
        await waitForLivewire(page);
        expect(page.url()).toMatch(/\/app\/login/);

        const result = await assertOperatorSurface(page, {
            route: '/app/login',
            label: 'Login after guest deny',
            watcher,
        });
        expect(result.ok || page.url().includes('/app/login')).toBeTruthy();
        setVerdict('Login', 'PASS', 'Guest /app routes redirect to /app/login');
    });

    test('legacy /system routes redirect or return 410', async ({ page }) => {
        const loginResponse = await page.goto('/system/login');
        expect(page.url()).toMatch(/\/app\/login/);
        expect(loginResponse?.status() ?? 200).toBeLessThan(500);

        await page.goto('/system');
        const systemPath = new URL(page.url()).pathname;
        expect(systemPath === '/app' || systemPath === '/app/login').toBeTruthy();

        const legacy = await page.goto('/system/customers');
        const status = legacy?.status() ?? 0;
        const body = await page.locator('body').innerText();
        const gone = status === 410 || /410|retired|gone/i.test(body) || /410|retired|gone/i.test(await page.title());
        if (!gone) {
            recordFinding({
                severity: 'HIGH',
                surface: 'legacy /system',
                route: '/system/customers',
                action: 'Visit representative retired /system route',
                observed: `status=${status} title=${await page.title()}`,
                expected: '410 Gone for retired /system/* operator routes',
                evidence: await screenshot(page, 'legacy-system-customers'),
                likelySource: 'routes/web.php legacy /system catch-all',
                fixScope: 'small',
            });
        }
        expect.soft(gone, '/system/* should be 410').toBeTruthy();
    });

    test('/admin remains a separate technical surface', async ({ page }) => {
        const response = await page.goto('/admin/login');
        const status = response?.status() ?? 0;
        const path = new URL(page.url()).pathname;
        expect(status).toBeLessThan(500);
        expect(path.startsWith('/admin')).toBeTruthy();
        expect(path).not.toBe('/app');
        await screenshot(page, 'admin-login-surface');
    });

    test('login, logout, login again, and session persist', async ({ page }) => {
        const password = loadPassword();

        await page.goto('/app/login?locale=en');
        await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();
        await page.locator('input[name="email"]').fill(E2E_EMAIL);
        await page.locator('input[name="password"]').fill(password);
        await page.getByRole('button', { name: 'Sign in' }).click();
        await page.waitForURL((url) => {
            const path = new URL(url).pathname;

            return path.startsWith('/app') && !path.includes('/login');
        }, { timeout: 20_000 });
        await expect(page.locator('#operator-sidebar')).toBeVisible();

        await page.goto('/app/customers');
        await expect(page.locator('#operator-sidebar')).toBeVisible();

        await page.goto('/app/profile');
        await waitForLivewire(page);
        await page.getByRole('button', { name: /Sign out|Çıkış/i }).click();
        await page.waitForURL(/\/app\/login/, { timeout: 20_000 });

        await page.goto('/app/customers');
        expect(page.url()).toMatch(/\/app\/login/);

        await page.locator('input[name="email"]').fill(E2E_EMAIL);
        await page.locator('input[name="password"]').fill(password);
        await page.getByRole('button', { name: 'Sign in' }).click();
        await page.waitForURL((url) => {
            const path = new URL(url).pathname;

            return path.startsWith('/app') && !path.includes('/login');
        }, { timeout: 20_000 });
        await expect(page.locator('#operator-sidebar')).toBeVisible();
        await page.goto('/app');
        await expect(page.locator('#operator-sidebar')).toBeVisible();
        expect(page.url()).not.toMatch(/\/login/);
        setVerdict('Login', 'PASS', `Session persisted after logout/login at ${BASE_URL}`);
    });
});

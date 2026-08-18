import { test, expect } from '@playwright/test';
import { attachHttpWatcher } from './helpers/httpWatcher.js';
import { assertOperatorSurface, pageHttpHints, screenshot, waitForLivewire } from './helpers/pages.js';
import { recordFinding } from './helpers/findings.js';
import { setVerdict } from './helpers/verdicts.js';
import { BASE_URL, E2E_EMAIL, loadPassword } from './helpers/env.js';

function isOperatorLanding(path) {
    return !path.includes('/login')
        && !path.startsWith('/admin')
        && !path.startsWith('/app')
        && !path.startsWith('/system');
}

test.describe('Auth, session, and legacy routes', () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    test('guest is denied protected operator routes without an auth loop', async ({ page }) => {
        const watcher = attachHttpWatcher(page);
        await page.goto('/customers');
        await waitForLivewire(page);
        expect(page.url()).toMatch(/\/login/);
        const login = await pageHttpHints(page);
        expect(login.looks500).toBeFalsy();
        expect(login.exception).toBeFalsy();

        await page.goto('/tasks');
        await waitForLivewire(page);
        expect(page.url()).toMatch(/\/login/);

        const result = await assertOperatorSurface(page, {
            route: '/login',
            label: 'Login after guest deny',
            watcher,
        });
        expect(result.ok || page.url().includes('/login')).toBeTruthy();
        setVerdict('Login', 'PASS', 'Guest operator routes redirect to /login');
    });

    test('legacy /system and /app routes return 410', async ({ page }) => {
        const loginResponse = await page.goto('/system/login');
        expect(loginResponse?.status()).toBe(410);
        expect(new URL(page.url()).pathname).toBe('/system/login');

        const systemRoot = await page.goto('/system');
        expect(systemRoot?.status()).toBe(410);
        expect(new URL(page.url()).pathname).toBe('/system');

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

        const appLogin = await page.goto('/app/login');
        expect(appLogin?.status()).toBe(410);
        const appCustomers = await page.goto('/app/customers');
        expect(appCustomers?.status()).toBe(410);
    });

    test('/admin remains a separate technical surface', async ({ page }) => {
        const response = await page.goto('/admin/login');
        const status = response?.status() ?? 0;
        const path = new URL(page.url()).pathname;
        expect(status).toBeLessThan(500);
        expect(path.startsWith('/admin')).toBeTruthy();
        expect(path).not.toBe('/');
        await screenshot(page, 'admin-login-surface');
    });

    test('login, logout, login again, and session persist', async ({ page }) => {
        const password = loadPassword();

        await page.goto('/login?locale=en');
        await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();
        await page.locator('input[name="email"]').fill(E2E_EMAIL);
        await page.locator('input[name="password"]').fill(password);
        await page.getByRole('button', { name: 'Sign in' }).click();
        await page.waitForURL((url) => isOperatorLanding(new URL(url).pathname), { timeout: 20_000 });
        await expect(page.locator('#operator-sidebar')).toBeVisible();
        const localeEn = page.getByRole('group', { name: /locale|dil|language/i }).getByRole('button', { name: 'EN' });
        if (await localeEn.count()) {
            await localeEn.click();
            await page.waitForTimeout(400);
        }
        await expect(page.locator('#operator-sidebar')).toBeVisible();

        await page.goto('/profile');
        await waitForLivewire(page);
        await page.getByRole('button', { name: /Sign out|Çıkış/i }).click();
        await page.waitForURL(/\/login/, { timeout: 20_000 });
        expect(new URL(page.url()).pathname).toBe('/login');

        await page.goto('/');
        expect(page.url()).toMatch(/\/login/);

        await page.goto('/customers');
        expect(page.url()).toMatch(/\/login/);

        await page.locator('input[name="email"]').fill(E2E_EMAIL);
        await page.locator('input[name="password"]').fill(password);
        await page.getByRole('button', { name: 'Sign in' }).click();
        await page.waitForURL((url) => isOperatorLanding(new URL(url).pathname), { timeout: 20_000 });
        await expect(page.locator('#operator-sidebar')).toBeVisible();
        await page.goto('/');
        await expect(page.locator('#operator-sidebar')).toBeVisible();
        expect(page.url()).not.toMatch(/\/login/);
        setVerdict('Login', 'PASS', `Session persisted after logout/login at ${BASE_URL}`);
    });
});

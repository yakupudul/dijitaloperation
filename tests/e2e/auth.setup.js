import { test as setup, expect } from '@playwright/test';
import { AUTH_STATE, E2E_EMAIL, loadPassword, writeJson, SESSION_FILE } from './helpers/env.js';

setup('authenticate through /app/login', async ({ page }) => {
    const password = loadPassword();

    await page.goto('/app/login?locale=en');
    await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();

    await page.locator('input[name="email"]').fill(E2E_EMAIL);
    await page.locator('input[name="password"]').fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();

    await page.waitForURL(/\/app(\/|$)/, { timeout: 20_000 });
    await expect(page.locator('#operator-sidebar')).toBeVisible();

    const localeEn = page.getByRole('group', { name: /locale|dil/i }).getByRole('button', { name: 'EN' });
    if (await localeEn.count()) {
        await localeEn.click();
        await page.waitForTimeout(500);
    }

    await page.context().storageState({ path: AUTH_STATE });
    writeJson(SESSION_FILE, {
        email: E2E_EMAIL,
        authenticatedAt: new Date().toISOString(),
        landing: page.url(),
    });
});

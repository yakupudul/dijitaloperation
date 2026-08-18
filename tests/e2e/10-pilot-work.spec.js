import { test, expect } from '@playwright/test';
import { waitForLivewire } from './helpers/pages.js';
import { readJson, SESSION_FILE } from './helpers/env.js';
import { taskByTitle } from './helpers/sqlite.js';
import { setVerdict } from './helpers/verdicts.js';

function sessionOrFail() {
    const session = readJson(SESSION_FILE, {});
    expect(session?.customerId, 'golden-path session customer is required').toBeTruthy();
    expect(session?.brandId, 'golden-path session brand is required').toBeTruthy();
    expect(session?.customerName).toBeTruthy();
    expect(session?.brandName).toBeTruthy();

    return session;
}

async function openCapture(page) {
    await page.locator('header').getByRole('button', { name: /Capture|Hızlı kayıt/i }).click();
    await expect(page.getByRole('dialog')).toBeVisible();
    await expect(page.getByRole('heading', { name: /Quick capture|Hızlı kayıt/i })).toBeVisible();
}

async function chooseDialogSelect(page, label, option) {
    const dialog = page.getByRole('dialog');
    const field = dialog.locator('div.space-y-1\\.5').filter({
        has: page.locator('label').filter({ hasText: label }),
    }).first();
    await field.getByRole('button').first().click({ timeout: 8_000 });
    const listbox = page.getByRole('listbox').last();
    await listbox.waitFor({ state: 'visible', timeout: 8_000 });
    const search = listbox.getByPlaceholder(/Search|Ara/i);
    if (await search.count()) {
        await search.fill(option);
    }
    await listbox.getByRole('option', { name: option }).first().click({ timeout: 8_000 });
    await page.keyboard.press('Escape').catch(() => {});
}

async function saveTaskFromCapture(page, title) {
    await page.getByRole('button', { name: /^Task$|^Görev$/ }).click();
    await page.locator('input[wire\\:model="title"]').fill(title);
    await page.getByRole('button', { name: /^Save$|^Kaydet$/ }).click();
}

test.describe('Pilot-critical logout, capture, and work detail', () => {
    test.setTimeout(180_000);

    test('visible Profile Sign out posts logout and denies /app', async ({ page }) => {
        await page.goto('/app/profile?locale=en');
        await waitForLivewire(page);
        await page.getByRole('button', { name: /Sign out|Çıkış/i }).click();
        await page.waitForURL(/\/app\/login/, { timeout: 20_000 });
        expect(new URL(page.url()).pathname).toBe('/app/login');

        await page.goto('/app');
        await page.waitForURL(/\/app\/login/, { timeout: 15_000 });
        expect(page.url()).toMatch(/\/app\/login/);
        setVerdict('Login', 'PASS', 'Visible Profile Sign out POSTs /app/logout');
    });

    test('global and contextual Capture persist Task and Work detail status', async ({ page }) => {
        const session = sessionOrFail();
        const stamp = Date.now();
        const globalTitle = `E2E global capture task ${stamp}`;
        const customerTitle = `E2E customer capture task ${stamp}`;
        const brandTitle = `E2E brand capture task ${stamp}`;

        await page.goto('/app');
        await waitForLivewire(page);
        const localeEn = page.getByRole('group', { name: /locale|dil|language/i }).getByRole('button', { name: 'EN' });
        if (await localeEn.count()) {
            await localeEn.click();
            await waitForLivewire(page);
        }

        await openCapture(page);
        await page.getByRole('button', { name: /^Task$/ }).click();
        await expect(page.getByRole('dialog').getByText('Customer', { exact: true })).toBeVisible();
        await chooseDialogSelect(page, 'Customer', session.customerName);
        await waitForLivewire(page);
        await expect(page.getByRole('dialog').getByText('Brand', { exact: true })).toBeVisible();
        await page.getByRole('dialog').locator('div.space-y-1\\.5').filter({
            has: page.locator('label').filter({ hasText: 'Brand' }),
        }).first().getByRole('button').first().click();
        const brandBox = page.getByRole('listbox').last();
        await brandBox.waitFor({ state: 'visible' });
        const brandLabels = (await brandBox.getByRole('option').allInnerTexts()).join('\n');
        expect(brandLabels).toContain(session.brandName);
        expect(brandLabels).not.toMatch(/Must Not Appear/i);
        await page.keyboard.press('Escape').catch(() => {});
        await chooseDialogSelect(page, 'Brand', session.brandName);
        await page.locator('input[wire\\:model="title"]').fill(globalTitle);
        await page.getByRole('button', { name: /^Save$/ }).click();
        await page.waitForURL(/\/app\/work\/task\/\d+/, { timeout: 20_000 });
        await expect(page.locator('h1')).toContainText(globalTitle);
        await expect(page.locator('body')).not.toContainText('Work item not found');

        const globalTask = taskByTitle(globalTitle);
        expect(globalTask).toBeTruthy();
        expect(String(globalTask.customer_id)).toBe(String(session.customerId));
        expect(String(globalTask.brand_id)).toBe(String(session.brandId));

        await page.getByRole('button', { name: /In progress/i }).click();
        await waitForLivewire(page);
        await page.reload();
        await waitForLivewire(page);
        await expect(page.locator('body')).toContainText(/in progress/i);
        const afterStatus = taskByTitle(globalTitle);
        expect(afterStatus?.status).toBe('in_progress');

        await page.goto('/app/tasks');
        await waitForLivewire(page);
        await page.getByRole('button', { name: /All Work|Tüm işler/i }).click();
        await waitForLivewire(page);
        await expect(page.locator('body')).toContainText(globalTitle);
        await page.locator('tr', { hasText: globalTitle }).getByRole('link', { name: /Open|Aç/i }).click();
        await page.waitForURL(/\/app\/work\/task\/\d+/);
        await expect(page.locator('h1')).toContainText(globalTitle);

        await page.goto(`/app/customers/${session.customerId}`);
        await waitForLivewire(page);
        await openCapture(page);
        await page.getByRole('button', { name: /^Task$/ }).click();
        await expect(page.getByRole('dialog').locator('div.space-y-1\\.5').filter({
            has: page.locator('label').filter({ hasText: 'Customer' }),
        }).first()).toContainText(session.customerName);
        await saveTaskFromCapture(page, customerTitle);
        await page.waitForURL(/\/app\/work\/task\/\d+/, { timeout: 20_000 });
        const customerTask = taskByTitle(customerTitle);
        expect(customerTask).toBeTruthy();
        expect(String(customerTask.customer_id)).toBe(String(session.customerId));

        await page.goto(`/app/customers/${session.customerId}`);
        await waitForLivewire(page);
        await expect(page.locator('body')).toContainText(customerTitle);
        await page.locator('li', { hasText: customerTitle }).getByRole('link', { name: /Open|Aç/i }).click();
        await page.waitForURL(new RegExp(`/app/work/task/${customerTask.id}`));
        await expect(page.locator('h1')).toContainText(customerTitle);

        await page.goto(`/app/brands/${session.brandId}`);
        await waitForLivewire(page);
        await openCapture(page);
        await page.getByRole('button', { name: /^Task$/ }).click();
        await expect(page.getByRole('dialog').locator('div.space-y-1\\.5').filter({
            has: page.locator('label').filter({ hasText: 'Customer' }),
        }).first()).toContainText(session.customerName);
        await expect(page.getByRole('dialog').locator('div.space-y-1\\.5').filter({
            has: page.locator('label').filter({ hasText: 'Brand' }),
        }).first()).toContainText(session.brandName);
        await saveTaskFromCapture(page, brandTitle);
        await page.waitForURL(/\/app\/work\/task\/\d+/, { timeout: 20_000 });
        const brandTask = taskByTitle(brandTitle);
        expect(brandTask).toBeTruthy();
        expect(String(brandTask.customer_id)).toBe(String(session.customerId));
        expect(String(brandTask.brand_id)).toBe(String(session.brandId));

        setVerdict('Work', 'PASS', 'Global and contextual Capture persist Tasks; Work detail and status work');
    });
});

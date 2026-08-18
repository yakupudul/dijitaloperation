import { test, expect } from '@playwright/test';
import { waitForLivewire } from './helpers/pages.js';

const FIXTURE_WEBSITE = 'http://prospect-fixture.moxdop-e2e.test/';

async function switchLocale(page, code) {
    const group = page.getByRole('group', { name: /locale|dil|language/i });
    const button = group.getByRole('button', { name: code });
    if (await button.count()) {
        const alreadySelected = await button.evaluate((el) => el.classList.contains('bg-brand-500'));
        if (! alreadySelected) {
            await button.click();
            await waitForLivewire(page);
        }
        return;
    }
    await page.getByRole('button', { name: code, exact: true }).click();
    await waitForLivewire(page);
}

test.describe('Sales Assistant Prospect golden path', () => {
    test.setTimeout(180_000);

    test('creates, researches, and persists prospect intelligence', async ({ page }) => {
        const title = `E2E Sales Prospect ${Date.now()}`;
        const inquiry = 'Web sitesi ve Google reklamları konusunda destek arıyoruz.';

        await page.goto('/app/prospects?locale=en');
        await waitForLivewire(page);
        await expect(page.locator('h1')).toContainText('Prospects');

        await page.getByRole('link', { name: 'New Prospect' }).click();
        await waitForLivewire(page);
        await expect(page.locator('h1')).toContainText('New Prospect');

        await page.locator('input[wire\\:model="company_name"]').fill(title);
        await page.locator('input[wire\\:model="website_url"]').fill(FIXTURE_WEBSITE);
        await page.locator('textarea[wire\\:model="inquiry"]').fill(inquiry);

        await page.getByRole('button', { name: /^Save$/ }).click();
        await page.waitForURL(/\/app\/prospects\/\d+/, { timeout: 20_000 });
        await waitForLivewire(page);

        await expect(page.locator('h1')).toContainText(title);
        await expect(page.locator('body')).toContainText(inquiry);

        await page.getByRole('button', { name: 'Research Prospect' }).click();
        await page.waitForLoadState('networkidle').catch(() => {});
        await waitForLivewire(page);

        await expect(page.locator('body')).toContainText(/Completed|Tamamlandı/i, { timeout: 45_000 });
        await expect(page.locator('body')).toContainText(/Observed|Gözlemlenen|Public page/i, { timeout: 15_000 });

        await page.getByRole('button', { name: 'Sales Intelligence' }).click();
        await waitForLivewire(page);

        await expect(page.locator('body')).toContainText('Google Ads Management', { timeout: 30_000 });
        await expect(page.locator('body')).toContainText('Website Design');

        await page.getByRole('button', { name: 'Overview' }).click();
        await waitForLivewire(page);

        const statusSelect = page.locator('select[wire\\:model="status"]').first();
        await statusSelect.selectOption('qualified');
        await page.getByRole('button', { name: /^Save$/ }).click();
        await waitForLivewire(page);
        await expect(statusSelect).toHaveValue('qualified');

        await page.reload();
        await waitForLivewire(page);
        await page.getByRole('button', { name: 'Overview' }).click();
        await waitForLivewire(page);
        await expect(page.locator('select[wire\\:model="status"]').first()).toHaveValue('qualified');
    });

    test('TR chrome for Sales prospects navigation', async ({ page }) => {
        await page.goto('/app/prospects');
        await waitForLivewire(page);
        await switchLocale(page, 'TR');
        await expect(page.locator('h1')).toContainText('Potansiyel Müşteriler');
        await expect(page.getByRole('link', { name: 'Yeni Potansiyel Müşteri' })).toBeVisible();
    });
});

test.describe('Sales Prospect responsive layouts', () => {
    test('prospect list at tablet width', async ({ page }) => {
        await page.setViewportSize({ width: 768, height: 900 });
        await page.goto('/app/prospects?locale=en');
        await waitForLivewire(page);
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
        expect(overflow).toBe(false);
    });

    test('prospect create at mobile width', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto('/app/prospects/create?locale=en');
        await waitForLivewire(page);
        await expect(page.getByRole('heading', { name: /New Prospect|Yeni Potansiyel Müşteri/i })).toBeVisible();
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
        expect(overflow).toBe(false);
    });
});

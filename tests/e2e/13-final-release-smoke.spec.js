import { test, expect } from '@playwright/test';
import { waitForLivewire } from './helpers/pages.js';
import {
    countCustomersNamed,
    countRows,
    customerByName,
    prospectByCompany,
    searchProfileByName,
} from './helpers/sqlite.js';

const FIXTURE_WEBSITE = 'http://prospect-fixture.moxdop-e2e.test/';
const INTERNAL_NOTE = 'INTERNAL_SMOKE_NOTES_DO_NOT_SHARE';

async function switchLocale(page, code) {
    const group = page.getByRole('group', { name: /locale|dil|language/i });
    const button = group.getByRole('button', { name: code }).first();
    const target = (await button.count()) ? button : page.getByRole('button', { name: code, exact: true }).first();
    const alreadySelected = await target.evaluate((el) => el.classList.contains('bg-brand-500')).catch(() => false);
    if (alreadySelected) {
        return;
    }

    await target.click();
    await expect(target).toHaveClass(/bg-brand-500/, { timeout: 15_000 });
    await page.waitForLoadState('networkidle').catch(() => {});
    await waitForLivewire(page);
}

async function overflow(page) {
    return page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 2);
}

async function confirmConversionCreatingNew(page) {
    await expect(page.getByRole('button', { name: /Confirm conversion|Dönüşümü onayla/ })).toBeVisible();

    const assetBoxes = page.getByTestId('promotable-asset');
    const assetCount = await assetBoxes.count();
    for (let i = 0; i < assetCount; i++) {
        const box = assetBoxes.nth(i);
        if (await box.isEnabled() && await box.isChecked()) {
            await box.uncheck();
        }
    }
    if (assetCount > 0) {
        await page.waitForLoadState('networkidle').catch(() => {});
        await waitForLivewire(page);
    }

    if (await page.getByTestId('potential-duplicate').count()) {
        const confirm = page.getByTestId('confirm-create-despite-duplicates');
        await confirm.check();
        await expect(confirm).toBeChecked();
        await page.waitForLoadState('networkidle').catch(() => {});
        await waitForLivewire(page);
        await expect(confirm).toBeChecked();
    }

    await page.getByRole('button', { name: /Confirm conversion|Dönüşümü onayla/ }).click();
    await expect(page.getByRole('link', { name: /Open Customer|Müşteriyi Aç/ })).toBeVisible({ timeout: 30_000 });
    await waitForLivewire(page);
}

test.describe('Final autonomous release smoke', () => {
    test.setTimeout(180_000);

    const stamp = Date.now();
    const customerName = `Final Smoke Customer ${stamp}`;
    const brandName = `Final Smoke Brand ${stamp}`;
    const prospectName = `Final Smoke Prospect ${stamp}`;
    const profileName = `Final Smoke Website Intent ${stamp}`;

    test('prospect research, client-safe report, conversion, and duplicate-safe second convert', async ({ page, browser }) => {
        const customersBefore = countRows('customers');
        const brandsBefore = countRows('brands');
        const assetsBefore = countRows('digital_assets');

        await page.goto('/app/prospects?locale=en');
        await waitForLivewire(page);
        await switchLocale(page, 'EN');
        await page.goto('/app/prospects/create?locale=en');
        await waitForLivewire(page);
        await page.locator('input[wire\\:model="company_name"]').fill(prospectName);
        await page.locator('input[wire\\:model="website_url"]').fill(FIXTURE_WEBSITE);
        await page.locator('textarea[wire\\:model="inquiry"]').fill('Web sitesi ve Google reklamları konusunda destek arıyoruz.');
        await page.getByRole('button', { name: /^(Save|Kaydet)$/ }).click();
        await page.waitForURL((url) => /\/app\/prospects\/\d+$/.test(new URL(url).pathname), { timeout: 20_000 });
        await expect(page.locator('h1')).toContainText(prospectName);

        expect(countRows('customers')).toBe(customersBefore);
        expect(countRows('brands')).toBe(brandsBefore);
        expect(countRows('digital_assets')).toBe(assetsBefore);
        expect(prospectByCompany(prospectName)?.converted_customer_id).toBeFalsy();

        await page.getByRole('button', { name: /Research Prospect|Potansiyel Müşteriyi Araştır/ }).click();
        await page.waitForLoadState('networkidle').catch(() => {});
        await waitForLivewire(page);
        await expect(page.locator('body')).toContainText(/Completed|Partial|Tamamlandı|Kısmi/i, { timeout: 45_000 });
        await expect(page.locator('body')).toContainText(/Observed|Gözlemlenen|Public page/i, { timeout: 15_000 });

        await page.getByRole('button', { name: 'Sales Intelligence' }).click();
        await waitForLivewire(page);
        await expect(page.locator('body')).toContainText(/Website Design|Google Ads Management/i, { timeout: 30_000 });
        await expect(page.locator('body')).not.toContainText(/email sequence|outreach sent|WhatsApp automation/i);

        await page.getByRole('button', { name: 'Report' }).click();
        await waitForLivewire(page);
        await page.locator('textarea[wire\\:model="internal_notes"]').fill(INTERNAL_NOTE);
        await page.waitForLoadState('networkidle').catch(() => {});
        await waitForLivewire(page);
        await page.getByRole('button', { name: 'Generate Internal Pre-Analysis' }).click();
        await waitForLivewire(page);
        await page.getByRole('button', { name: 'Generate Client Pre-Analysis' }).click();
        await waitForLivewire(page);
        await expect(page.getByTestId('prospect-internal-report')).toBeVisible();
        await expect(page.getByTestId('prospect-client-report')).not.toContainText(INTERNAL_NOTE);

        await page.getByRole('button', { name: 'Create Share Link' }).click();
        await waitForLivewire(page);
        const shareUrl = (await page.getByTestId('prospect-share-url').innerText()).trim();
        expect(shareUrl).toContain('/prospect-reports/share/');

        const shareContext = await browser.newContext({ storageState: { cookies: [], origins: [] } });
        const sharePage = await shareContext.newPage();
        await sharePage.goto(shareUrl);
        await expect(sharePage.getByTestId('prospect-client-share')).toBeVisible();
        await expect(sharePage.locator('body')).toContainText(prospectName);
        await expect(sharePage.locator('body')).not.toContainText(INTERNAL_NOTE);
        await expect(sharePage.locator('body')).not.toContainText('Convert to Customer');

        const invalid = await sharePage.goto('/prospect-reports/share/not-a-real-token');
        const invalidBody = await sharePage.locator('body').innerText();
        expect((invalid?.status() ?? 200) >= 400 || /not found|denied|404/i.test(invalidBody)).toBeTruthy();
        await shareContext.close();

        await page.getByRole('link', { name: 'Convert to Customer' }).click();
        await waitForLivewire(page);
        await page.locator('input[wire\\:model="customer_name"]').fill(customerName);
        await page.locator('input[wire\\:model="brand_name"]').fill(brandName);
        await confirmConversionCreatingNew(page);
        await expect(page.getByRole('link', { name: 'Open Customer' })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Open Brand' })).toBeVisible();

        const row = prospectByCompany(prospectName);
        expect(row?.converted_customer_id).toBeTruthy();
        expect(row?.converted_brand_id).toBeTruthy();
        expect(row?.converted_at).toBeTruthy();
        expect(countCustomersNamed(customerName)).toBe(1);
        expect(customerByName(customerName)?.id).toBeTruthy();

        await page.goto(`/app/prospects/${row.id}/convert?locale=en`);
        await waitForLivewire(page);
        await expect(page.locator('body')).toContainText(/already converted|Open Customer/i);
        expect(countCustomersNamed(customerName)).toBe(1);
        expect(prospectByCompany(prospectName)?.id).toBeTruthy();
    });

    test('search profile, paid-call default off, intent radar, and signal to prospect', async ({ page }) => {
        const signalsBeforeProfile = countRows('sales_intent_signals');

        await page.goto('/app/prospects/search-profiles?locale=en');
        await waitForLivewire(page);
        await switchLocale(page, 'EN');
        await page.getByRole('link', { name: /New Search Profile|Yeni Arama Profili/ }).click();
        await waitForLivewire(page);
        await page.locator('input[wire\\:model="name"]').fill(profileName);
        await page.locator('textarea[wire\\:model="include_concepts"]').fill('web sitesi yaptırmak');
        await page.locator('textarea[wire\\:model="exclude_concepts"]').fill('nasıl yapılır');
        await page.getByRole('button', { name: /^(Save|Kaydet)$/ }).click();
        await page.waitForURL(/\/app\/prospects\/search-profiles\/\d+/, { timeout: 20_000 });
        await waitForLivewire(page);

        const profile = searchProfileByName(profileName);
        expect(profile?.id).toBeTruthy();
        expect(countRows('sales_intent_signals')).toBe(signalsBeforeProfile);
        await expect(page.locator('body')).toContainText(/PARTIAL \(test fixtures\)|Paid intent discovery is off|paid DataForSEO credits|paid provider credits/i);

        await page.getByRole('button', { name: 'Run Search' }).click();
        await waitForLivewire(page);
        await expect(page.locator('body')).toContainText(/completed|partial/i, { timeout: 30_000 });

        await page.getByRole('link', { name: 'Intent Radar' }).click();
        await waitForLivewire(page);
        await expect(page.locator('body')).toContainText('Web sitesi yaptırmak için bir ajans arıyoruz.');
        await expect(page.locator('body')).toContainText('Web sitesi nasıl yapılır?');

        const highRow = page.locator('[data-testid="intent-signal-row"][data-purchase-stage="high_intent"]').first();
        await expect(highRow).toBeVisible();
        await highRow.getByRole('link', { name: 'Inspect' }).click();
        await waitForLivewire(page);
        await expect(page.locator('body')).toContainText(/intent-fixture|source|DataForSEO|snippet/i);
        await expect(page.getByRole('link', { name: 'Open Source' })).toBeVisible();
        await expect(page.locator('body')).not.toContainText(/email sequence|outreach sent/i);

        await page.getByRole('button', { name: 'Create Prospect' }).click();
        await page.waitForURL(/\/app\/prospects\/\d+/, { timeout: 20_000 });
        await waitForLivewire(page);
        await expect(page.locator('h1')).toContainText(/Anonymous prospect|Final Smoke|ajans/i);
        await page.getByRole('button', { name: /Research Prospect|Potansiyel Müşteriyi Araştır/ }).click();
        await waitForLivewire(page);
        await expect(page.locator('body')).toContainText(/Completed|Partial|Tamamlandı|Kısmi/i, { timeout: 45_000 });
    });

    test('guest cannot convert or edit prospects; representative responsive pages do not overflow', async ({ browser, page }) => {
        const guest = await browser.newContext({ storageState: { cookies: [], origins: [] } });
        const guestPage = await guest.newPage();
        await guestPage.goto('/app/prospects');
        expect(guestPage.url()).toMatch(/\/app\/login/);
        const convert = await guestPage.goto('/app/prospects/1/convert');
        expect(guestPage.url()).toMatch(/\/app\/login/);
        expect((convert?.status() ?? 200) < 500).toBeTruthy();
        await guest.close();

        const routes = [
            '/app',
            '/app/customers',
            '/app/brands',
            '/app/assets',
            '/app/tasks',
            '/app/prospects',
            '/app/prospects/intent-radar',
            '/app/prospects/search-profiles',
            '/app/settings',
        ];
        for (const width of [1440, 768, 390]) {
            await page.setViewportSize({ width, height: 900 });
            for (const route of routes) {
                await page.goto(`${route}?locale=en`);
                await waitForLivewire(page);
                expect(await overflow(page), `${route} @ ${width}`).toBe(false);
            }
        }
    });
});

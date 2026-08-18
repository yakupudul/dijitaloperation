import { test, expect } from '@playwright/test';
import { waitForLivewire } from './helpers/pages.js';
import { countCustomersNamed, customerByName, prospectByCompany } from './helpers/sqlite.js';

const FIXTURE_WEBSITE = 'http://prospect-fixture.moxdop-e2e.test/';
const INTERNAL_NOTE = 'INTERNAL_SALES_STRATEGY_DO_NOT_SHARE';

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

async function createProspect(page, title, website = FIXTURE_WEBSITE) {
    await page.goto('/app/prospects/create?locale=en');
    await waitForLivewire(page);
    await switchLocale(page, 'EN');
    await page.locator('input[wire\\:model="company_name"]').fill(title);
    if (website) {
        await page.locator('input[wire\\:model="website_url"]').fill(website);
    }
    await page.locator('textarea[wire\\:model="inquiry"]').fill('Web sitesi ve Google reklamları konusunda destek arıyoruz.');
    await page.getByRole('button', { name: /^(Save|Kaydet)$/ }).click();
    await page.waitForURL((url) => /\/app\/prospects\/\d+$/.test(new URL(url).pathname), { timeout: 20_000 });
    await waitForLivewire(page);
}

async function researchProspect(page) {
    await page.getByRole('button', { name: /Research Prospect|Potansiyel Müşteriyi Araştır/ }).click();
    await page.waitForLoadState('networkidle').catch(() => {});
    await waitForLivewire(page);
    await expect(page.locator('body')).toContainText(/Completed|Partial|Tamamlandı|Kısmi/i, { timeout: 45_000 });
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

test.describe('Sales Assistant Batch B golden path A', () => {
    test.setTimeout(180_000);

    test('report, client-safe share, and convert to customer', async ({ page, browser }) => {
        const title = `E2E Batch B Prospect ${Date.now()}`;
        await createProspect(page, title);
        await researchProspect(page);

        await page.getByRole('button', { name: 'Sales Intelligence' }).click();
        await waitForLivewire(page);
        await expect(page.locator('body')).toContainText('Website Design', { timeout: 30_000 });

        await page.getByRole('button', { name: 'Report' }).click();
        await waitForLivewire(page);
        await expect(page.getByTestId('prospect-internal-report')).toBeVisible();
        await expect(page.getByTestId('prospect-client-report')).toBeVisible();

        await page.locator('textarea[wire\\:model="internal_notes"]').fill(INTERNAL_NOTE);
        await page.getByRole('button', { name: 'Generate Internal Pre-Analysis' }).click();
        await waitForLivewire(page);
        await page.getByRole('button', { name: 'Generate Client Pre-Analysis' }).click();
        await waitForLivewire(page);

        await expect(page.getByTestId('prospect-client-report')).not.toContainText(INTERNAL_NOTE);
        await page.getByRole('button', { name: 'Create Share Link' }).click();
        await waitForLivewire(page);

        const shareUrl = (await page.getByTestId('prospect-share-url').innerText()).trim();
        expect(shareUrl).toContain('/prospect-reports/share/');

        const shareContext = await browser.newContext();
        const sharePage = await shareContext.newPage();
        await sharePage.goto(shareUrl);
        await expect(sharePage.getByTestId('prospect-client-share')).toBeVisible();
        await expect(sharePage.locator('body')).toContainText(title);
        await expect(sharePage.locator('body')).not.toContainText(INTERNAL_NOTE);
        await expect(sharePage.locator('body')).not.toContainText('Do not share this sales strategy');
        await expect(sharePage.locator('body')).not.toContainText('Convert to Customer');
        await shareContext.close();

        await page.getByRole('link', { name: 'Convert to Customer' }).click();
        await waitForLivewire(page);
        await confirmConversionCreatingNew(page);

        await expect(page.getByRole('link', { name: 'Open Customer' })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Open Brand' })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Convert to Customer' })).toHaveCount(0);

        const row = prospectByCompany(title);
        expect(row?.converted_customer_id).toBeTruthy();
        expect(row?.converted_brand_id).toBeTruthy();
        const customer = customerByName(title);
        expect(customer?.id).toBeTruthy();
        expect(countCustomersNamed(title)).toBe(1);

        await page.goto(`/app/prospects/${row.id}/convert?locale=en`);
        await waitForLivewire(page);
        await expect(page.locator('body')).toContainText(/already converted|Open Customer/i);
        expect(countCustomersNamed(title)).toBe(1);

        await page.getByRole('link', { name: 'Open Customer' }).first().click();
        await waitForLivewire(page);
        await expect(page.locator('h1')).toContainText(title);
    });
});

test.describe('Sales Assistant Batch B golden path B', () => {
    test.setTimeout(180_000);

    test('search profile, intent radar, signal to prospect, research', async ({ page }) => {
        const profileName = `E2E Website Intent ${Date.now()}`;

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

        await expect(page.locator('body')).toContainText('web sitesi yaptırmak');
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

        await expect(page.locator('body')).toContainText('Web sitesi yaptırmak için bir ajans arıyoruz.');
        await expect(page.locator('body')).toContainText(/intent-fixture|source|DataForSEO|snippet/i);
        await expect(page.locator('body')).toContainText(/Anonymous|unknown/i);
        await expect(page.getByRole('link', { name: 'Open Source' })).toBeVisible();

        await page.getByRole('button', { name: 'Create Prospect' }).click();
        await page.waitForURL(/\/app\/prospects\/\d+/, { timeout: 20_000 });
        await waitForLivewire(page);
        await expect(page.locator('h1')).toContainText(/Anonymous prospect/i);
        await expect(page.locator('body')).toContainText('ajans arıyoruz');

        await researchProspect(page);
        await page.getByRole('button', { name: 'Sales Intelligence' }).click();
        await waitForLivewire(page);
        await expect(page.locator('body')).toContainText(/Website Design|Google Ads Management/i, { timeout: 30_000 });
        await expect(page.locator('body')).not.toContainText(/WhatsApp|email sequence|outreach sent/i);
    });
});

test.describe('Sales Assistant Batch B duplicate conversion', () => {
    test.setTimeout(180_000);

    test('duplicate warning and existing customer reuse', async ({ page }) => {
        const name = `E2E Dup Conversion ${Date.now()}`;
        await createProspect(page, name, null);
        await page.getByRole('link', { name: 'Convert to Customer' }).click();
        await waitForLivewire(page);
        await page.getByRole('button', { name: 'Confirm conversion' }).click();
        await page.waitForURL((url) => /\/app\/prospects\/\d+$/.test(new URL(url).pathname), { timeout: 20_000 });
        await waitForLivewire(page);
        await expect(page.getByRole('link', { name: 'Open Customer' })).toBeVisible();
        expect(countCustomersNamed(name)).toBe(1);

        await createProspect(page, name, null);
        await page.getByRole('link', { name: 'Convert to Customer' }).click();
        await waitForLivewire(page);
        await expect(page.getByTestId('potential-duplicate')).toBeVisible();
        await expect(page.locator('body')).toContainText(/Potential Duplicate|Olası Mükerrer Kayıt/);

        const customerSelect = page.locator('select[wire\\:model\\.live="existing_customer_id"]');
        if (await customerSelect.count()) {
            const value = await customerSelect.locator('option').nth(1).getAttribute('value');
            if (value) {
                await customerSelect.selectOption(value);
            }
        }
        const brandSelect = page.locator('select[wire\\:model\\.live="existing_brand_id"]');
        if (await brandSelect.count()) {
            const value = await brandSelect.locator('option').nth(1).getAttribute('value');
            if (value) {
                await brandSelect.selectOption(value);
            }
        }
        await page.getByRole('button', { name: 'Confirm conversion' }).click();
        await page.waitForURL((url) => /\/app\/prospects\/\d+$/.test(new URL(url).pathname), { timeout: 20_000 });
        await waitForLivewire(page);
        await expect(page.getByRole('link', { name: /Open Customer|Müşteriyi Aç/ })).toBeVisible();
        expect(countCustomersNamed(name)).toBe(1);
    });
});

test.describe('Sales Assistant Batch B chrome', () => {
    test('TR labels for radar and profiles', async ({ page }) => {
        await page.goto('/app/prospects');
        await waitForLivewire(page);
        await switchLocale(page, 'TR');
        await expect(page.getByRole('link', { name: 'Niyet Radarı' })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Arama Profilleri' })).toBeVisible();
        await page.getByRole('link', { name: 'Niyet Radarı' }).click();
        await waitForLivewire(page);
        await expect(page.locator('h1')).toContainText('Niyet Radarı');
    });

    test('intent radar and search profiles at 768 and 390', async ({ page }) => {
        for (const width of [768, 390]) {
            await page.setViewportSize({ width, height: 900 });
            await page.goto('/app/prospects/intent-radar?locale=en');
            await waitForLivewire(page);
            const overflowRadar = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
            expect(overflowRadar).toBe(false);

            await page.goto('/app/prospects/search-profiles?locale=en');
            await waitForLivewire(page);
            const overflowProfiles = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
            expect(overflowProfiles).toBe(false);
        }
    });
});

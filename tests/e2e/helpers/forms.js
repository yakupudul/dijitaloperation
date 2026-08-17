/**
 * Alpine combobox used by x-ta.form.select / multi-select.
 */

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} label
 */
export function fieldByLabel(page, label) {
    return page.locator('div.space-y-1\\.5').filter({
        has: page.locator('label').filter({ hasText: label }),
    }).first();
}

async function closeOverlays(page) {
    await page.keyboard.press('Escape').catch(() => {});
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} label
 * @param {string} option
 */
export async function chooseSelect(page, label, option) {
    await closeOverlays(page);
    const field = fieldByLabel(page, label);
    await field.getByRole('button').first().click({ timeout: 5_000 });
    const listbox = page.getByRole('listbox').last();
    await listbox.waitFor({ state: 'visible', timeout: 5_000 });
    const search = listbox.getByPlaceholder('Search…');

    if (await search.count()) {
        await search.fill(option);
    }

    await listbox.getByRole('option', { name: option }).first().click({ timeout: 5_000 });
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} label
 */
export async function inspectSelect(page, label) {
    const field = fieldByLabel(page, label);
    if (!(await field.count())) {
        return { label, present: false };
    }

    const helper = ((await field.locator('p.text-xs').first().textContent().catch(() => '')) || '').trim();
    const hasSearchbox = (await field.getByRole('searchbox').count()) > 0;
    const hasButton = (await field.getByRole('button').count()) > 0;

    if (hasSearchbox && !hasButton) {
        return {
            label,
            present: true,
            helper,
            searchable: true,
            optionCount: null,
            sample: [],
            allowCustomHint: helper.toLowerCase().includes('enter'),
            classification: 'MULTISELECT',
        };
    }

    await closeOverlays(page);
    await field.getByRole('button').first().click({ timeout: 5_000 });
    const listbox = page.getByRole('listbox').last();
    await listbox.waitFor({ state: 'visible', timeout: 5_000 }).catch(() => {});
    const searchable = (await listbox.getByPlaceholder('Search…').count()) > 0;
    const optionCount = await listbox.getByRole('option').count();
    const sample = [];
    const options = listbox.getByRole('option');
    const limit = Math.min(optionCount, 5);

    for (let i = 0; i < limit; i += 1) {
        sample.push((await options.nth(i).innerText()).trim());
    }

    await closeOverlays(page);

    let classification = 'CONTROLLED_SELECT';
    if (searchable) {
        classification = 'SEARCHABLE_SELECT';
    }
    if (helper.toLowerCase().includes('enter') || helper.toLowerCase().includes('custom')) {
        classification = 'SUSPICIOUS_FREE_TEXT';
    }

    return {
        label,
        present: true,
        helper,
        searchable,
        optionCount,
        sample,
        allowCustomHint: helper.toLowerCase().includes('enter'),
        classification,
    };
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} label
 * @param {string} option
 */
export async function chooseMultiSelect(page, label, option) {
    await closeOverlays(page);
    const field = fieldByLabel(page, label);
    const search = field.getByRole('searchbox').first();
    await search.click({ timeout: 5_000 });
    await search.fill(option);
    await page.getByRole('listbox').last().getByRole('option', { name: option }).first().click({ timeout: 5_000 });
    await closeOverlays(page);
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} label
 */
export async function safeInspectSelect(page, label) {
    try {
        return await inspectSelect(page, label);
    } catch (error) {
        await page.keyboard.press('Escape').catch(() => {});

        return { label, present: false, error: error.message };
    }
}

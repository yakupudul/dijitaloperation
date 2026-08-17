/**
 * Alpine combobox used by x-ta.form.select / multi-select.
 * Options are buttons inside role=listbox (role=option is present).
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

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} label
 * @param {string} option
 */
export async function chooseSelect(page, label, option) {
    const field = fieldByLabel(page, label);
    await field.getByRole('button').first().click();
    const listbox = page.getByRole('listbox').last();
    const search = listbox.getByPlaceholder('Search…');

    if (await search.count()) {
        await search.fill(option);
    }

    const named = listbox.getByRole('option', { name: option });
    if (await named.count()) {
        await named.first().click();
        return;
    }

    await listbox.locator('button').filter({ hasText: option }).first().click();
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} label
 */
export async function inspectSelect(page, label) {
    const field = fieldByLabel(page, label);
    const exists = await field.count();
    if (!exists) {
        return { label, present: false };
    }

    const helper = (await field.locator('p.text-xs').first().textContent().catch(() => ''))?.trim() || '';
    await field.getByRole('button').first().click();
    const listbox = page.getByRole('listbox').last();
    const searchable = (await listbox.getByPlaceholder('Search…').count()) > 0;
    const customHint = (await listbox.getByText(/Use “/).count()) > 0;
    const optionCount = await listbox.getByRole('option').count();
    const sample = [];
    const options = listbox.getByRole('option');
    const limit = Math.min(optionCount, 8);

    for (let i = 0; i < limit; i += 1) {
        sample.push((await options.nth(i).innerText()).trim());
    }

    await page.keyboard.press('Escape');

    let classification = 'CONTROLLED_SELECT';
    if (searchable && optionCount > 12) {
        classification = 'SEARCHABLE_SELECT';
    }
    if (helper.toLowerCase().includes('enter') || helper.toLowerCase().includes('custom') || customHint) {
        classification = 'SEARCHABLE_SELECT';
    }

    return {
        label,
        present: true,
        helper,
        searchable,
        optionCount,
        sample,
        allowCustomHint: customHint,
        classification,
    };
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} label
 * @param {string} option
 */
export async function chooseMultiSelect(page, label, option) {
    const field = fieldByLabel(page, label);
    await field.getByRole('button').first().click();
    const listbox = page.getByRole('listbox').last();
    const search = listbox.getByPlaceholder('Search…');

    if (await search.count()) {
        await search.fill(option);
    }

    await listbox.locator('button').filter({ hasText: option }).first().click();
    await page.keyboard.press('Escape');
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

export async function inspectNativeSelect(page, label) {
    const select = page.getByLabel(label, { exact: true });
    if (!(await select.count())) {
        return { label, present: false };
    }

    const tag = await select.evaluate((el) => el.tagName.toLowerCase());
    if (tag !== 'select') {
        return { label, present: true, tag, classification: 'OTHER' };
    }

    const options = await select.locator('option').allTextContents();

    return {
        label,
        present: true,
        tag: 'select',
        optionCount: options.length,
        sample: options.slice(0, 8).map((text) => text.trim()),
        classification: 'CONTROLLED_SELECT',
    };
}

import { recordFinding } from './findings.js';
import { SCREENSHOTS_DIR } from './env.js';
import path from 'node:path';

const EXCEPTION_MARKERS = [
    'Whoops, looks like something went wrong',
    'Illuminate\\',
    'SQLSTATE',
    'Symfony\\Component\\HttpKernel\\Exception',
    'Not Found',
    '404',
    'Server Error',
    '500',
];

/**
 * @param {import('@playwright/test').Page} page
 */
export async function waitForLivewire(page) {
    await page.waitForLoadState('domcontentloaded');
    await page.locator('#nprogress').waitFor({ state: 'hidden', timeout: 5000 }).catch(() => {});
}

/**
 * @param {import('@playwright/test').Page} page
 */
export async function isLaravelExceptionPage(page) {
    const body = (await page.locator('body').innerText().catch(() => '')) || '';
    const title = await page.title();

    if (/server error|not found|exception/i.test(title) && /laravel/i.test(body)) {
        return true;
    }

    return body.includes('Whoops, looks like something went wrong')
        || (body.includes('Illuminate\\') && body.includes('Stack trace'));
}

/**
 * @param {import('@playwright/test').Page} page
 */
export async function pageHttpHints(page) {
    const body = ((await page.locator('body').innerText().catch(() => '')) || '').slice(0, 4000);
    const title = await page.title();
    const url = page.url();
    const looks404 = /404|not found/i.test(title) || /^\s*404\b/m.test(body) || /not found/i.test(body.slice(0, 400));
    const looks500 = /500|server error/i.test(title) || /server error/i.test(body.slice(0, 400));
    const exception = await isLaravelExceptionPage(page);

    return { url, title, looks404, looks500, exception, bodyPreview: body.slice(0, 500) };
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} name
 */
export async function screenshot(page, name) {
    const file = path.join(SCREENSHOTS_DIR, `${name}.png`);
    await page.screenshot({ path: file, fullPage: true, animations: 'disabled' });

    return file;
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {{ route: string, label: string, watcher?: { documentFailures: () => object[] } }} ctx
 */
export async function assertOperatorSurface(page, ctx) {
    const hints = await pageHttpHints(page);
    const docFails = ctx.watcher?.documentFailures?.() || [];
    const failed = hints.looks404 || hints.looks500 || hints.exception || docFails.length > 0;

    if (failed) {
        const evidence = await screenshot(page, `fail-${ctx.label.replace(/\s+/g, '-').toLowerCase()}`);
        recordFinding({
            severity: hints.looks404 ? 'BLOCKER' : 'HIGH',
            surface: ctx.label,
            route: hints.url || ctx.route,
            action: `Visit ${ctx.label}`,
            observed: hints.looks404
                ? `Page rendered 404/not found (${hints.title})`
                : hints.looks500 || hints.exception
                    ? `Exception/500 page (${hints.title})`
                    : `HTTP failure ${JSON.stringify(docFails)}`,
            expected: 'Authenticated operator surface renders without 404/500.',
            evidence,
            likelySource: 'Route or Livewire page renderer',
            fixScope: 'small',
        });
    }

    return { ok: !failed, hints };
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} name
 */
export async function openSidebar(page, name) {
    const sidebar = page.locator('#operator-sidebar');
    if (!(await sidebar.isVisible().catch(() => false))) {
        await page.goto('/app');
        await waitForLivewire(page);
    }

    const link = page.locator('#operator-sidebar a').filter({ hasText: name }).first();
    await link.click();
    await waitForLivewire(page);
}

export { EXCEPTION_MARKERS };

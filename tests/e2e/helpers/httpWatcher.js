import { appendJsonl, WATCHER_FILE } from './env.js';

const HARMLESS = [
    'Failed to load resource: the server responded with a status of 404 ()',
    'cdn.tailwindcss.com',
    'Download the React DevTools',
    'chrome-extension://',
    'net::ERR_BLOCKED_BY_CLIENT',
    'favicon.ico',
];

function isHarmless(text) {
    return HARMLESS.some((needle) => text.includes(needle));
}

function isLivewire(url) {
    return url.includes('/livewire/');
}

/**
 * @param {import('@playwright/test').Page} page
 * @returns {{ events: object[], snapshot: () => object[] }}
 */
export function attachHttpWatcher(page) {
    const events = [];

    const push = (event) => {
        events.push(event);
        appendJsonl(WATCHER_FILE, { ...event, at: new Date().toISOString(), page: page.url() });
    };

    page.on('response', (response) => {
        const url = response.url();
        const status = response.status();
        const type = response.request().resourceType();
        const method = response.request().method();

        if (type === 'document' && (status === 404 || status >= 500)) {
            push({
                kind: status === 404 ? 'document-404' : 'document-5xx',
                status,
                method,
                url,
                originating: page.url(),
            });
        }

        if (isLivewire(url) && status >= 400) {
            push({
                kind: 'livewire-failed',
                status,
                method,
                url,
                originating: page.url(),
            });
        }

        if ((type === 'xhr' || type === 'fetch') && status >= 400 && !url.includes('favicon')) {
            push({
                kind: 'xhr-failed',
                status,
                method,
                url,
                originating: page.url(),
            });
        }
    });

    page.on('pageerror', (error) => {
        push({
            kind: 'unhandled-exception',
            message: error.message,
            originating: page.url(),
        });
    });

    page.on('console', (msg) => {
        if (msg.type() !== 'error') {
            return;
        }

        const text = msg.text();
        if (isHarmless(text)) {
            return;
        }

        push({
            kind: 'console-error',
            message: text,
            originating: page.url(),
        });
    });

    return {
        events,
        snapshot: () => [...events],
        documentFailures: () => events.filter((event) => event.kind === 'document-404' || event.kind === 'document-5xx'),
        livewireFailures: () => events.filter((event) => event.kind === 'livewire-failed'),
    };
}

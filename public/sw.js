/*
 * MoxDOP service worker: phone / browser notifications (Genel işler › Telefona bildirim aç).
 * MoxDOP sends an empty Web Push; the text comes from /push/latest with the user's session.
 * No offline cache: the app always loads from the server.
 */
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

const PUSH_CACHE = 'moxdop-push';

async function lastShownId() {
    try {
        const response = await (await caches.open(PUSH_CACHE)).match('/__push-last');
        return response ? (parseInt(await response.text(), 10) || 0) : 0;
    } catch (error) {
        return 0;
    }
}

async function rememberShown(id) {
    try {
        await (await caches.open(PUSH_CACHE)).put('/__push-last', new Response(String(id)));
    } catch (error) {
        // No cache storage: the newest push is still shown.
    }
}

// An app-like notification: the Moximu mark as the large icon, a one-colour silhouette for the status bar
// (Android paints the badge white, so a full-colour square showed as a blank block), the time, and critical ones
// stay until opened. Inbox messages of one chat share a tag; a silent one (night, a chat's next message) neither
// rings nor vibrates.
// Kasa sesi (2026-11-14): a notification with a sound (a paid order 'kasa', a free application 'zil') is played by
// the panel when it is open on the screen; the notification then comes quietly so the phone does not ring twice.
// With the app closed or in the background the phone's own notification sound rings (no browser lets a web
// notification choose its sound).
async function playOnOpenPanel(sound) {
    if (!sound) {
        return false;
    }
    try {
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        const visible = windows.filter((client) => client.visibilityState === 'visible');
        for (const client of visible) {
            client.postMessage({ type: 'moxdop-sound', sound: sound });
        }
        return visible.length > 0;
    } catch (error) {
        return false;
    }
}

async function show(data, renotify) {
    const critical = data.severity === 'critical';
    const played = data.silent === true ? false : await playOnOpenPanel(data.sound);
    const silent = data.silent === true || played;
    const kasa = data.sound === 'kasa';
    return self.registration.showNotification(data.title, {
        body: data.body,
        tag: data.tag,
        icon: '/images/app/icon-192.png?v=2',
        badge: '/images/app/badge-96.png?v=2',
        timestamp: data.at ? Date.parse(data.at) || Date.now() : Date.now(),
        renotify: renotify && !silent,
        silent: silent,
        requireInteraction: critical || kasa,
        vibrate: silent ? [] : (critical ? [200, 100, 200, 100, 200] : (kasa ? [60, 40, 60, 40, 300] : [120, 60, 120])),
        actions: [{ action: 'open', title: 'Aç' }, { action: 'dismiss', title: 'Kapat' }],
        data: { url: data.url },
    });
}

self.addEventListener('push', (event) => {
    event.waitUntil((async () => {
        let data = { title: 'MoxDOP', body: 'Yeni önemli iş var.', url: '/work', tag: 'moxdop', items: [] };
        const after = await lastShownId();
        try {
            const response = await fetch('/push/latest?after=' + after, { credentials: 'include', headers: { 'Accept': 'application/json' } });
            if (response.ok) {
                data = Object.assign(data, await response.json());
            }
        } catch (error) {
            // Offline or signed out: show the generic line, the click opens Genel işler.
        }
        // Every push this device has not shown yet (two chats in the same second both appear); when a late wake
        // finds nothing new, the newest one is shown again quietly under its own tag (a push must show something).
        const items = Array.isArray(data.items) ? data.items : [];
        if (items.length === 0) {
            await show(after > 0 && data.id && data.id <= after ? Object.assign({}, data, { silent: true }) : data, after === 0 || !data.id || data.id > after);
        }
        for (const item of items) {
            await show(item, true);
        }
        const newest = items.length > 0 ? items[items.length - 1].id : data.id;
        if (newest) {
            await rememberShown(newest);
        }
    })());
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    if (event.action === 'dismiss') {
        return;
    }
    const url = new URL((event.notification.data && event.notification.data.url) || '/work', self.location.origin).href;
    event.waitUntil((async () => {
        // The page reads this on load too: phones (iOS above all) often reopen the app on its home page and ignore
        // the address given to openWindow / navigate, so the app itself goes on to the notification's page.
        try {
            const cache = await caches.open(PUSH_CACHE);
            await cache.put('/__push-target', new Response(JSON.stringify({ url, at: Date.now() })));
        } catch (error) {
            // No cache storage: the direct navigation below still works on most browsers.
        }
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        for (const client of windows) {
            if ('focus' in client) {
                try {
                    await client.focus();
                } catch (error) {
                    // Focus is not allowed everywhere; the page still gets the message below.
                }
                client.postMessage({ type: 'moxdop-push-open', url });
                if ('navigate' in client) {
                    try {
                        return await client.navigate(url);
                    } catch (error) {
                        // An uncontrolled page cannot be navigated from here; it follows the message instead.
                    }
                }
                return;
            }
        }
        return self.clients.openWindow(url);
    })());
});

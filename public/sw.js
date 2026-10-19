/*
 * MoxDOP service worker: phone / browser notifications (Genel işler › Telefona bildirim aç).
 * MoxDOP sends an empty Web Push; the text comes from /push/latest with the user's session.
 * No offline cache: the app always loads from the server.
 */
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
    event.waitUntil((async () => {
        let data = { title: 'MoxDOP', body: 'Yeni önemli iş var.', url: '/work', tag: 'moxdop' };
        try {
            const response = await fetch('/push/latest', { credentials: 'include', headers: { 'Accept': 'application/json' } });
            if (response.ok) {
                data = Object.assign(data, await response.json());
            }
        } catch (error) {
            // Offline or signed out: show the generic line, the click opens Genel işler.
        }
        await self.registration.showNotification(data.title, {
            body: data.body,
            tag: data.tag,
            icon: '/images/app/icon-192.png',
            badge: '/images/app/icon-192.png',
            data: { url: data.url },
        });
    })());
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = new URL((event.notification.data && event.notification.data.url) || '/work', self.location.origin).href;
    event.waitUntil((async () => {
        // The page reads this on load too: phones (iOS above all) often reopen the app on its home page and ignore
        // the address given to openWindow / navigate, so the app itself goes on to the notification's page.
        try {
            const cache = await caches.open('moxdop-push');
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

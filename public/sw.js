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
    const url = (event.notification.data && event.notification.data.url) || '/work';
    event.waitUntil((async () => {
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        for (const client of windows) {
            if ('focus' in client) {
                await client.focus();
                if ('navigate' in client) {
                    return client.navigate(url);
                }
                return;
            }
        }
        return self.clients.openWindow(url);
    })());
});

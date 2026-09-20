/* =====================================================
   sw.js — Service Worker
   Caches the static shell so repeat visits paint without waiting on the
   network. Anything personal deliberately stays out of the cache: pages and
   API responses are user-specific and this app runs on shared devices, so a
   cached page could be shown to the next person who opens the shortcut.
===================================================== */

// Bump on deploy to retire the previous cache generation.
const CACHE = 'dayflow-static-v2';

// Path prefix the app is served from ("" at a domain root, "/DayFlow" under a
// subfolder). Derived from the worker's own URL so no build step is needed.
const BASE = new URL('.', self.location).pathname.replace(/\/$/, '');

const SHELL = [
    `${BASE}/assets/css/fonts.css`,
    `${BASE}/assets/css/app.css`,
    `${BASE}/assets/css/components.css`,
    `${BASE}/assets/js/app.js`,
    `${BASE}/assets/fonts/inter-latin-400.woff2`,
    `${BASE}/assets/fonts/plexthai-thai-400.woff2`,
    `${BASE}/offline.html`,
];

self.addEventListener('install', event => {
    event.waitUntil((async () => {
        const cache = await caches.open(CACHE);
        // One bad entry must not fail the whole install.
        await Promise.all(SHELL.map(url => cache.add(url).catch(() => null)));
        await self.skipWaiting();
    })());
});

self.addEventListener('activate', event => {
    event.waitUntil((async () => {
        const names = await caches.keys();
        await Promise.all(names.filter(n => n !== CACHE).map(n => caches.delete(n)));
        await self.clients.claim();
    })());
});

self.addEventListener('fetch', event => {
    const request = event.request;
    if (request.method !== 'GET') return;

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;

    // Never touch API traffic, uploads or share links — all user-specific.
    if (url.pathname.startsWith(`${BASE}/api/`)
        || url.pathname.startsWith(`${BASE}/uploads/`)
        || url.pathname.startsWith(`${BASE}/shared/`)) {
        return;
    }

    // Versioned static assets: serve from cache, fetch once, keep.
    if (url.pathname.startsWith(`${BASE}/assets/`)) {
        event.respondWith(cacheFirst(request));
        return;
    }

    // Pages: always go to the network so nobody sees another session's HTML.
    // Only a failed navigation falls back to the offline notice.
    if (request.mode === 'navigate') {
        event.respondWith(networkWithOfflineFallback(request));
    }
});

async function cacheFirst(request) {
    const cache = await caches.open(CACHE);
    const hit = await cache.match(request);
    if (hit) return hit;

    try {
        const response = await fetch(request);
        if (response.ok) cache.put(request, response.clone());
        return response;
    } catch (err) {
        // A versioned URL that is not cached and cannot be fetched has no
        // sensible substitute; let the request fail as it normally would.
        throw err;
    }
}

async function networkWithOfflineFallback(request) {
    try {
        return await fetch(request);
    } catch (err) {
        const cache = await caches.open(CACHE);
        return (await cache.match(`${BASE}/offline.html`))
            || new Response('ออฟไลน์', { status: 503, headers: { 'Content-Type': 'text/plain; charset=utf-8' } });
    }
}

/* =====================================================
   Push notifications
   Pushes carry no payload on purpose: the push service never sees the
   content. On wake-up the worker asks the app what to show, using the
   viewer's own session cookie.
===================================================== */

self.addEventListener('push', event => {
    event.waitUntil((async () => {
        let items = [];

        try {
            const response = await fetch(`${BASE}/api/push/pending`, {
                credentials: 'include',
                headers: { 'Accept': 'application/json' },
            });
            if (response.ok) {
                items = (await response.json()).items || [];
            }
        } catch (err) {
            // Offline, or the session has expired: fall through to the
            // generic notice below rather than showing nothing at all.
        }

        if (items.length === 0) {
            await self.registration.showNotification('DayFlow', {
                body: 'เปิดแอปเพื่อดูรายการที่ต้องทำ',
                icon: `${BASE}/assets/icons/icon-192.png`,
                badge: `${BASE}/assets/icons/icon-192.png`,
                tag: 'dayflow-generic',
                data: { url: `${BASE}/` },
            });
            return;
        }

        // A tag per item means a repeat push replaces the old notification
        // rather than stacking another copy of it.
        await Promise.all(items.slice(0, 3).map(item =>
            self.registration.showNotification(item.title, {
                body: item.body || '',
                icon: `${BASE}/assets/icons/icon-192.png`,
                badge: `${BASE}/assets/icons/icon-192.png`,
                tag: item.tag || 'dayflow',
                data: { url: item.url || `${BASE}/` },
            })
        ));
    })());
});

self.addEventListener('notificationclick', event => {
    event.notification.close();
    const target = event.notification.data && event.notification.data.url;

    event.waitUntil((async () => {
        const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });

        // Reuse an open DayFlow tab when there is one.
        for (const client of clients) {
            if (client.url.startsWith(self.location.origin) && 'focus' in client) {
                if (target && 'navigate' in client) await client.navigate(target);
                return client.focus();
            }
        }

        if (self.clients.openWindow) {
            return self.clients.openWindow(target || `${BASE}/`);
        }
    })());
});

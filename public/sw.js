/* =====================================================
   sw.js — Service Worker
   Caches the static shell so repeat visits paint without waiting on the
   network. Anything personal deliberately stays out of the cache: pages and
   API responses are user-specific and this app runs on shared devices, so a
   cached page could be shown to the next person who opens the shortcut.
===================================================== */

// Bump on deploy to retire the previous cache generation.
const CACHE = 'dayflow-static-v5';

// Path prefix the app is served from ("" at a domain root, "/DayFlow" under a
// subfolder). Derived from the worker's own URL so no build step is needed.
const BASE = new URL('.', self.location).pathname.replace(/\/$/, '');

const SHELL = [
    `${BASE}/assets/css/fonts.css`,
    `${BASE}/assets/css/tokens.css`,
    `${BASE}/assets/css/base.css`,
    `${BASE}/assets/css/components.css`,
    `${BASE}/assets/css/shell.css`,
    `${BASE}/assets/css/modules/outside.css`,
    `${BASE}/assets/js/standalone.js`,
    `${BASE}/assets/js/html.js`,
    `${BASE}/assets/js/actions.js`,
    `${BASE}/assets/js/app.js`,
    `${BASE}/assets/js/shell.js`,
    `${BASE}/assets/fonts/plexsans-latin-var.woff2`,
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
        const notice = await cache.match(`${BASE}/offline.html`);
        if (!notice) {
            return new Response('ออฟไลน์', { status: 503, headers: { 'Content-Type': 'text/plain; charset=utf-8' } });
        }
        // One file answers for every address (/notes/12, /files/…), and its
        // stylesheets are relative links; a fixed base keeps them pointing at
        // the app's assets rather than at wherever the visitor was.
        const html = (await notice.text()).replace('<head>', `<head><base href="${BASE}/">`);
        return new Response(html, { status: 200, headers: { 'Content-Type': 'text/html; charset=utf-8' } });
    }
}

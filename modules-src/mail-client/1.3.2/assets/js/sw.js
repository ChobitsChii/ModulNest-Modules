/**
 * Modulon Mail-Client – Progressive Web App Service Worker
 */
const CACHE_NAME = 'modulon-mail-pwa-v2';

self.addEventListener('install', function (event) {
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys().then(function (keys) {
            return Promise.all(
                keys.filter(function (key) { return key !== CACHE_NAME; })
                    .map(function (key) { return caches.delete(key); })
            );
        }).then(function () {
            return self.clients.claim();
        })
    );
});

self.addEventListener('fetch', function (event) {
    // Nur GET-Anfragen abfangen (keine POST, PUT, DELETE etc.)
    if (event.request.method !== 'GET') {
        return;
    }

    const url = new URL(event.request.url);
    if (!url.protocol.startsWith('http')) {
        return;
    }

    // Für dynamische API-Aufrufe direkt das Netzwerk nutzen
    if (url.pathname.includes('/api/')) {
        return;
    }

    // Netzwerk zuerst mit statischem Cache-Fallback
    event.respondWith(
        fetch(event.request)
            .then(function (response) {
                if (response.status === 200 && (
                    url.pathname.endsWith('.js') ||
                    url.pathname.endsWith('.css') ||
                    url.pathname.endsWith('.svg') ||
                    url.pathname.endsWith('.png') ||
                    url.pathname.endsWith('.woff2')
                )) {
                    const responseClone = response.clone();
                    caches.open(CACHE_NAME).then(function (cache) {
                        cache.put(event.request, responseClone);
                    });
                }
                return response;
            })
            .catch(async function () {
                const cached = await caches.match(event.request);
                if (cached) {
                    return cached;
                }
                return new Response('Offline: Die angeforderte Seite ist derzeit nicht erreichbar.', {
                    status: 503,
                    statusText: 'Service Unavailable',
                    headers: { 'Content-Type': 'text/plain; charset=utf-8' }
                });
            })
    );
});

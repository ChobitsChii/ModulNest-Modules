/**
 * Modulon Mail-Client – Progressive Web App Service Worker
 */
const CACHE_NAME = 'modulon-mail-pwa-v1';

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
    // Immer Netzwerk zuerst, damit E-Mails, API und Authentifizierung stets aktuell sind
    event.respondWith(
        fetch(event.request).catch(function () {
            return caches.match(event.request);
        })
    );
});

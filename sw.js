/**
 * SubTracker Service Worker
 * Caches core app shell, local CSS/JS, and CDN assets for offline use
 */

const CACHE_NAME = 'subtracker-cache-v12';
const ASSETS_TO_CACHE = [
  './',
  './index.html',
  './manifest.webmanifest',
  './css/app.css',
  './js/utils.js',
  './js/notifications.js',
  './js/store.js',
  './assets/icons/icon-192.svg',
  './assets/icons/icon-192.png',
  './assets/icons/icon-512.png',
  './assets/images/imsamaritan.png',
  'https://cdn.jsdelivr.net/npm/bulma@1.0.2/css/bulma.min.css',
  'https://cdn.jsdelivr.net/npm/alpinejs@3.14.8/dist/cdn.min.js',
  'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css'
];

// Service Worker install event - caches assets for offline installation
self.addEventListener('install', (event) => {
  console.log('SubTracker Service Worker: "install" lifecycle executing');
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      return cache.addAll(ASSETS_TO_CACHE).catch((err) => {
        console.warn('Some external assets could not be pre-cached, proceeding:', err);
      });
    })
  );
  self.skipWaiting();
});

// Service Worker activate event - cleans up older cache versions
self.addEventListener('activate', (event) => {
  console.log('SubTracker Service Worker: "activate" lifecycle executing');
  event.waitUntil(
    caches.keys().then((keys) => {
      return Promise.all(
        keys.map((key) => {
          if (key !== CACHE_NAME) {
            return caches.delete(key);
          }
        })
      );
    })
  );
  self.clients.claim();
});

// Explicit message event handler to trigger installer "install" in sw.js
self.addEventListener('message', (event) => {
  if (event.data && (event.data.action === 'install' || event.data.type === 'INSTALL' || event.data === 'install')) {
    console.log('SubTracker Service Worker: Installer "install" action triggered via client message');
    event.waitUntil(
      caches.open(CACHE_NAME).then((cache) => {
        return cache.addAll(ASSETS_TO_CACHE);
      }).catch((err) => {
        console.warn('Service Worker: Installer cache warning:', err);
      })
    );
    self.skipWaiting();
    if (event.ports && event.ports[0]) {
      event.ports[0].postMessage({ status: 'installed', cache: CACHE_NAME });
    }
  }
});

// Network First with Cache Fallback for local assets; Cache First for CDN
self.addEventListener('fetch', (event) => {
  if (event.request.method !== 'GET') return;

  const url = new URL(event.request.url);

  // If local origin (HTML, JS, CSS), use Network-First so updates take effect immediately
  if (url.origin === location.origin) {
    event.respondWith(
      fetch(event.request).then((networkResponse) => {
        if (networkResponse && networkResponse.status === 200) {
          const responseToCache = networkResponse.clone();
          caches.open(CACHE_NAME).then((cache) => {
            cache.put(event.request, responseToCache);
          });
        }
        return networkResponse;
      }).catch(() => {
        return caches.match(event.request).then((cachedResponse) => {
          if (cachedResponse) return cachedResponse;
          return caches.match('./index.html');
        });
      })
    );
    return;
  }

  // Cache First for external CDN resources
  event.respondWith(
    caches.match(event.request).then((cachedResponse) => {
      if (cachedResponse) {
        return cachedResponse;
      }
      return fetch(event.request).then((networkResponse) => {
        if (!networkResponse || networkResponse.status !== 200) {
          return networkResponse;
        }
        const responseToCache = networkResponse.clone();
        caches.open(CACHE_NAME).then((cache) => {
          cache.put(event.request, responseToCache);
        });
        return networkResponse;
      });
    })
  );
});

// Notification click handler
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  event.waitUntil(
    clients.matchAll({ type: 'window' }).then((clientList) => {
      for (const client of clientList) {
        if (client.url.includes('index.html') && 'focus' in client) {
          return client.focus();
        }
      }
      if (clients.openWindow) {
        return clients.openWindow('./index.html');
      }
    })
  );
});

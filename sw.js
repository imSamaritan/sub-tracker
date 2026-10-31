/**
 * SubTracker Service Worker
 * Caches core app shell, local CSS/JS, and CDN assets for offline use
 * and receives Web Push reminders while the app is closed.
 */

const CACHE_NAME = 'subtracker-cache-v14';
// Small persistent store for push settings (API base, current endpoint, VAPID key) that the
// service worker needs when it runs without any open page (e.g. on `pushsubscriptionchange`).
const CONFIG_CACHE = 'subtracker-config';
const CONFIG_URL = './__subtracker_push_config__';
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
          if (key !== CACHE_NAME && key !== CONFIG_CACHE) {
            return caches.delete(key);
          }
        })
      );
    }).then(() => self.clients.claim())
  );
});

// ----------------------------------------------------
// Push config persistence (used while no page is open)
// ----------------------------------------------------
async function readPushConfig() {
  try {
    const cache = await caches.open(CONFIG_CACHE);
    const res = await cache.match(CONFIG_URL);
    return res ? await res.json() : {};
  } catch (e) {
    return {};
  }
}

async function writePushConfig(partial) {
  const current = await readPushConfig();
  const next = { ...current, ...partial };
  const cache = await caches.open(CONFIG_CACHE);
  await cache.put(CONFIG_URL, new Response(JSON.stringify(next), {
    headers: { 'Content-Type': 'application/json' }
  }));
  return next;
}

function resolveApiUrl(apiBase, path) {
  const base = (apiBase || './api/v1').replace(/\/+$/, '');
  return new URL(`${base}${path}`, self.registration.scope).toString();
}

function urlBase64ToUint8Array(base64String) {
  const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
  const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
  const raw = atob(base64);
  const output = new Uint8Array(raw.length);
  for (let i = 0; i < raw.length; ++i) output[i] = raw.charCodeAt(i);
  return output;
}

// Explicit message event handler to trigger installer "install" in sw.js
self.addEventListener('message', (event) => {
  // Page -> SW: remember push settings so the SW can re-subscribe on its own later
  if (event.data && event.data.type === 'PUSH_CONFIG') {
    const { apiBase, endpoint, vapidPublicKey } = event.data;
    event.waitUntil(writePushConfig({ apiBase, endpoint, vapidPublicKey }));
    return;
  }

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
  if (url.pathname.includes('/api/')) return; // never cache API responses

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

// Web Push event handler (§9)
// Runs even when no SubTracker tab/PWA window is open: the browser/OS wakes this worker
// when the push service delivers a message, and we MUST show a notification here.
self.addEventListener('push', (event) => {
  let data = {
    title: 'SubTracker Reminder',
    body: 'You have upcoming subscription bills due soon.',
    url: './index.html#calendar',
    tag: 'subtracker-alert'
  };

  if (event.data) {
    try {
      const parsed = event.data.json();
      if (parsed && typeof parsed === 'object') {
        data = { ...data, ...parsed };
      }
    } catch (e) {
      const text = event.data.text();
      if (text) data.body = text;
    }
  }

  const options = {
    body: data.body,
    // PNG icons: Android/Chrome cannot render SVG notification icons or badges
    icon: './assets/icons/icon-192.png',
    badge: './assets/icons/icon-192.png',
    vibrate: [200, 100, 200],
    tag: data.tag || 'subtracker-alert',
    renotify: true,
    timestamp: Date.now(),
    data: {
      url: data.url || './index.html#calendar'
    }
  };

  event.waitUntil(
    self.registration.showNotification(data.title, options)
  );
});

// The browser rotated or expired the push subscription (can happen any time, usually while
// the app is closed). Re-subscribe and tell the server, otherwise the cron keeps pushing to a
// dead endpoint and the user only gets alerts again after re-opening the app.
self.addEventListener('pushsubscriptionchange', (event) => {
  event.waitUntil((async () => {
    const config = await readPushConfig();
    const oldEndpoint = (event.oldSubscription && event.oldSubscription.endpoint) || config.endpoint;
    if (!oldEndpoint) return;

    let newSubscription = event.newSubscription || null;
    if (!newSubscription) {
      let vapidKey = config.vapidPublicKey;
      if (!vapidKey) {
        const keyRes = await fetch(resolveApiUrl(config.apiBase, '/push/vapid-public-key'), { cache: 'no-store' });
        const keyJson = await keyRes.json();
        vapidKey = keyJson && keyJson.data && keyJson.data.publicKey;
      }
      if (!vapidKey) return;
      newSubscription = await self.registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(vapidKey)
      });
    }

    const json = newSubscription.toJSON();
    await fetch(resolveApiUrl(config.apiBase, '/push/subscriptions/renew'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json; charset=utf-8', 'Accept': 'application/json' },
      body: JSON.stringify({
        oldEndpoint,
        endpoint: json.endpoint,
        keys: json.keys || {},
        contentEncoding: 'aes128gcm'
      })
    });
    await writePushConfig({ endpoint: json.endpoint });
  })().catch((err) => {
    console.warn('SubTracker SW: pushsubscriptionchange re-subscribe failed:', err);
  }));
});

// Notification click handler
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const targetUrl = new URL(
    (event.notification.data && event.notification.data.url) ? event.notification.data.url : './index.html',
    self.registration.scope
  ).toString();

  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
      for (const client of clientList) {
        if (client.url.startsWith(self.registration.scope) && 'focus' in client) {
          if ('navigate' in client) {
            client.navigate(targetUrl).catch(() => {});
          }
          return client.focus();
        }
      }
      if (clients.openWindow) {
        return clients.openWindow(targetUrl);
      }
    })
  );
});

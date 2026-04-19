// ============================================================
//  sw.js — Luna's POS Service Worker v4
// ============================================================

const CACHE_NAME   = 'lunas-pos-v4';
const OFFLINE_PAGE = '/offline.html';

const SHELL_ASSETS = [
  '/',
  '/login.html',
  '/register.html',
  '/forgot_password.html',
  '/dashboard.html',
  '/pos_terminal.html',
  '/inventory.html',
  '/salesreport.html',
  '/customer.html',
  '/analytics.html',
  '/settings.html',
  '/admin.html',
  '/offline.html',
  '/manifest.json',
  '/favicon.ico',
  '/js/api.js',
  '/js/pwa.js',
  '/icon-192x192.png',
  '/icon-512x512.png',
];

// ── INSTALL ──────────────────────────────────────────────────
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache =>
      Promise.allSettled(
        SHELL_ASSETS.map(url =>
          cache.add(url).catch(err =>
            console.warn('[SW] Skipping cache for:', url, err)
          )
        )
      )
    ).then(() => self.skipWaiting())
  );
});

// ── ACTIVATE ─────────────────────────────────────────────────
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(
        keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k))
      )
    ).then(() => self.clients.claim())
  );
});

// ── FETCH ─────────────────────────────────────────────────────
self.addEventListener('fetch', event => {
  const { request } = event;
  const url = new URL(request.url);

  if (request.method !== 'GET') return;
  if (url.pathname.includes('/api/') || url.pathname.endsWith('.php')) return;

  const isShell = SHELL_ASSETS.some(a => url.pathname === a || url.pathname === a + '/');

  if (isShell) {
    event.respondWith(
      caches.match(request).then(cached => {
        if (cached) return cached;
        return fetch(request).then(res => {
          const resClone = res.clone();
          caches.open(CACHE_NAME).then(c => c.put(request, resClone));
          return res;
        });
      }).catch(() => caches.match(OFFLINE_PAGE))
    );
  } else {
    event.respondWith(
      fetch(request).then(res => {
        const isCDN = url.hostname.includes('cdnjs') ||
          url.hostname.includes('fonts.googleapis') ||
          url.hostname.includes('cdn.tailwindcss') ||
          url.hostname.includes('cdn.jsdelivr');
        if (isCDN) {
          const resClone = res.clone();
          caches.open(CACHE_NAME).then(c => c.put(request, resClone));
        }
        return res;
      }).catch(() =>
        caches.match(request).then(c => c || caches.match(OFFLINE_PAGE))
      )
    );
  }
});

// ── BACKGROUND SYNC ──────────────────────────────────────────
self.addEventListener('sync', event => {
  if (event.tag === 'sync-orders') {
    event.waitUntil(syncPendingOrders());
  }
});

async function syncPendingOrders() {
  try {
    const db = await openDB();
    const tx = db.transaction('pending_orders', 'readwrite');
    const store = tx.objectStore('pending_orders');
    const orders = await getAllFromStore(store);
    for (const order of orders) {
      try {
        const res = await fetch('/api/orders.php?action=place', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify(order.payload),
        });
        const data = await res.json();
        if (data.success) await deleteFromStore(db, 'pending_orders', order.id);
      } catch (e) { /* retry next sync */ }
    }
  } catch (e) {
    console.error('[SW] syncPendingOrders error:', e);
  }
}

function openDB() {
  return new Promise((resolve, reject) => {
    const req = indexedDB.open('lunas_pos_offline', 1);
    req.onupgradeneeded = e => {
      const db = e.target.result;
      if (!db.objectStoreNames.contains('pending_orders')) {
        db.createObjectStore('pending_orders', { keyPath: 'id', autoIncrement: true });
      }
    };
    req.onsuccess = e => resolve(e.target.result);
    req.onerror   = e => reject(e.target.error);
  });
}

function getAllFromStore(store) {
  return new Promise((resolve, reject) => {
    const req = store.getAll();
    req.onsuccess = e => resolve(e.target.result);
    req.onerror   = e => reject(e.target.error);
  });
}

function deleteFromStore(db, storeName, id) {
  return new Promise((resolve, reject) => {
    const tx  = db.transaction(storeName, 'readwrite');
    const req = tx.objectStore(storeName).delete(id);
    req.onsuccess = () => resolve();
    req.onerror   = e  => reject(e.target.error);
  });
}

// ── PUSH NOTIFICATIONS ────────────────────────────────────────
self.addEventListener('push', event => {
  if (!event.data) return;
  const data = event.data.json();
  event.waitUntil(
    self.registration.showNotification(data.title || "Luna's POS Alert", {
      body:    data.body || '',
      icon:    '/icon-192x192.png',
      badge:   '/icon-72x72.png',
      vibrate: [200, 100, 200],
      data:    { url: data.url || '/dashboard.html' },
    })
  );
});

self.addEventListener('notificationclick', event => {
  event.notification.close();
  event.waitUntil(
    clients.openWindow(event.notification.data.url || '/dashboard.html')
  );
});

// ============================================================
//  js/pwa.js — PWA registration + install prompt
// ============================================================

(function () {

  let deferredPrompt = null;
  let swRegistered = false;

  // ── Register Service Worker ───────────────────────────────
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
      navigator.serviceWorker.register('/sw.js', { scope: '/' })
        .then(reg => {
          console.log('[PWA] SW registered:', reg.scope);
          swRegistered = true;
          setInterval(() => reg.update(), 60_000);
        })
        .catch(err => console.error('[PWA] SW failed:', err));
    });
  }

  // ── Listen for install prompt ─────────────────────────────
  window.addEventListener('beforeinstallprompt', e => {
    console.log('[PWA] beforeinstallprompt fired!');
    e.preventDefault();
    deferredPrompt = e;
    showBanner();
  });

  window.addEventListener('appinstalled', () => {
    deferredPrompt = null;
    removeBanner();
  });

  // ── Always show install UI after load ────────────────────
  // Show banner regardless - if deferredPrompt not available,
  // guide user to browser menu
  window.addEventListener('load', () => {
    // Don't show if already running as installed PWA
    if (window.matchMedia('(display-mode: standalone)').matches) return;
    if (navigator.standalone === true) return; // iOS

    // Wait 2 seconds then show install UI
    setTimeout(() => {
      showBanner();
    }, 2000);
  });

  function showBanner() {
    if (window.matchMedia('(display-mode: standalone)').matches) return;
    if (navigator.standalone === true) return;

    // Remove existing
    removeBanner();

    const banner = document.createElement('div');
    banner.id = 'pwa-install-banner';

    const isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent);
    const canInstall = !!deferredPrompt;

    let content = '';

    if (isIOS) {
      content = `
        <img src="/icon-192x192.png" style="width:48px;height:48px;border-radius:12px;flex-shrink:0;">
        <div style="flex:1;min-width:0;">
          <div style="font-weight:700;color:#1e293b;font-size:15px;">Install Luna's POS</div>
          <div style="color:#64748b;font-size:12px;margin-top:3px;">Tap <b>Share</b> → <b>Add to Home Screen</b></div>
        </div>
        <button id="pwa-dismiss-btn" style="background:none;border:none;color:#94a3b8;font-size:24px;cursor:pointer;padding:0;flex-shrink:0;">×</button>
      `;
    } else if (canInstall) {
      content = `
        <img src="/icon-192x192.png" style="width:48px;height:48px;border-radius:12px;flex-shrink:0;">
        <div style="flex:1;min-width:0;">
          <div style="font-weight:700;color:#1e293b;font-size:15px;">Install Luna's POS</div>
          <div style="color:#64748b;font-size:12px;margin-top:3px;">Works offline · Faster access</div>
        </div>
        <button id="pwa-install-btn" style="background:#4f46e5;color:#fff;border:none;border-radius:10px;padding:10px 16px;font-weight:700;font-size:14px;cursor:pointer;white-space:nowrap;flex-shrink:0;">Install</button>
        <button id="pwa-dismiss-btn" style="background:none;border:none;color:#94a3b8;font-size:24px;cursor:pointer;padding:0;flex-shrink:0;">×</button>
      `;
    } else {
      // No prompt available - guide to browser menu
      content = `
        <img src="/icon-192x192.png" style="width:48px;height:48px;border-radius:12px;flex-shrink:0;">
        <div style="flex:1;min-width:0;">
          <div style="font-weight:700;color:#1e293b;font-size:15px;">Install Luna's POS</div>
          <div style="color:#64748b;font-size:12px;margin-top:3px;">Tap <b>⋮ menu</b> → <b>Add to Home Screen</b></div>
        </div>
        <button id="pwa-dismiss-btn" style="background:none;border:none;color:#94a3b8;font-size:24px;cursor:pointer;padding:0;flex-shrink:0;">×</button>
      `;
    }

    banner.innerHTML = `
      <div style="
        position:fixed;bottom:20px;left:50%;transform:translateX(-50%);
        background:#fff;border-radius:16px;padding:16px 18px;
        box-shadow:0 8px 32px rgba(79,70,229,0.3);
        display:flex;align-items:center;gap:12px;z-index:99999;
        max-width:380px;width:calc(100% - 32px);
        border:2px solid #4f46e5;
        font-family:sans-serif;
      ">
        ${content}
      </div>
    `;

    document.body.appendChild(banner);

    const installBtn = document.getElementById('pwa-install-btn');
    if (installBtn) {
      installBtn.addEventListener('click', async () => {
        if (!deferredPrompt) return;
        deferredPrompt.prompt();
        const { outcome } = await deferredPrompt.userChoice;
        console.log('[PWA] outcome:', outcome);
        deferredPrompt = null;
        removeBanner();
      });
    }

    document.getElementById('pwa-dismiss-btn').addEventListener('click', () => {
      removeBanner();
      localStorage.setItem('pwa_dismissed_until', Date.now() + 86400000); // hide for 1 day
    });
  }

  function removeBanner() {
    const b = document.getElementById('pwa-install-banner');
    if (b) b.remove();
  }

  // ── Offline Order Queue ───────────────────────────────────
  window.offlineQueue = {
    async save(orderPayload) {
      const db = await openOfflineDB();
      const tx = db.transaction('pending_orders', 'readwrite');
      tx.objectStore('pending_orders').add({ payload: orderPayload, saved_at: new Date().toISOString() });
      return new Promise((res, rej) => {
        tx.oncomplete = () => res(true);
        tx.onerror    = e  => rej(e.target.error);
      });
    },
    async triggerSync() {
      if ('serviceWorker' in navigator && 'SyncManager' in window) {
        const reg = await navigator.serviceWorker.ready;
        await reg.sync.register('sync-orders');
      }
    }
  };

  function openOfflineDB() {
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

  // ── Online/Offline indicator ──────────────────────────────
  function updateOnlineStatus() {
    let el = document.getElementById('pwa-online-status');
    if (!el) {
      el = document.createElement('div');
      el.id = 'pwa-online-status';
      el.style.cssText = 'position:fixed;top:10px;right:10px;z-index:88888;padding:5px 12px;border-radius:20px;font-size:12px;font-weight:700;pointer-events:none;transition:opacity 0.5s;font-family:sans-serif;';
      document.body.appendChild(el);
    }
    if (navigator.onLine) {
      el.style.cssText += 'background:#dcfce7;color:#16a34a;opacity:1;';
      el.textContent = '● Online';
      setTimeout(() => el.style.opacity = '0', 2000);
    } else {
      el.style.cssText += 'background:#fef2f2;color:#dc2626;opacity:1;';
      el.textContent = '⚠ Offline';
    }
  }

  window.addEventListener('online', updateOnlineStatus);
  window.addEventListener('offline', updateOnlineStatus);

})();

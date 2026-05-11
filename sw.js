const CACHE_NAME = 'absen-bbws-v2';
const STATIC_ASSETS = [
  '/absensi-nonasn/assets/img/logo-instansi.png',
  '/absensi-nonasn/assets/img/logo-bbws-white.png'
];

// Install: cache aset statis saja (bukan PHP)
self.addEventListener('install', e => {
  e.waitUntil(
    caches.open(CACHE_NAME).then(cache => cache.addAll(STATIC_ASSETS)).catch(() => {})
  );
  self.skipWaiting();
});

// Activate: hapus cache lama
self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))
    )
  );
  self.clients.claim();
});

// Fetch: untuk PHP selalu ke network, untuk aset pakai cache
self.addEventListener('fetch', e => {
  const url = new URL(e.request.url);
  
  // Jangan intercept PHP atau non-GET
  if (e.request.method !== 'GET') return;
  if (url.pathname.endsWith('.php')) return;
  if (url.origin !== location.origin) return;

  // Untuk aset statis: cache first
  e.respondWith(
    caches.match(e.request).then(cached => {
      return cached || fetch(e.request).then(res => {
        if (res.ok) {
          const clone = res.clone();
          caches.open(CACHE_NAME).then(c => c.put(e.request, clone));
        }
        return res;
      });
    }).catch(() => caches.match(e.request))
  );
});

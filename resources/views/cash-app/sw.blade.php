// Service worker «Каси». Дані завжди з мережі (гроші — тільки живі цифри);
// офлайн показуємо останню збережену сторінку, щоб застосунок відкрився й сказав «немає звʼязку».
const CACHE = 'cash-app-v1';
const ICONS = ['/cash-icons/icon-192.png', '/cash-icons/icon-512.png', '/cash-icons/icon-180.png'];

self.addEventListener('install', (e) => {
    e.waitUntil(caches.open(CACHE).then((c) => c.addAll(ICONS)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
    e.waitUntil(caches.keys()
        .then((keys) => Promise.all(keys.filter((k) => k.startsWith('cash-app-') && k !== CACHE).map((k) => caches.delete(k))))
        .then(() => self.clients.claim()));
});

self.addEventListener('fetch', (e) => {
    const url = new URL(e.request.url);
    if (e.request.method !== 'GET' || url.origin !== location.origin) return;

    if (e.request.mode === 'navigate' && url.pathname.replace(/\/$/, '') === '/cash-app') {
        e.respondWith(fetch(e.request).then((res) => {
            // Кешуємо лише справжню сторінку каси, не редирект на логін.
            if (res.ok && !res.redirected) {
                const copy = res.clone();
                caches.open(CACHE).then((c) => c.put('/cash-app', copy));
            }
            return res;
        }).catch(() => caches.match('/cash-app')));
        return;
    }

    if (url.pathname.startsWith('/cash-icons/')) {
        e.respondWith(caches.match(e.request).then((hit) => hit || fetch(e.request)));
    }
});

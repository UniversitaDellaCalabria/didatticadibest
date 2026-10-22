// sw.js - Service Worker PWA Didattica DiBEST
// Le pagine arrivano SEMPRE dal server: contengono nome dell'utente, posti liberi e stato del login,
// quindi una copia salvata mostrerebbe lo stato precedente (e dati personali dopo il logout).
// In cache solo le librerie in assets/vendor e assets/js (percorsi con la versione: Cache-First) e la pagina offline.
// Cambiare SW_VERSION fa cancellare ai browser tutte le cache delle versioni precedenti.

const SW_VERSION = 'dibest-v8';

const CACHE_STATIC  = SW_VERSION + '-static';   // CSS, JS, font e icone delle librerie
const CACHE_OFFLINE = SW_VERSION + '-offline';  // Solo offline.html

const STATIC_ASSETS = [
    './assets/vendor/jsdelivr/npm/bootstrap-italia@2.8.3/dist/css/bootstrap-italia.min.css',
    './assets/vendor/jsdelivr/npm/bootstrap-italia@2.8.3/dist/js/bootstrap-italia.bundle.min.js',
    './assets/vendor/cdnjs/ajax/libs/font-awesome/6.4.0/css/all.min.css',
    './assets/vendor/fonts/titillium-lora.css',
];

self.addEventListener('install', event => {
    event.waitUntil(
        Promise.all([
            caches.open(CACHE_STATIC).then(cache => cache.addAll(STATIC_ASSETS).catch(() => {})),
            caches.open(CACHE_OFFLINE).then(cache => cache.addAll(['./offline.html']).catch(() => {})),
        ]).then(() => self.skipWaiting())
    );
});

// Elimina ogni altra cache, comprese le pagine salvate dalle versioni precedenti
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys =>
            Promise.all(keys.filter(k => k !== CACHE_STATIC && k !== CACHE_OFFLINE).map(k => caches.delete(k)))
        ).then(() => clients.claim())
    );
});

self.addEventListener('message', event => {
    if (event.data && event.data.type === 'SKIP_WAITING') self.skipWaiting();
});

self.addEventListener('fetch', event => {
    const req = event.request;
    if (req.method !== 'GET') return;

    // Librerie statiche (locali, o del CDN se usato come ripiego): Cache-First
    if (isStaticCDN(req.url)) {
        event.respondWith(cacheFirst(req));
        return;
    }

    // Apertura di una pagina: sempre dalla rete; solo se si è offline, la pagina offline
    if (req.mode === 'navigate') {
        event.respondWith(fetch(req).catch(offlineFallback));
    }
    // Tutto il resto (AJAX, immagini, file locali): gestito normalmente dal browser
});

// Notifiche push dell'assistenza: il messaggio arriva cifrato dal portale (titolo, corpo, url, tag) e si mostra come finestrella del sistema.
// Con lo stesso tag la notifica nuova sostituisce la precedente della stessa chat e suona di nuovo (renotify).
self.addEventListener('push', event => {
    let d = {};
    try { d = event.data ? event.data.json() : {}; } catch (e) { d = { corpo: event.data ? event.data.text() : '' }; }
    event.waitUntil(self.registration.showNotification(d.titolo || 'Didattica DiBEST', {
        body: d.corpo || '', tag: d.tag || undefined, renotify: !!d.tag,
        icon: './assets/icon-192.png', badge: './assets/icon-192.png', data: { url: d.url || './' },
    }));
});

// Un clic sulla notifica porta alla chat: se il sito è già aperto in una scheda si riusa quella, altrimenti se ne apre una nuova
self.addEventListener('notificationclick', event => {
    event.notification.close();
    const url = new URL((event.notification.data && event.notification.data.url) || './', self.registration.scope).href;
    event.waitUntil(clients.matchAll({ type: 'window', includeUncontrolled: true }).then(lista => {
        const base = url.split('?')[0];
        for (const c of lista) {
            if (c.url.split('?')[0] === base && 'focus' in c) {
                return c.focus().then(w => ('navigate' in w && w.url !== url ? w.navigate(url) : w));
            }
        }
        return clients.openWindow(url);
    }));
});

async function cacheFirst(req) {
    const cache  = await caches.open(CACHE_STATIC);
    const cached = await cache.match(req);
    if (cached) return cached;
    try {
        const fresh = await fetch(req);
        if (fresh.ok) cache.put(req, fresh.clone());
        return fresh;
    } catch {
        return Response.error();
    }
}

async function offlineFallback() {
    const cache = await caches.open(CACHE_OFFLINE);
    return (await cache.match('./offline.html')) || new Response(
        '<h1>Sei offline</h1><p>Connettiti per usare Didattica DiBEST.</p>',
        { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } }
    );
}

function isStaticCDN(href) {
    return href.includes('/assets/vendor/') ||
           href.includes('/assets/js/') ||
           href.includes('cdn.jsdelivr.net') ||
           href.includes('cdnjs.cloudflare.com') ||
           href.includes('fonts.googleapis.com') ||
           href.includes('fonts.gstatic.com');
}

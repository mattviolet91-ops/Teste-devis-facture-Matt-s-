// Service worker : notifications sur le téléphone (Web Push) et mode hors connexion.
var PAGES = 'mc-pages-v1';
var STATIC = 'mc-static-v1';
var OFFLINE_URL = '/hors-ligne';

self.addEventListener('install', function (event) {
  self.skipWaiting();
  event.waitUntil(caches.open(STATIC).then(function (cache) { return cache.add(OFFLINE_URL); }).catch(function () {}));
});
self.addEventListener('activate', function (event) {
  event.waitUntil(caches.keys().then(function (keys) {
    return Promise.all(keys.filter(function (k) { return k.indexOf('mc-') === 0 && k !== PAGES && k !== STATIC; }).map(function (k) { return caches.delete(k); }));
  }).then(function () { return self.clients.claim(); }));
});

// Jamais en cache : espace client, déconnexion, sauvegardes, jeton, liste des pages.
var SKIP = [/^\/d\//, /^\/f\//, /^\/deconnexion/, /^\/reglages\/sauvegardes\//, /^\/hors-ligne\/(jeton|pages)/, /^\/connexion/, /^\/photos\/\d+\/original/];
var STATIC_PATH = /^\/(css|js|fonts|icons|marque)\/|\.(css|js|png|svg|woff2?|webmanifest)$/;

function fromNetwork(request, cacheName) {
  return fetch(request).then(function (response) {
    // Pas de page de connexion (session expirée) ni d'erreur dans le cache.
    if (response.ok && !response.redirected && response.type === 'basic') {
      var copy = response.clone();
      caches.open(cacheName).then(function (cache) { cache.put(request, copy); });
    }
    return response;
  });
}

function networkFirst(request) {
  return new Promise(function (resolve, reject) {
    var settled = false;
    var timer = setTimeout(function () {
      caches.match(request, { cacheName: PAGES }).then(function (cached) {
        if (cached && !settled) { settled = true; resolve(cached); }
      });
    }, 6000);
    fromNetwork(request, PAGES).then(function (response) {
      clearTimeout(timer);
      if (!settled) { settled = true; resolve(response); }
    }, function (error) {
      clearTimeout(timer);
      if (!settled) { settled = true; reject(error); }
    });
  });
}

self.addEventListener('fetch', function (event) {
  var request = event.request;
  if (request.method !== 'GET' || request.headers.has('range')) { return; }
  var url = new URL(request.url);
  if (url.origin !== self.location.origin || SKIP.some(function (re) { return re.test(url.pathname); })) { return; }

  if (STATIC_PATH.test(url.pathname) && !/^\/photos\//.test(url.pathname)) {
    // Fichiers de l'application : cache d'abord, mis à jour en arrière-plan.
    event.respondWith(caches.match(request).then(function (cached) {
      var network = fromNetwork(request, STATIC).catch(function () { return cached; });
      return cached || network;
    }));
    return;
  }

  // Pages et données : réseau d'abord, copie enregistrée ; sans réseau (ou réseau trop lent), dernière copie.
  event.respondWith(networkFirst(request).catch(function () {
    return caches.match(request, { cacheName: PAGES }).then(function (cached) {
      if (cached) { return cached; }
      if (request.mode === 'navigate') { return caches.match(OFFLINE_URL); }
      return Response.error();
    });
  }));
});

self.addEventListener('message', function (event) {
  // Déconnexion : les pages enregistrées sont effacées du téléphone.
  if (event.data === 'clear-pages') { event.waitUntil(caches.delete(PAGES)); }
});

self.addEventListener('push', function (event) {
  var data = {};
  try { data = event.data ? event.data.json() : {}; } catch (e) { data = { body: event.data ? event.data.text() : '' }; }
  event.waitUntil(self.registration.showNotification(data.title || "Matt's Couverture", {
    body: data.body || '',
    icon: '/icons/icon-192.png',
    badge: '/icons/icon-192.png',
    data: { url: data.url || '/' },
    tag: data.url || undefined,
    renotify: true
  }));
});

self.addEventListener('notificationclick', function (event) {
  event.notification.close();
  var url = (event.notification.data && event.notification.data.url) || '/';
  event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
    for (var i = 0; i < list.length; i++) {
      if ('focus' in list[i]) { list[i].navigate(url); return list[i].focus(); }
    }
    return self.clients.openWindow(url);
  }));
});

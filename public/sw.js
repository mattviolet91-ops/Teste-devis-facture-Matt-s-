// Service worker : notifications sur le téléphone (Web Push) et mode hors connexion.
var PAGES = 'mc-pages-v1';
var STATIC = 'mc-static-v3';
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

// Jamais en cache : espace client, déconnexion, sauvegardes, jeton, liste des pages, espace Argent (privé).
var SKIP = [/^\/argent(\/|$)/, /^\/d\//, /^\/f\//, /^\/deconnexion/, /^\/reglages\/sauvegardes\//, /^\/hors-ligne\/(jeton|pages)/, /^\/connexion/, /^\/photos\/\d+\/original/];
var STATIC_PATH = /^\/(css|js|fonts|icons|marque|images\/guide)\/|\.(css|js|png|svg|woff2?|webmanifest)$/;

// Réseau mobile instable : une demande qui échoue est retentée une fois (ou deux)
// avant d'abandonner, au lieu d'afficher « connexion échouée ».
function fetchRetry(request, tries) {
  return fetch(request).catch(function (error) {
    if (tries <= 0) { throw error; }
    return new Promise(function (resolve) { setTimeout(resolve, 700); }).then(function () {
      return fetchRetry(request, tries - 1);
    });
  });
}

// Page de secours si rien n'est disponible (jamais d'écran d'erreur du navigateur).
function offlineResponse() {
  return caches.match(OFFLINE_URL).then(function (page) {
    return page || new Response(
      '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' +
      '<title>Pas de réseau</title><body style="font-family:sans-serif;padding:2rem;text-align:center">' +
      '<h1>Pas de réseau</h1><p>La connexion au serveur a été interrompue.</p><p><a href="" onclick="location.reload();return false">Réessayer</a></p>',
      { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } }
    );
  });
}

function fromNetwork(request, cacheName) {
  return fetchRetry(request, 2).then(function (response) {
    // Pas de page de connexion (session expirée) ni d'erreur dans le cache.
    if (response.ok && !response.redirected && response.type === 'basic') {
      var copy = response.clone();
      caches.open(cacheName).then(function (cache) { cache.put(request, copy); });
    }
    return response;
  });
}

// Juste après un enregistrement, la page affichée doit être la nouvelle : pas de copie ancienne.
var lastPostAt = 0;

// Copie enregistrée montrée à la place de la page : on le signale en haut de l'écran.
function markStale(response, request) {
  if (!response || request.mode !== 'navigate' || (response.headers.get('Content-Type') || '').indexOf('text/html') === -1) {
    return Promise.resolve(response);
  }
  var saved = response.headers.get('Date');
  var when = saved ? new Date(saved).toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '';
  var message = 'Copie enregistrée sur le téléphone' + (when ? ' (' + when + ')' : '') + ' : réseau absent ou trop lent. Touchez ici pour recharger.';
  return response.text().then(function (html) {
    html = html.replace('data-offline-bar hidden', 'data-offline-bar data-stale="' + message + '"');
    return new Response(html, { status: response.status, statusText: response.statusText, headers: response.headers });
  });
}

function networkFirst(request) {
  return new Promise(function (resolve, reject) {
    var settled = false;
    // Après un enregistrement, on attend bien plus longtemps la vraie page avant de montrer une copie.
    var fresh = Date.now() - lastPostAt < 60000;
    var timer = setTimeout(function () {
      caches.match(request, { cacheName: PAGES }).then(function (cached) {
        if (cached && !settled) { settled = true; resolve(markStale(cached, request)); }
      });
    }, fresh ? 30000 : 6000);
    fromNetwork(request, PAGES).then(function (response) {
      clearTimeout(timer);
      if (!settled) { settled = true; resolve(response); }
    }, function (error) {
      clearTimeout(timer);
      if (!settled) { settled = true; reject(error); }
    });
  });
}

// Connexion coupée pendant l'envoi d'un formulaire : page en français, sans perdre la saisie.
function postFailedResponse() {
  return new Response(
    '<!doctype html><html lang="fr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' +
    '<title>Connexion coupée</title><body style="font-family:system-ui,sans-serif;padding:2rem 1.25rem;max-width:32rem;margin:auto;line-height:1.5">' +
    '<h1 style="font-size:1.4rem">Connexion coupée pendant l\'envoi</h1>' +
    '<p>Le réseau a coupé au mauvais moment : l\'enregistrement est peut-être arrivé, peut-être pas.</p>' +
    '<p><strong>Revenez en arrière et appuyez de nouveau sur le bouton.</strong> S\'il était déjà arrivé, il ne sera pas enregistré deux fois.</p>' +
    '<p><a href="#" onclick="history.back();return false" style="display:inline-block;padding:.8rem 1.2rem;background:#1f7fb4;color:#fff;border-radius:10px;text-decoration:none;font-weight:600">‹ Revenir au formulaire</a></p>',
    { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' } }
  );
}

self.addEventListener('fetch', function (event) {
  var request = event.request;
  if (request.method === 'POST') {
    lastPostAt = Date.now();
    var postUrl = new URL(request.url);
    if (request.mode === 'navigate' && postUrl.origin === self.location.origin && !SKIP.some(function (re) { return re.test(postUrl.pathname); })) {
      event.respondWith(fetch(request).catch(postFailedResponse));
    }
    return;
  }
  if (request.method !== 'GET' || request.headers.has('range')) { return; }
  var url = new URL(request.url);
  if (url.origin !== self.location.origin || SKIP.some(function (re) { return re.test(url.pathname); })) { return; }

  if (STATIC_PATH.test(url.pathname) && !/^\/photos\//.test(url.pathname)) {
    // Fichiers de l'application : cache d'abord, mis à jour en arrière-plan.
    event.respondWith(caches.match(request).then(function (cached) {
      var network = fromNetwork(request, STATIC).catch(function () { return cached || Response.error(); });
      return cached || network;
    }));
    return;
  }

  // Pages et données : réseau d'abord, copie enregistrée ; sans réseau (ou réseau trop lent), dernière copie.
  event.respondWith(networkFirst(request).catch(function () {
    return caches.match(request, { cacheName: PAGES }).then(function (cached) {
      if (cached) { return markStale(cached, request); }
      if (request.mode === 'navigate') { return offlineResponse(); }
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

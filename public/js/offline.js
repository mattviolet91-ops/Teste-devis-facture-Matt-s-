// Mode hors connexion : pages consultables sans réseau (service worker) et
// formulaires remplis sans réseau, gardés sur le téléphone puis envoyés au retour du réseau.
(function () {
  'use strict';

  var root = document.querySelector('[data-offline-root]');
  if (!root || !('serviceWorker' in navigator)) { return; }

  var bar = document.querySelector('[data-offline-bar]');
  var dialog = document.getElementById('offline-dialog');
  var list = dialog ? dialog.querySelector('[data-offline-list]') : null;
  var tokenUrl = root.getAttribute('data-token-url');
  var pagesUrl = root.getAttribute('data-pages-url');

  navigator.serviceWorker.register('/sw.js').catch(function () {});

  // ---- File d'attente (IndexedDB) ----
  function db() {
    return new Promise(function (resolve, reject) {
      if (!window.indexedDB) { reject(new Error('indexedDB indisponible')); return; }
      var req = indexedDB.open('mc-offline', 1);
      req.onupgradeneeded = function () { req.result.createObjectStore('forms', { keyPath: 'id', autoIncrement: true }); };
      req.onsuccess = function () { resolve(req.result); };
      req.onerror = function () { reject(req.error); };
    });
  }
  function store(mode, fn) {
    return db().then(function (d) {
      return new Promise(function (resolve, reject) {
        var tx = d.transaction('forms', mode);
        var result = fn(tx.objectStore('forms'));
        tx.oncomplete = function () { resolve(result && result.result !== undefined ? result.result : result); };
        tx.onerror = function () { reject(tx.error); };
      });
    });
  }
  function all() { return store('readonly', function (s) { return s.getAll(); }); }
  function put(item) { return store('readwrite', function (s) { return s.put(item); }); }
  function remove(id) { return store('readwrite', function (s) { return s.delete(id); }); }

  // ---- Bandeau ----
  function refresh() {
    return all().then(function (items) {
      var waiting = items.length;
      var failed = items.filter(function (i) { return i.error; }).length;
      var text = '';
      if (!navigator.onLine) { text = 'Hors connexion : vous voyez les pages enregistrées sur le téléphone.'; }
      if (waiting) { text += (text ? ' ' : '') + waiting + ' envoi' + (waiting > 1 ? 's' : '') + ' en attente' + (failed ? ' (' + failed + ' à corriger)' : '') + '.'; }
      bar.hidden = !text;
      bar.querySelector('[data-offline-text]').textContent = text;
      bar.querySelector('[data-offline-open]').hidden = !waiting;
      if (list) { render(items); }
    }).catch(function () {
      bar.hidden = navigator.onLine;
      bar.querySelector('[data-offline-text]').textContent = 'Hors connexion.';
    });
  }

  function render(items) {
    list.textContent = '';
    if (!items.length) { list.innerHTML = '<p class="muted">Rien en attente.</p>'; return; }
    items.forEach(function (item) {
      var row = document.createElement('div');
      row.className = 'offline-item';
      var title = document.createElement('strong');
      title.textContent = item.label + ' — ' + new Date(item.createdAt).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' });
      row.appendChild(title);
      var state = document.createElement('p');
      state.className = 'small ' + (item.error ? 'error' : 'muted');
      state.textContent = item.error || 'Sera envoyé automatiquement au retour du réseau.';
      row.appendChild(state);
      var actions = document.createElement('div');
      actions.className = 'chips';
      var open = document.createElement('a');
      open.className = 'chip'; open.href = item.page; open.textContent = 'Ouvrir la page';
      var del = document.createElement('button');
      del.type = 'button'; del.className = 'chip'; del.textContent = 'Supprimer';
      del.addEventListener('click', function () {
        if (window.confirm('Supprimer cet envoi en attente ? Les informations saisies seront perdues.')) { remove(item.id).then(refresh); }
      });
      actions.appendChild(open); actions.appendChild(del);
      row.appendChild(actions);
      list.appendChild(row);
    });
  }

  // ---- Formulaire rempli sans réseau ----
  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (navigator.onLine || !form.matches('form[data-offline]') || event.defaultPrevented) { return; }
    event.preventDefault();

    var fields = [];
    new FormData(form).forEach(function (value, name) {
      if (typeof value === 'string') { fields.push([name, value]); }
    });
    put({
      label: form.getAttribute('data-offline') || 'Formulaire',
      url: form.action, page: location.href, fields: fields, createdAt: Date.now(), error: null
    }).then(function () {
      var note = document.createElement('div');
      note.className = 'alert alert-info';
      note.setAttribute('role', 'status');
      note.textContent = 'Pas de réseau : enregistré sur le téléphone. Envoi automatique dès que le réseau revient.';
      form.parentNode.insertBefore(note, form);
      note.scrollIntoView({ behavior: 'smooth', block: 'center' });
      form.querySelectorAll('[type="submit"]').forEach(function (b) { b.disabled = true; b.textContent = 'En attente du réseau'; });
      refresh();
    }).catch(function () {
      window.alert('Impossible d\'enregistrer sur ce téléphone. Réessayez quand vous aurez du réseau.');
    });
  });

  // ---- Envoi au retour du réseau ----
  var flushing = false;
  function flush() {
    if (flushing || !navigator.onLine) { return Promise.resolve(); }
    flushing = true;
    return all().then(function (items) {
      items = items.filter(function (i) { return !i.error; });
      if (!items.length) { return; }
      return fetch(tokenUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { if (!r.ok || r.redirected) { throw new Error('login'); } return r.json(); })
        .then(function (json) {
          return items.reduce(function (chain, item) {
            return chain.then(function () { return send(item, json.token); });
          }, Promise.resolve());
        });
    }).catch(function (e) {
      if (e && e.message === 'login') {
        bar.hidden = false;
        bar.querySelector('[data-offline-text]').textContent = 'Envois en attente : reconnectez-vous pour les envoyer.';
      }
    }).then(function () { flushing = false; return refresh(); });
  }

  function send(item, token) {
    var body = new FormData();
    item.fields.forEach(function (f) { body.append(f[0], f[0] === '_token' ? token : f[1]); });
    return fetch(item.url, {
      method: 'POST', body: body, credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (response) {
      if (response.status === 422) {
        return response.json().then(function (json) {
          var messages = json.errors ? Object.keys(json.errors).map(function (k) { return json.errors[k][0]; }) : [];
          item.error = 'À corriger : ' + (messages.slice(0, 3).join(' ') || json.message || 'informations invalides') + ' Ouvrez la page pour le refaire.';
          return put(item);
        });
      }
      if (response.status === 419 || response.status === 401 || /\/connexion/.test(response.url)) { throw new Error('login'); }
      if (!response.ok) {
        item.error = 'Refusé par le serveur (erreur ' + response.status + '). Ouvrez la page pour le refaire.';
        return put(item);
      }
      return response.text().then(function (html) {
        // Renvoyé sur la même page avec une erreur (ex. doublon de client) : à reprendre à la main.
        if (response.url === item.page && /has-error|alert alert-error/.test(html)) {
          item.error = 'Non enregistré : ouvrez la page pour vérifier et renvoyer.';
          return put(item);
        }
        return remove(item.id);
      });
    });
  }

  window.addEventListener('online', function () { refresh(); flush(); });
  window.addEventListener('offline', refresh);
  if (dialog) {
    bar.querySelector('[data-offline-open]').addEventListener('click', function () { refresh(); dialog.showModal(); });
    dialog.querySelector('[data-offline-send]').addEventListener('click', flush);
  }

  // ---- Pages à garder sur le téléphone ----
  function download(limit, progress) {
    return fetch(pagesUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (json) {
        var urls = limit ? json.urls.slice(0, limit) : json.urls;
        var done = 0;
        var next = 0;
        function worker() {
          if (next >= urls.length) { return Promise.resolve(); }
          var url = urls[next++];
          return fetch(url, { credentials: 'same-origin' }).then(function (response) {
            if (response.ok && !response.redirected && window.caches) {
              return caches.open('mc-pages-v1').then(function (cache) { return cache.put(url, response); });
            }
          }).catch(function () {}).then(function () {
            done++;
            if (progress) { progress(done, urls.length); }
            return worker();
          });
        }
        return Promise.all([worker(), worker(), worker()]).then(function () { return urls.length; });
      });
  }

  var button = document.querySelector('[data-offline-download]');
  if (button) {
    var status = document.querySelector('[data-offline-status]');
    button.addEventListener('click', function () {
      button.disabled = true;
      navigator.serviceWorker.ready.then(function () {
        return download(0, function (done, total) { status.textContent = 'Téléchargement… ' + done + ' / ' + total; });
      }).then(function (count) {
        status.textContent = count + ' pages enregistrées sur ce téléphone (' + new Date().toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' }) + ').';
        try { localStorage.setItem('offline-full', String(Date.now())); } catch (e) { /* ignoré */ }
      }).catch(function () {
        status.textContent = 'Téléchargement interrompu : vérifiez le réseau et réessayez.';
      }).then(function () { button.disabled = false; });
    });
  }

  // Une fois par jour, les pages principales sont mises à jour en arrière-plan.
  function dailyRefresh() {
    var last = 0;
    try { last = Number(localStorage.getItem('offline-daily') || 0); } catch (e) { /* ignoré */ }
    if (!navigator.onLine || Date.now() - last < 20 * 3600 * 1000) { return; }
    try { localStorage.setItem('offline-daily', String(Date.now())); } catch (e) { /* ignoré */ }
    navigator.serviceWorker.ready.then(function () { return download(40); }).catch(function () {});
  }

  // Déconnexion : les pages enregistrées sont effacées du téléphone.
  document.querySelectorAll('form[action$="/deconnexion"]').forEach(function (form) {
    form.addEventListener('submit', function () {
      if (navigator.serviceWorker.controller) { navigator.serviceWorker.controller.postMessage('clear-pages'); }
      if (window.caches) { caches.delete('mc-pages-v1'); }
      try { localStorage.removeItem('offline-daily'); } catch (e) { /* ignoré */ }
    });
  });

  refresh();
  flush();
  setTimeout(dailyRefresh, 4000);
})();

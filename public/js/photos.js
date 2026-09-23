// Photos de chantier : compression dans le téléphone, envoi, file d'attente
// hors connexion (IndexedDB) et annotation au doigt.
(function () {
  'use strict';

  var MAX = 2000;
  var QUALITY = 0.85;

  // ---------- Choix d'un chantier (page Photos) ----------

  document.querySelectorAll('[data-go-select]').forEach(function (select) {
    select.addEventListener('change', function () { if (select.value) { window.location = select.value; } });
  });

  // ---------- Compression ----------

  function loadImage(file) {
    if (window.createImageBitmap) {
      return createImageBitmap(file, { imageOrientation: 'from-image' }).catch(function () { return loadWithImg(file); });
    }
    return loadWithImg(file);
  }
  function loadWithImg(file) {
    return new Promise(function (resolve, reject) {
      var img = new Image();
      img.onload = function () { resolve(img); };
      img.onerror = function () { reject(new Error('Image illisible')); };
      img.src = URL.createObjectURL(file);
    });
  }
  function compress(file) {
    return loadImage(file).then(function (img) {
      var w = img.width, h = img.height, ratio = Math.min(1, MAX / Math.max(w, h));
      var canvas = document.createElement('canvas');
      canvas.width = Math.round(w * ratio);
      canvas.height = Math.round(h * ratio);
      var ctx = canvas.getContext('2d');
      ctx.fillStyle = '#fff';
      ctx.fillRect(0, 0, canvas.width, canvas.height);
      ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
      return new Promise(function (resolve) { canvas.toBlob(resolve, 'image/jpeg', QUALITY); });
    });
  }

  // ---------- File d'attente hors connexion ----------

  var DB_NAME = 'photos-en-attente';
  function db() {
    return new Promise(function (resolve, reject) {
      if (!window.indexedDB) { reject(new Error('indexedDB indisponible')); return; }
      var req = indexedDB.open(DB_NAME, 1);
      req.onupgradeneeded = function () { req.result.createObjectStore('queue', { keyPath: 'id', autoIncrement: true }); };
      req.onsuccess = function () { resolve(req.result); };
      req.onerror = function () { reject(req.error); };
    });
  }
  function tx(mode, fn) {
    return db().then(function (d) {
      return new Promise(function (resolve, reject) {
        var t = d.transaction('queue', mode);
        var result = fn(t.objectStore('queue'));
        t.oncomplete = function () { resolve(result && result.result !== undefined ? result.result : result); };
        t.onerror = function () { reject(t.error); };
      });
    });
  }
  function enqueue(item) { return tx('readwrite', function (s) { return s.add(item); }); }
  function removeQueued(id) { return tx('readwrite', function (s) { return s.delete(id); }); }
  function allQueued() { return tx('readonly', function (s) { return s.getAll(); }); }

  function send(item) {
    var data = new FormData();
    data.append('_token', item.token);
    data.append('category', item.category);
    data.append('caption', item.caption || '');
    data.append('photos[]', item.blob, item.name);
    return fetch(item.url, { method: 'POST', body: data, headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(function (response) {
        if (response.ok) { return 'ok'; }
        if (response.status === 419 || response.status >= 500) { throw new Error('retry'); }
        return response.json().then(function (json) { throw new Error(json.message || 'Envoi refusé'); }, function () { throw new Error('Envoi refusé'); });
      });
  }

  var flushing = false;
  function flush() {
    if (flushing || !navigator.onLine) { return Promise.resolve(0); }
    flushing = true;
    return allQueued().then(function (items) {
      var sent = 0;
      return items.reduce(function (p, item) {
        return p.then(function () {
          return send(item).then(function () { sent++; return removeQueued(item.id); }, function () { /* on réessaiera */ });
        });
      }, Promise.resolve()).then(function () { return sent; });
    }).catch(function () { return 0; }).then(function (sent) {
      flushing = false;
      if (sent) { showPending(); if (document.querySelector('[data-photo-upload]')) { window.location.reload(); } }
      return sent;
    });
  }

  function showPending() {
    allQueued().then(function (items) {
      document.querySelectorAll('[data-photo-status]').forEach(function (el) {
        if (items.length) { el.textContent = items.length + ' photo(s) en attente d\'envoi (pas de réseau) : elles partiront automatiquement.'; }
      });
    }).catch(function () {});
  }

  window.addEventListener('online', flush);
  flush();
  showPending();

  // ---------- Envoi depuis le formulaire ----------

  document.querySelectorAll('[data-photo-upload]').forEach(function (form) {
    var input = form.querySelector('[data-photo-input]');
    var status = form.querySelector('[data-photo-status]');

    input.addEventListener('change', function () {
      var files = Array.prototype.slice.call(input.files || []);
      if (!files.length) { return; }
      var base = {
        url: form.action,
        token: form.querySelector('[name="_token"]').value,
        category: form.querySelector('[name="category"]').value,
        caption: form.querySelector('[name="caption"]').value
      };
      var done = 0, queued = 0, errors = [];
      status.textContent = 'Préparation de ' + files.length + ' photo(s)…';

      files.reduce(function (p, file, index) {
        return p.then(function () {
          status.textContent = 'Envoi de la photo ' + (index + 1) + ' sur ' + files.length + '…';
          return compress(file).then(function (blob) {
            var item = Object.assign({ blob: blob, name: (file.name || 'photo').replace(/\.[^.]+$/, '') + '.jpg' }, base);
            return send(item).then(function () { done++; }, function (error) {
              if (error.message === 'retry' || error instanceof TypeError) {
                return enqueue(item).then(function () { queued++; });
              }
              errors.push(error.message);
            });
          }, function () { errors.push((file.name || 'photo') + ' : image illisible'); });
        });
      }, Promise.resolve()).then(function () {
        input.value = '';
        if (errors.length) { status.textContent = errors.join(' — '); }
        else if (queued) { status.textContent = queued + ' photo(s) en attente : elles partiront dès le retour du réseau.'; }
        if (done && !errors.length && !queued) { window.location.reload(); }
      });
    });
  });

  // ---------- Annotation ----------

  var dialog = document.getElementById('annotator');
  if (!dialog) { return; }
  var canvas = dialog.querySelector('[data-annotate-canvas]');
  var ctx = canvas.getContext('2d');
  var strokes = [], current = null, color = '#E53935', image = null, action = null;

  function redraw() {
    ctx.drawImage(image, 0, 0, canvas.width, canvas.height);
    strokes.forEach(function (s) {
      ctx.strokeStyle = s.color;
      ctx.lineWidth = s.width;
      ctx.lineCap = 'round';
      ctx.lineJoin = 'round';
      ctx.beginPath();
      s.points.forEach(function (pt, i) { if (i) { ctx.lineTo(pt[0], pt[1]); } else { ctx.moveTo(pt[0], pt[1]); } });
      ctx.stroke();
    });
  }
  function point(event) {
    var rect = canvas.getBoundingClientRect();
    return [(event.clientX - rect.left) * canvas.width / rect.width, (event.clientY - rect.top) * canvas.height / rect.height];
  }

  document.querySelectorAll('[data-annotate]').forEach(function (button) {
    button.addEventListener('click', function () {
      action = button.getAttribute('data-annotate-action');
      strokes = [];
      var img = new Image();
      img.onload = function () {
        image = img;
        var ratio = Math.min(1, MAX / Math.max(img.width, img.height));
        canvas.width = Math.round(img.width * ratio);
        canvas.height = Math.round(img.height * ratio);
        redraw();
      };
      img.src = button.getAttribute('data-annotate');
      dialog.showModal();
    });
  });

  dialog.querySelectorAll('[data-color]').forEach(function (b) {
    b.addEventListener('click', function () {
      color = b.getAttribute('data-color');
      dialog.querySelectorAll('[data-color]').forEach(function (o) { o.classList.toggle('is-active', o === b); });
    });
  });
  dialog.querySelector('[data-annotate-undo]').addEventListener('click', function () { strokes.pop(); redraw(); });

  canvas.addEventListener('pointerdown', function (event) {
    event.preventDefault();
    canvas.setPointerCapture(event.pointerId);
    current = { color: color, width: Math.max(4, canvas.width / 150), points: [point(event)] };
    strokes.push(current);
  });
  canvas.addEventListener('pointermove', function (event) {
    if (!current) { return; }
    current.points.push(point(event));
    redraw();
  });
  ['pointerup', 'pointercancel'].forEach(function (type) { canvas.addEventListener(type, function () { current = null; }); });

  dialog.querySelector('[data-annotate-save]').addEventListener('click', function () {
    if (!strokes.length) { dialog.close(); return; }
    var token = document.querySelector('[name="_token"]').value;
    canvas.toBlob(function (blob) {
      var data = new FormData();
      data.append('_token', token);
      data.append('image', blob, 'annotation.jpg');
      fetch(action, { method: 'POST', body: data, headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { if (!r.ok) { throw new Error(); } window.location.reload(); })
        .catch(function () { window.alert('L\'annotation n\'a pas pu être enregistrée. Vérifiez la connexion et réessayez.'); });
    }, 'image/jpeg', QUALITY);
  });
})();

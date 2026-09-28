// Comportements de l'interface. Aucun framework : chaque bloc est autonome.
(function () {
  'use strict';

  // Bascule clair / sombre.
  document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
    button.addEventListener('click', function () {
      var root = document.documentElement;
      var current = root.getAttribute('data-theme')
        || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
      var next = current === 'dark' ? 'light' : 'dark';
      root.setAttribute('data-theme', next);
      try { localStorage.setItem('theme', next); } catch (e) { /* ignoré */ }
    });
  });

  // Options d'affichage de ce téléphone (menu Plus) : grands boutons.
  document.querySelectorAll('[data-pref-toggle]').forEach(function (button) {
    var name = button.getAttribute('data-pref-toggle');
    var attribute = 'data-' + name;
    var sync = function () { button.setAttribute('aria-pressed', document.documentElement.hasAttribute(attribute) ? 'true' : 'false'); };
    sync();
    button.addEventListener('click', function () {
      var on = !document.documentElement.hasAttribute(attribute);
      if (on) { document.documentElement.setAttribute(attribute, ''); } else { document.documentElement.removeAttribute(attribute); }
      try { localStorage.setItem('pref-' + name, on ? '1' : '0'); } catch (e) { /* ignoré */ }
      sync();
    });
  });

  // Feuilles d'actions (bouton « + » et menu « Plus »).
  document.querySelectorAll('[data-open-sheet]').forEach(function (button) {
    button.addEventListener('click', function () {
      var sheet = document.getElementById(button.getAttribute('data-open-sheet'));
      if (sheet && sheet.showModal) { sheet.showModal(); }
    });
  });
  document.querySelectorAll('dialog.sheet').forEach(function (sheet) {
    sheet.addEventListener('click', function (event) {
      if (event.target === sheet) { sheet.close(); }
    });
    sheet.querySelectorAll('[data-close-sheet]').forEach(function (button) {
      button.addEventListener('click', function () { sheet.close(); });
    });
  });

  // Champs couleur : le sélecteur et la saisie hexadécimale restent synchronisés.
  document.querySelectorAll('.color-field').forEach(function (field) {
    var picker = field.querySelector('input[type="color"]');
    var text = field.querySelector('input[type="text"]');
    if (!picker || !text) { return; }
    picker.addEventListener('input', function () {
      text.value = picker.value.toUpperCase();
      text.dispatchEvent(new Event('input'));
    });
    text.addEventListener('input', function () {
      if (/^#[0-9A-Fa-f]{6}$/.test(text.value)) { picker.value = text.value; }
      var target = text.getAttribute('data-preview-var');
      if (target && /^#[0-9A-Fa-f]{6}$/.test(text.value)) {
        document.documentElement.style.setProperty(target, text.value);
      }
    });
  });

  // Aperçu du logo avant envoi.
  document.querySelectorAll('input[type="file"][data-preview]').forEach(function (input) {
    input.addEventListener('change', function () {
      var img = document.getElementById(input.getAttribute('data-preview'));
      if (img && input.files && input.files[0]) {
        img.src = URL.createObjectURL(input.files[0]);
        img.hidden = false;
      }
    });
  });

  // Demande de confirmation avant une action sensible.
  document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      if (!window.confirm(form.getAttribute('data-confirm'))) { event.preventDefault(); }
    });
  });

  // Message au client par WhatsApp, SMS ou copie (texte modifiable).
  document.querySelectorAll('[data-share]').forEach(function (box) {
    var text = box.querySelector('[data-share-message]');
    var trackUrl = box.getAttribute('data-track');
    var track = function (channel) {
      if (!trackUrl) { return; }
      var data = new FormData();
      data.append('_token', box.querySelector('[name="_token"]').value);
      data.append('channel', channel);
      if (navigator.sendBeacon) { navigator.sendBeacon(trackUrl, data); }
      else { fetch(trackUrl, { method: 'POST', body: data, credentials: 'same-origin', keepalive: true }); }
    };
    box.querySelectorAll('[data-share-to]').forEach(function (button) {
      button.addEventListener('click', function (event) {
        var message = text.value.trim();
        var kind = button.getAttribute('data-share-to');
        var phone = button.getAttribute('data-phone') || '';
        track(kind);
        if (kind === 'copy') {
          event.preventDefault();
          var done = function () { var old = button.innerHTML; button.textContent = 'Message copié ✓'; setTimeout(function () { button.innerHTML = old; }, 2000); };
          if (navigator.clipboard) { navigator.clipboard.writeText(message).then(done, function () { text.select(); }); }
          else { text.select(); document.execCommand('copy'); done(); }
          return;
        }
        if (kind === 'whatsapp') {
          button.href = 'https://wa.me/' + phone + '?text=' + encodeURIComponent(message);
          button.target = '_blank';
        } else {
          // iPhone : « sms:numéro&body= » ; Android : « sms:numéro?body= ».
          var separator = /iPhone|iPad/.test(navigator.userAgent) ? '&' : '?';
          button.href = 'sms:' + phone + separator + 'body=' + encodeURIComponent(message);
        }
      });
    });
  });

  // Copier un lien dans le presse-papiers.
  document.querySelectorAll('[data-copy]').forEach(function (button) {
    button.addEventListener('click', function () {
      var source = button.parentElement.querySelector('[data-copy-source]');
      var done = function () { button.textContent = 'Copié ✓'; setTimeout(function () { button.textContent = 'Copier'; }, 2000); };
      if (navigator.clipboard) { navigator.clipboard.writeText(source.value).then(done, function () { source.select(); }); }
      else { source.select(); document.execCommand('copy'); done(); }
    });
  });

  // Envoi immédiat d'un fichier choisi (documents du client).
  document.querySelectorAll('[data-autosubmit]').forEach(function (input) {
    input.addEventListener('change', function () { if (input.files.length) { input.form.submit(); } });
  });

  // Facturer un devis : le pourcentage ne concerne que l'acompte et la situation.
  document.querySelectorAll('[data-percent-field]').forEach(function (field) {
    var form = field.closest('form');
    function refresh() {
      var checked = form.querySelector('input[name="kind"]:checked');
      field.hidden = !checked || !checked.hasAttribute('data-kind-percent');
    }
    form.addEventListener('change', refresh);
    refresh();
  });

  // Formulaire client : les champs « société » ne concernent que les professionnels.
  document.querySelectorAll('[data-client-type]').forEach(function (group) {
    var form = group.closest('form');
    function refresh() {
      var checked = group.querySelector('input:checked');
      var individual = !checked || checked.value === 'particulier';
      form.querySelectorAll('[data-pro-only]').forEach(function (el) {
        (el.closest('.field') || el).classList.toggle('is-hidden', individual);
      });
    }
    group.addEventListener('change', refresh);
    refresh();
  });

  // Chantier : reprendre l'adresse du client.
  document.querySelectorAll('[data-fill-address]').forEach(function (button) {
    button.addEventListener('click', function () {
      var values = JSON.parse(button.getAttribute('data-fill-address'));
      var form = button.closest('form');
      Object.keys(values).forEach(function (name) {
        var input = form.querySelector('[name="' + name + '"]');
        if (input && values[name]) { input.value = values[name]; }
      });
    });
  });

  // Suggestions d'adresses (Base Adresse Nationale) : remplit aussi le code postal et la ville.
  document.querySelectorAll('[data-address-autocomplete]').forEach(function (input) {
    var form = input.closest('form');
    var field = input.closest('.field');
    field.classList.add('address-field');
    var list = document.createElement('ul');
    list.className = 'suggestions';
    list.setAttribute('role', 'listbox');
    list.hidden = true;
    field.appendChild(list);
    input.setAttribute('aria-autocomplete', 'list');
    var timer = null;
    var items = [];
    var active = -1;

    function close() { list.hidden = true; active = -1; }
    function choose(index) {
      var props = items[index];
      if (!props) { return; }
      input.value = props.name;
      var postal = form.querySelector('[name="postal_code"]');
      var city = form.querySelector('[name="city"]');
      if (postal) { postal.value = props.postcode || ''; }
      if (city) { city.value = props.city || ''; }
      close();
    }
    function render() {
      list.innerHTML = '';
      items.forEach(function (props, index) {
        var li = document.createElement('li');
        li.setAttribute('role', 'option');
        li.setAttribute('aria-selected', index === active ? 'true' : 'false');
        li.textContent = props.name;
        var small = document.createElement('span');
        small.className = 'small';
        small.textContent = (props.postcode || '') + ' ' + (props.city || '');
        li.appendChild(small);
        li.addEventListener('mousedown', function (event) { event.preventDefault(); choose(index); });
        list.appendChild(li);
      });
      list.hidden = items.length === 0;
    }

    input.addEventListener('input', function () {
      clearTimeout(timer);
      var q = input.value.trim();
      if (q.length < 4) { close(); return; }
      timer = setTimeout(function () {
        // lat/lon : les adresses proches de l'entreprise (Essonne) sont proposées en premier.
        fetch('https://data.geopf.fr/geocodage/search?limit=5&autocomplete=1&lat=48.70&lon=2.25&q=' + encodeURIComponent(q))
          .then(function (response) { return response.ok ? response.json() : Promise.reject(); })
          .then(function (data) {
            items = (data.features || []).map(function (f) { return f.properties; })
              .filter(function (p) { return p.type === 'housenumber' || p.type === 'street'; });
            active = -1;
            render();
          })
          .catch(function () { close(); /* hors ligne : saisie manuelle */ });
      }, 250);
    });
    input.addEventListener('keydown', function (event) {
      if (list.hidden) { return; }
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        active = (active + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
        render();
      } else if (event.key === 'Enter' && active >= 0) {
        event.preventDefault();
        choose(active);
      } else if (event.key === 'Escape') {
        close();
      }
    });
    input.addEventListener('blur', function () { setTimeout(close, 150); });
  });

  // Recherche instantanée : les résultats se mettent à jour pendant la saisie.
  document.querySelectorAll('[data-live-search]').forEach(function (input) {
    var target = document.getElementById(input.getAttribute('data-live-search'));
    var form = input.closest('form');
    var timer = null;
    var controller = null;
    input.addEventListener('input', function () {
      clearTimeout(timer);
      timer = setTimeout(function () {
        if (controller) { controller.abort(); }
        controller = new AbortController();
        var url = form.action + '?partial=1&q=' + encodeURIComponent(input.value);
        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, signal: controller.signal })
          .then(function (response) { return response.ok ? response.text() : Promise.reject(); })
          .then(function (html) {
            target.innerHTML = html;
            history.replaceState(null, '', form.action + '?q=' + encodeURIComponent(input.value));
          })
          .catch(function () { /* requête annulée ou hors ligne : on garde l'affichage */ });
      }, 200);
    });
  });
  // Formulaires longs à traiter : bouton désactivé et message d'attente.
  document.querySelectorAll('form[data-busy]').forEach(function (form) {
    form.addEventListener('submit', function () {
      form.querySelectorAll('[type="submit"]').forEach(function (button) {
        button.disabled = true;
        button.textContent = form.getAttribute('data-busy');
      });
    });
  });

  // Bouton « Retour » : revient à la page précédente si elle est dans l'application
  // (et n'est pas un formulaire déjà enregistré), sinon va à la page parente.
  document.querySelectorAll('[data-back]').forEach(function (link) {
    link.addEventListener('click', function (event) {
      var ref = document.referrer;
      if (!ref || window.history.length < 2) { return; }
      try {
        var previous = new URL(ref);
        if (previous.origin !== window.location.origin || previous.href === window.location.href) { return; }
        if (/\/(nouveau|modifier|connexion)(\/|$|\?)/.test(previous.pathname + previous.search)) { return; }
      } catch (e) { return; }
      event.preventDefault();
      window.history.back();
    });
  });

  // Barre d'actions : un bouton peut valider un formulaire de la page (avec sa confirmation).
  document.querySelectorAll('[data-quick-submit]').forEach(function (button) {
    button.addEventListener('click', function () {
      var form = document.getElementById(button.getAttribute('data-quick-submit'));
      if (form) { form.requestSubmit ? form.requestSubmit() : form.submit(); }
    });
  });

  // Glisser vers la gauche une ligne (planning, devis, factures) : affiche ses actions rapides.
  var openSwipe = null;
  function closeSwipe(row) {
    if (!row) { return; }
    row.classList.remove('is-open');
    row.querySelector('.swipe-content').style.transform = '';
    if (openSwipe === row) { openSwipe = null; }
  }
  document.querySelectorAll('[data-swipe]').forEach(function (row) {
    var content = row.querySelector('.swipe-content');
    var actions = row.querySelector('.swipe-actions');
    if (!content || !actions) { return; }
    var startX = 0, startY = 0, dx = 0, dragging = false, decided = false;
    row.addEventListener('touchstart', function (event) {
      if (openSwipe && openSwipe !== row) { closeSwipe(openSwipe); }
      startX = event.touches[0].clientX; startY = event.touches[0].clientY;
      dx = 0; dragging = false; decided = false;
      content.style.transition = 'none';
    }, { passive: true });
    row.addEventListener('touchmove', function (event) {
      var x = event.touches[0].clientX - startX, y = event.touches[0].clientY - startY;
      if (!decided) {
        if (Math.abs(x) < 8 && Math.abs(y) < 8) { return; }
        decided = true;
        dragging = Math.abs(x) > Math.abs(y);
      }
      if (!dragging) { return; }
      event.preventDefault();
      var base = row.classList.contains('is-open') ? -actions.offsetWidth : 0;
      dx = Math.max(-actions.offsetWidth - 24, Math.min(0, base + x));
      content.style.transform = 'translateX(' + dx + 'px)';
    }, { passive: false });
    row.addEventListener('touchend', function () {
      content.style.transition = '';
      if (!dragging) { return; }
      if (dx < -actions.offsetWidth / 2) {
        row.classList.add('is-open');
        content.style.transform = 'translateX(' + (-actions.offsetWidth) + 'px)';
        openSwipe = row;
      } else {
        closeSwipe(row);
      }
    });
    // Ligne ouverte : un appui la referme au lieu d'ouvrir la fiche.
    content.addEventListener('click', function (event) {
      if (row.classList.contains('is-open')) { event.preventDefault(); closeSwipe(row); }
    });
  });
})();

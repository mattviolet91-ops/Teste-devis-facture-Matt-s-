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
})();

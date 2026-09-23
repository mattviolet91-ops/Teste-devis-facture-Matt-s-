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

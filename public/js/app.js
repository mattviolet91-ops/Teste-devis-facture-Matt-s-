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
})();

// Guide : recherche dans les rubriques et ouverture de la rubrique choisie dans le sommaire.
(function () {
  'use strict';

  var sections = Array.prototype.slice.call(document.querySelectorAll('[data-guide-section]'));
  var input = document.querySelector('[data-guide-search]');
  var empty = document.querySelector('[data-guide-empty]');
  if (!sections.length) { return; }

  function normalize(text) {
    return text.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
  }

  function openFromHash() {
    var target = location.hash ? document.getElementById(location.hash.slice(1)) : null;
    if (target && target.hasAttribute('data-guide-section')) {
      target.open = true;
      target.scrollIntoView({ block: 'start' });
    }
  }

  if (input) {
    input.addEventListener('input', function () {
      var terms = normalize(input.value).split(/\s+/).filter(Boolean);
      var shown = 0;
      sections.forEach(function (section) {
        var text = normalize(section.textContent);
        var match = terms.every(function (t) { return text.indexOf(t) !== -1; });
        section.hidden = !match;
        section.open = terms.length > 0 && match;
        if (match) { shown++; }
      });
      if (empty) { empty.hidden = shown > 0; }
    });
  }

  window.addEventListener('hashchange', openFromHash);
  openFromHash();
})();

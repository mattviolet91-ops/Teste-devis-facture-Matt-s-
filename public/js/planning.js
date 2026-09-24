// Formulaire du planning : chantier ou rendez-vous, chantiers et devis du client choisi.
(function () {
  'use strict';

  var form = document.querySelector('[data-planning-form]');
  if (!form) { return; }

  var client = form.querySelector('[data-planning-client]');
  var site = form.querySelector('[data-planning-worksite]');
  var quote = form.querySelector('[data-planning-quote]');
  var title = form.querySelector('#title');
  var data = JSON.parse((document.getElementById('planning-worksites') || {}).textContent || '{}');

  function kind() {
    var checked = form.querySelector('[data-planning-kind]:checked');
    return checked ? checked.value : 'chantier';
  }

  function applyKind() {
    var k = kind();
    form.querySelectorAll('[data-kind-only]').forEach(function (el) { el.hidden = el.getAttribute('data-kind-only') !== k; });
    client.required = k !== 'rdv';
    client.options[0].textContent = k === 'rdv' ? 'Aucun client (fournisseur, comptable…)' : 'Choisir un client…';
    // Les objets types ne sont proposés que pour les rendez-vous.
    if (k === 'rdv') { title.setAttribute('list', 'appointment-titles'); } else { title.removeAttribute('list'); }
  }

  function fill() {
    var keep = site.getAttribute('data-selected');
    site.length = 1;
    (data[client.value] || []).forEach(function (w, i) {
      var o = new Option(w.label, w.id);
      if (String(w.id) === keep || (!keep && i === 0)) { o.selected = true; }
      site.add(o);
    });
    Array.prototype.forEach.call(quote.options, function (o) {
      o.hidden = Boolean(o.value && client.value && o.getAttribute('data-client') !== client.value);
    });
  }

  client.addEventListener('change', function () {
    site.setAttribute('data-selected', '');
    if (quote.selectedOptions[0] && quote.selectedOptions[0].hidden) { quote.value = ''; }
    fill();
  });
  quote.addEventListener('change', function () {
    var o = quote.selectedOptions[0];
    if (!o || !o.value) { return; }
    if (client.value !== o.getAttribute('data-client')) {
      client.value = o.getAttribute('data-client');
      site.setAttribute('data-selected', '');
      fill();
    }
    if (!title.value && kind() === 'chantier' && o.getAttribute('data-title')) { title.value = o.getAttribute('data-title'); }
  });
  form.querySelectorAll('[data-planning-kind]').forEach(function (r) { r.addEventListener('change', applyKind); });

  fill();
  applyKind();
})();

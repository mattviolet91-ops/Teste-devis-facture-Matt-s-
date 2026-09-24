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
    form.querySelectorAll('[data-kind-only]').forEach(function (el) {
      el.hidden = el.getAttribute('data-kind-only') !== k;
      // Un champ caché n'est pas envoyé.
      el.querySelectorAll('input, select, textarea').forEach(function (f) { f.disabled = el.hidden; });
    });
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

  // Recherche d'un client : chaque mot doit se retrouver dans le nom, le téléphone, l'email ou la ville.
  var search = form.querySelector('[data-client-search]');
  var results = form.querySelector('[data-client-results]');
  function normalize(text) { return String(text).toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, ''); }
  if (search) {
    search.addEventListener('input', function () {
      var terms = normalize(search.value.replace(/[\s.]/g, function (c) { return c === '.' ? '' : ' '; })).split(/\s+/).filter(Boolean);
      results.textContent = '';
      if (!terms.length) { results.hidden = true; return; }
      var matches = Array.prototype.filter.call(client.options, function (option) {
        var haystack = option.getAttribute('data-search') || '';
        return option.value && terms.every(function (t) { return haystack.indexOf(t) !== -1; });
      }).slice(0, 8);
      if (!matches.length) {
        var none = document.createElement('p');
        none.className = 'small muted';
        none.textContent = 'Aucun client trouvé.';
        results.appendChild(none);
      }
      matches.forEach(function (option) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'client-search-item';
        button.textContent = option.textContent;
        button.addEventListener('click', function () {
          client.value = option.value;
          client.dispatchEvent(new Event('change'));
          search.value = '';
          results.hidden = true;
        });
        results.appendChild(button);
      });
      results.hidden = false;
    });
    // Entrée dans la recherche : ne pas envoyer le formulaire.
    search.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); } });
  }

  // La fin ne peut pas précéder le début.
  var starts = form.querySelector('[name="starts_on"]');
  var ends = form.querySelector('[name="ends_on"]');
  if (starts && ends) {
    starts.addEventListener('change', function () {
      if (ends.value && ends.value < starts.value) { ends.value = ''; }
      ends.min = starts.value;
    });
    ends.min = starts.value;
  }

  fill();
  applyKind();
})();

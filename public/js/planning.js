// Formulaire du planning : chantier ou rendez-vous, chantiers et devis du client choisi.
(function () {
  'use strict';

  var form = document.querySelector('[data-planning-form]');
  if (!form) { return; }

  var client = form.querySelector('[data-planning-client]');
  var site = form.querySelector('[data-planning-worksite]');
  var project = form.querySelector('[data-planning-project]');
  var quote = form.querySelector('[data-planning-quote]');
  var invoice = form.querySelector('[data-planning-invoice]');
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

  // Les options sont reconstruites (et non masquées : l'iPhone affiche quand même les options masquées).
  function rebuild(select, items, keep, firstIfNone) {
    if (!select) { return; }
    select.length = 1;
    items.forEach(function (item, i) {
      var o = new Option(item.label, item.id);
      if (String(item.id) === String(keep || '') || (!keep && firstIfNone && i === 0)) { o.selected = true; }
      select.add(o);
    });
  }
  function links() { return data[client.value] || { worksites: [], projects: [], quotes: [], invoices: [] }; }
  // Un chantier choisi : seulement ses devis et factures.
  function inProject(items) {
    return project && project.value ? items.filter(function (i) { return String(i.project) === String(project.value); }) : items;
  }
  function fillDocuments() {
    var l = links();
    rebuild(quote, inProject(l.quotes), quote.value || quote.getAttribute('data-selected'));
    if (invoice) { rebuild(invoice, inProject(l.invoices), invoice.value || invoice.getAttribute('data-selected')); }
    quote.setAttribute('data-selected', '');
    if (invoice) { invoice.setAttribute('data-selected', ''); }
  }
  function fill() {
    var l = links();
    rebuild(site, l.worksites, site.getAttribute('data-selected'), true);
    rebuild(project, l.projects, project ? project.getAttribute('data-selected') : null);
    if (project) { project.setAttribute('data-selected', ''); }
    fillDocuments();
  }
  function find(items, id) { return items.filter(function (i) { return String(i.id) === String(id); })[0]; }

  client.addEventListener('change', function () {
    site.setAttribute('data-selected', '');
    quote.value = ''; if (invoice) { invoice.value = ''; } if (project) { project.value = ''; }
    fill();
  });
  if (project) {
    project.addEventListener('change', function () {
      var l = links();
      // Devis ou facture d'un autre chantier : retirés.
      if (quote.value && project.value && String((find(l.quotes, quote.value) || {}).project) !== project.value) { quote.value = ''; }
      if (invoice && invoice.value && project.value && String((find(l.invoices, invoice.value) || {}).project) !== project.value) { invoice.value = ''; }
      fillDocuments();
    });
  }
  // Devis ou facture choisi : son chantier est choisi aussi.
  function pickProject(item) {
    if (project && item && item.project && project.value !== String(item.project)) {
      project.value = String(item.project);
      fillDocuments();
    }
  }
  quote.addEventListener('change', function () {
    var q = find(links().quotes, quote.value);
    if (!q) { return; }
    pickProject(q);
    if (q.worksite) { site.value = String(q.worksite); }
    if (!title.value && kind() === 'chantier' && q.title) { title.value = q.title; }
  });
  if (invoice) { invoice.addEventListener('change', function () { pickProject(find(links().invoices, invoice.value)); }); }
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

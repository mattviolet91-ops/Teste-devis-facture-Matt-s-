// Éditeur de devis : ajout / déplacement / suppression des lignes, bibliothèque,
// étapes types et calcul des totaux en direct. Le serveur recalcule tout à
// l'enregistrement : l'affichage ici n'est qu'un aperçu.
(function () {
  'use strict';

  var form = document.querySelector('[data-quote-editor]');
  if (!form) { return; }

  var data = JSON.parse(document.getElementById('quote-editor-data').textContent);
  var linesBox = form.querySelector('[data-lines]');
  var emptyHint = form.querySelector('[data-empty-lines]');
  var euro = new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' });

  // ---------- Nombres saisis à la française ----------

  function parseDecimal(value, decimals) {
    value = String(value || '').replace(/[\s  €%]/g, '').replace(',', '.');
    if (value === '') { return 0; }
    if (!/^\d+(\.\d+)?$/.test(value)) { return null; }
    var factor = Math.pow(10, decimals);
    return Math.round(parseFloat(value) * factor);
  }
  function roundDiv(a, b) { return Math.floor((a + Math.floor(b / 2)) / b); }
  function money(cents) { return euro.format(cents / 100); }

  // ---------- Numérotation des champs (ordre visuel = ordre enregistré) ----------

  function lineElements() { return Array.prototype.slice.call(linesBox.querySelectorAll('[data-line]')); }

  function renumber() {
    lineElements().forEach(function (line, index) {
      line.querySelectorAll('[name^="lines["]').forEach(function (field) {
        field.name = field.name.replace(/^lines\[[^\]]*\]/, 'lines[' + index + ']');
      });
    });
    emptyHint.hidden = lineElements().length > 0;
  }

  // ---------- Ajout de lignes ----------

  function createLine(type, values) {
    var template = document.getElementById('tpl-line-' + type);
    var node = template.content.firstElementChild.cloneNode(true);
    if (values) {
      Object.keys(values).forEach(function (key) {
        var field = node.querySelector('[name$="[' + key + ']"]');
        if (!field) { return; }
        if (field.type === 'checkbox') { field.checked = !!values[key]; } else { field.value = values[key]; }
      });
    }
    linesBox.appendChild(node);
    renumber();
    autogrow(node);
    recalc();
    node.scrollIntoView({ behavior: 'smooth', block: 'center' });
    return node;
  }

  form.querySelectorAll('[data-add]').forEach(function (button) {
    button.addEventListener('click', function () {
      var node = createLine(button.getAttribute('data-add'));
      var first = node.querySelector('.line-title, textarea');
      if (first) { first.focus({ preventScroll: true }); }
    });
  });

  // ---------- Actions sur une ligne ----------

  linesBox.addEventListener('click', function (event) {
    var button = event.target.closest('button');
    if (!button) { return; }
    var line = button.closest('[data-line]');
    if (!line) { return; }

    if (button.hasAttribute('data-remove')) {
      var title = (line.querySelector('.line-title') || {}).value;
      if (window.confirm('Supprimer cette ligne' + (title ? ' « ' + title + ' »' : '') + ' ?')) {
        line.remove();
      }
    } else if (button.hasAttribute('data-duplicate')) {
      var copy = line.cloneNode(true);
      line.after(copy);
    } else if (button.getAttribute('data-move') === 'up' && line.previousElementSibling) {
      line.previousElementSibling.before(line);
    } else if (button.getAttribute('data-move') === 'down' && line.nextElementSibling) {
      line.nextElementSibling.after(line);
    } else {
      return;
    }
    renumber();
    recalc();
  });

  // Étapes types : ajoutées à la fin du détail de la ligne.
  linesBox.addEventListener('change', function (event) {
    var picker = event.target.closest('[data-step-picker]');
    if (!picker || !picker.value) { return; }
    var textarea = picker.closest('[data-line]').querySelector('textarea');
    textarea.value = (textarea.value.trim() ? textarea.value.replace(/\s+$/, '') + '\n' : '') + picker.value;
    picker.value = '';
    autogrow(textarea);
  });

  // Textes types des conditions et des notes.
  form.querySelectorAll('.template-picker').forEach(function (picker) {
    picker.addEventListener('change', function () {
      if (!picker.value) { return; }
      var target = document.getElementById(picker.getAttribute('data-template-target'));
      if (picker.getAttribute('data-template-mode') === 'replace' || !target.value.trim()) {
        target.value = picker.value;
      } else {
        target.value = target.value.replace(/\s+$/, '') + '\n' + picker.value;
      }
      picker.value = '';
      autogrow(target);
      target.focus();
    });
  });

  // ---------- Zones de texte qui s'agrandissent ----------

  function autogrow(root) {
    var areas = root.matches && root.matches('textarea') ? [root] : root.querySelectorAll('textarea[data-autogrow]');
    Array.prototype.forEach.call(areas, function (area) {
      area.style.height = 'auto';
      area.style.height = (area.scrollHeight + 2) + 'px';
    });
  }
  form.addEventListener('input', function (event) {
    if (event.target.matches('textarea[data-autogrow]')) { autogrow(event.target); }
  });
  autogrow(form);

  // ---------- Client → chantiers ----------

  var clientSelect = form.querySelector('[data-client-select]');
  var worksiteSelect = form.querySelector('[data-worksite-select]');
  function fillWorksites(keepSelected) {
    var selected = keepSelected ? worksiteSelect.getAttribute('data-selected') : null;
    var sites = data.worksites[clientSelect.value] || [];
    worksiteSelect.innerHTML = '<option value="">— Aucun —</option>';
    sites.forEach(function (site, index) {
      var option = new Option(site.label, site.id);
      if (String(site.id) === String(selected) || (!selected && index === 0)) { option.selected = true; }
      worksiteSelect.appendChild(option);
    });
  }
  clientSelect.addEventListener('change', function () { fillWorksites(false); });
  fillWorksites(true);

  // ---------- Bibliothèque ----------

  var dialog = document.getElementById('catalog-dialog');
  var search = document.getElementById('catalog-search');
  var list = dialog.querySelector('[data-catalog-list]');

  function normalize(text) { return String(text).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); }

  function renderCatalog() {
    var terms = normalize(search.value).split(/\s+/).filter(Boolean);
    var groups = {};
    data.catalog.forEach(function (item) {
      var haystack = normalize(item.name + ' ' + item.description + ' ' + item.category);
      if (terms.every(function (t) { return haystack.indexOf(t) !== -1; })) {
        (groups[item.category] = groups[item.category] || []).push(item);
      }
    });
    list.innerHTML = '';
    var names = Object.keys(groups);
    if (!names.length) {
      list.innerHTML = '<p class="muted">Aucune prestation trouvée. Ajoutez une ligne libre.</p>';
      return;
    }
    names.forEach(function (name) {
      var title = document.createElement('h3');
      title.className = 'catalog-group';
      title.textContent = name;
      list.appendChild(title);
      groups[name].forEach(function (item) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'catalog-item';
        var label = document.createElement('span');
        label.textContent = item.name;
        var price = document.createElement('span');
        price.className = 'muted small';
        price.textContent = item.unit_price ? money(item.unit_price) + ' / ' + item.unit : 'prix à saisir';
        button.appendChild(label);
        button.appendChild(price);
        button.addEventListener('click', function () {
          dialog.close();
          createLine('item', {
            title: item.name,
            description: item.description,
            unit: item.unit,
            unit_price: item.unit_price ? (item.unit_price / 100).toFixed(2).replace('.', ',') : '',
            vat_rate: item.vat_rate,
            catalog_item_id: item.id,
            quantity: '1'
          }).querySelector('[name$="[quantity]"]').select();
        });
        list.appendChild(button);
      });
    });
  }

  form.querySelector('[data-open-catalog]').addEventListener('click', function () {
    search.value = '';
    renderCatalog();
    dialog.showModal();
    search.focus();
  });
  search.addEventListener('input', renderCatalog);

  // ---------- Totaux en direct (même règles que le serveur) ----------

  function field(line, key) { return line.querySelector('[name$="[' + key + ']"]'); }

  function recalc() {
    var subtotal = 0, optional = 0, bases = {}, section = null, sectionTotals = [];
    lineElements().forEach(function (line) {
      var type = line.getAttribute('data-type');
      var totalBox = line.querySelector('[data-line-total]');
      if (type === 'section') {
        section = { box: totalBox, total: 0 };
        sectionTotals.push(section);
        return;
      }
      if (type !== 'item') { totalBox.textContent = ''; return; }

      var qty = parseDecimal(field(line, 'quantity').value, 3);
      var price = parseDecimal(field(line, 'unit_price').value, 2);
      var discount = parseDecimal(field(line, 'discount_percent').value, 2);
      if (qty === null || price === null || discount === null) { totalBox.textContent = 'Saisie invalide'; return; }

      var gross = roundDiv(qty * price, 1000);
      var amount = gross - roundDiv(gross * Math.min(discount, 10000), 10000);
      var optionalLine = field(line, 'is_optional').checked;
      var offered = field(line, 'is_offered').checked;
      totalBox.textContent = offered ? 'Offert' : money(amount) + (optionalLine ? ' (option)' : '');
      line.classList.toggle('is-optional', optionalLine);
      line.classList.toggle('is-offered', offered);

      if (optionalLine) { optional += amount; return; }
      if (offered) { return; }
      subtotal += amount;
      var rate = parseInt(field(line, 'vat_rate').value, 10) || 0;
      bases[rate] = (bases[rate] || 0) + amount;
      if (section) { section.total += amount; }
    });
    sectionTotals.forEach(function (s) { s.box.textContent = 'Sous-total ' + money(s.total); });

    var type = form.querySelector('[name="discount_type"]').value;
    var raw = parseDecimal(form.querySelector('[name="discount_value"]').value, 2) || 0;
    var discount = type === 'percent' ? Math.min(subtotal, roundDiv(subtotal * Math.min(raw, 10000), 10000))
      : type === 'amount' ? Math.min(subtotal, raw) : 0;

    // Répartition de la remise sur les taux, comme côté serveur.
    var rates = Object.keys(bases), remaining = discount, vat = 0, vatRows = [];
    rates.forEach(function (rate, index) {
      var share = index === rates.length - 1 ? remaining : (subtotal ? roundDiv(discount * bases[rate], subtotal) : 0);
      share = Math.min(share, bases[rate], remaining);
      remaining -= share;
      var base = bases[rate] - share;
      var amount = data.franchise ? 0 : roundDiv(base * parseInt(rate, 10), 10000);
      vat += amount;
      if (!data.franchise) { vatRows.push([rate, amount]); }
    });

    var totals = form.querySelector('[data-totals]');
    totals.querySelector('[data-total="subtotal"]').textContent = money(subtotal);
    totals.querySelector('[data-total="discount"]').textContent = discount ? '− ' + money(discount) : '—';
    totals.querySelector('[data-total="total_ht"]').textContent = money(subtotal - discount);
    totals.querySelector('[data-total="total_ttc"]').textContent = money(subtotal - discount + vat);
    totals.querySelector('[data-total="optional_total"]').textContent = money(optional);
    totals.querySelector('[data-optional-row]').hidden = optional === 0;
    var vatBox = totals.querySelector('[data-vat-rows]');
    vatBox.innerHTML = '';
    vatRows.sort(function (a, b) { return b[0] - a[0]; }).forEach(function (row) {
      var div = document.createElement('div');
      var dt = document.createElement('dt');
      dt.textContent = 'TVA ' + (row[0] / 100).toLocaleString('fr-FR') + ' %';
      var dd = document.createElement('dd');
      dd.textContent = money(row[1]);
      div.appendChild(dt); div.appendChild(dd);
      vatBox.appendChild(div);
    });
  }

  form.addEventListener('input', function (event) { if (event.target.matches('[data-calc]')) { recalc(); } });
  form.addEventListener('change', function (event) { if (event.target.matches('[data-calc]')) { recalc(); } });

  // Avant l'envoi : champs renumérotés dans l'ordre affiché.
  form.addEventListener('submit', renumber);

  // Prévient la perte d'un brouillon non enregistré.
  var dirty = false;
  form.addEventListener('input', function () { dirty = true; });
  form.addEventListener('submit', function () { dirty = false; });
  window.addEventListener('beforeunload', function (event) {
    if (dirty) { event.preventDefault(); event.returnValue = ''; }
  });

  renumber();
  recalc();
})();

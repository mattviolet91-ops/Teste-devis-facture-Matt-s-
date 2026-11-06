// « Encaisser un montant » : devis du seul client choisi, type de facture proposé, champs selon le mode.
(function () {
  var form = document.querySelector('[data-collect]');
  if (!form) { return; }
  var data = JSON.parse((document.getElementById('collect-quotes') || {}).textContent || '{}');
  var client = form.querySelector('[data-collect-client]');
  var quote = form.querySelector('[data-collect-quote]');
  var kindTouched = false;

  function setKind(value) {
    if (kindTouched) { return; }
    form.querySelectorAll('[data-collect-kind]').forEach(function (r) { r.checked = r.value === value; });
  }

  // Options reconstruites (l'iPhone affiche les options masquées).
  function rebuild() {
    var keep = quote.value || quote.getAttribute('data-selected');
    quote.length = 1;
    (data[client.value] || []).forEach(function (item) {
      var o = new Option(item.label, item.id);
      if (String(item.id) === String(keep || '')) { o.selected = true; }
      quote.add(o);
    });
    quote.removeAttribute('data-selected');
  }

  function applyMode() {
    var checked = form.querySelector('[data-collect-mode]:checked');
    var mode = checked ? checked.value : 'card';
    form.querySelectorAll('[data-mode-only]').forEach(function (el) {
      el.hidden = el.getAttribute('data-mode-only') !== mode;
      el.querySelectorAll('input, select, textarea').forEach(function (f) { f.disabled = el.hidden; });
    });
  }

  client.addEventListener('change', function () { quote.value = ''; rebuild(); setKind('standard'); });
  quote.addEventListener('change', function () { setKind(quote.value ? 'deposit' : 'standard'); });
  form.querySelectorAll('[data-collect-kind]').forEach(function (r) { r.addEventListener('change', function () { kindTouched = true; }); });
  form.querySelectorAll('[data-collect-mode]').forEach(function (r) { r.addEventListener('change', applyMode); });
  rebuild();
  applyMode();
})();

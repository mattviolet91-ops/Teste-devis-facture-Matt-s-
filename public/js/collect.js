// « Encaisser un montant » : factures du seul client choisi, montant proposé, champs selon le mode.
(function () {
  var form = document.querySelector('[data-collect]');
  if (!form) { return; }
  var data = JSON.parse((document.getElementById('collect-invoices') || {}).textContent || '{}');
  var client = form.querySelector('[data-collect-client]');
  var invoice = form.querySelector('[data-collect-invoice]');
  var amount = form.querySelector('#amount');
  var amountTouched = !!(amount && amount.value);

  // Options reconstruites (l'iPhone affiche les options masquées).
  function rebuild() {
    var keep = invoice.value || invoice.getAttribute('data-selected');
    invoice.length = 1;
    (data[client.value] || []).forEach(function (item) {
      var o = new Option(item.label, item.id);
      if (String(item.id) === String(keep || '')) { o.selected = true; }
      invoice.add(o);
    });
    invoice.removeAttribute('data-selected');
  }

  // Facture choisie : son reste à payer est proposé comme montant (modifiable).
  function proposeAmount() {
    if (!amount || amountTouched) { return; }
    var chosen = (data[client.value] || []).filter(function (item) { return String(item.id) === invoice.value; })[0];
    amount.value = chosen ? (chosen.balance / 100).toFixed(2).replace('.', ',').replace(',00', '') : '';
  }

  function applyMode() {
    var checked = form.querySelector('[data-collect-mode]:checked');
    var mode = checked ? checked.value : 'card';
    form.querySelectorAll('[data-mode-only]').forEach(function (el) {
      el.hidden = el.getAttribute('data-mode-only') !== mode;
      el.querySelectorAll('input, select, textarea').forEach(function (f) { f.disabled = el.hidden; });
    });
  }

  if (amount) { amount.addEventListener('input', function () { amountTouched = amount.value !== ''; }); }
  client.addEventListener('change', function () { invoice.value = ''; rebuild(); proposeAmount(); });
  invoice.addEventListener('change', proposeAmount);
  form.querySelectorAll('[data-collect-mode]').forEach(function (r) { r.addEventListener('change', applyMode); });
  rebuild();
  proposeAmount();
  applyMode();
})();

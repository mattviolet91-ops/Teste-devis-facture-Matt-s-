// « Faire signer un document » : devis et factures du seul client choisi, email proposé, champs selon le mode.
(function () {
  var form = document.querySelector('[data-sign-send]');
  if (!form) { return; }
  var data = JSON.parse((document.getElementById('sign-send-data') || {}).textContent || '{}');
  var client = form.querySelector('[data-sign-client]');
  var target = form.querySelector('[data-sign-target]');
  var email = form.querySelector('[data-sign-email]');
  var emailTouched = !!(email && email.value);

  // Options reconstruites (l'iPhone affiche les options masquées).
  function rebuild() {
    var info = data[client.value] || { email: '', targets: [] };
    var keep = target.value || target.getAttribute('data-selected');
    target.length = 1;
    info.targets.forEach(function (item) {
      var o = new Option(item.label, item.id);
      if (item.id === keep) { o.selected = true; }
      target.add(o);
    });
    target.removeAttribute('data-selected');
    if (email && !emailTouched) { email.value = info.email || ''; }
  }

  function applyAction() {
    var checked = form.querySelector('[data-sign-action]:checked');
    var action = checked ? checked.value : 'send';
    form.querySelectorAll('[data-action-only]').forEach(function (el) {
      el.hidden = el.getAttribute('data-action-only') !== action;
      el.querySelectorAll('input').forEach(function (f) { f.disabled = el.hidden; });
    });
  }

  if (email) { email.addEventListener('input', function () { emailTouched = true; }); }
  client.addEventListener('change', function () { target.value = ''; rebuild(); });
  form.querySelectorAll('[data-sign-action]').forEach(function (r) { r.addEventListener('change', applyAction); });
  rebuild();
  applyAction();
})();

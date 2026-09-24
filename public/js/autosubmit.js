// Envoie automatiquement le formulaire de paiement vers myPOS.
(function () {
  'use strict';
  var form = document.querySelector('[data-autosubmit]');
  if (form) { setTimeout(function () { form.submit(); }, 300); }
})();

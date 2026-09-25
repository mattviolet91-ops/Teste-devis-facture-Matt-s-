// Réglages d'affichage : ordre des blocs de l'accueil et aperçu de la barre du bas.
(function () {
  'use strict';

  var form = document.querySelector('[data-display-form]');
  if (!form) { return; }

  var list = form.querySelector('[data-block-order]');
  list.addEventListener('click', function (event) {
    var button = event.target.closest('[data-move-block]');
    if (!button) { return; }
    var item = button.closest('[data-block]');
    if (button.getAttribute('data-move-block') === 'up' && item.previousElementSibling) {
      list.insertBefore(item, item.previousElementSibling);
    } else if (button.getAttribute('data-move-block') === 'down' && item.nextElementSibling) {
      list.insertBefore(item.nextElementSibling, item);
    }
    button.focus();
  });

  // Aperçu : le libellé change avec le choix (l'icône est mise à jour à l'enregistrement).
  form.querySelectorAll('[data-bottom-slot]').forEach(function (select) {
    select.addEventListener('change', function () {
      var slot = form.querySelector('[data-preview-slot="' + select.getAttribute('data-bottom-slot') + '"] span');
      if (slot) { slot.textContent = select.selectedOptions[0].textContent; }
    });
  });
})();

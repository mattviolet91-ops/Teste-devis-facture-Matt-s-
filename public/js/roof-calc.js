// Calcul de surface de toiture (longueur × largeur, corrigé de la pente) pour remplir la quantité d'une ligne.
(function () {
  'use strict';

  var dialog = document.querySelector('[data-roof-dialog]');
  var tpl = document.getElementById('tpl-roof-pan');
  if (!dialog || !tpl) { return; }

  var pans = dialog.querySelector('[data-roof-pans]');
  var totalBox = dialog.querySelector('[data-roof-total]');
  var deduct = dialog.querySelector('[data-roof-deduct]');
  var target = null;
  var total = 0;

  function num(value) {
    var n = parseFloat(String(value || '').replace(/\s/g, '').replace(',', '.'));
    return isFinite(n) ? n : 0;
  }
  function fmt(n) { return n.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

  function panSurface(pan) {
    var get = function (key) { return pan.querySelector('[data-roof="' + key + '"]'); };
    var length = num(get('length').value);
    var width = num(get('width').value);
    var slope = num(get('slope').value);
    var count = Math.max(1, Math.round(num(get('count').value) || 1));
    var angle = get('slope_unit').value === 'pct' ? Math.atan(slope / 100) : slope * Math.PI / 180;
    // Mesure au sol : la surface réelle du rampant est plus grande (÷ cos de l'angle).
    var factor = slope > 0 && angle < Math.PI / 2 * 0.95 ? 1 / Math.cos(angle) : 1;
    var surface = length * width * factor * count;
    pan.querySelector('[data-roof-pan-total]').textContent = surface ? fmt(surface) + ' m²' + (factor > 1 ? ' (coefficient de pente ' + factor.toFixed(3).replace('.', ',') + ')' : '') : '';
    return surface;
  }

  function recalc() {
    total = 0;
    pans.querySelectorAll('[data-roof-pan]').forEach(function (pan, index) {
      pan.querySelector('[data-roof-label]').textContent = 'Pan ' + (index + 1);
      total += panSurface(pan);
    });
    total = Math.max(0, total - num(deduct.value));
    total = Math.round(total * 100) / 100;
    totalBox.textContent = fmt(total) + ' m²';
  }

  function addPan() {
    pans.appendChild(tpl.content.cloneNode(true));
    recalc();
  }

  document.addEventListener('click', function (event) {
    var opener = event.target.closest('[data-roof-calc]');
    if (!opener) { return; }
    event.preventDefault();
    target = opener.closest('[data-line]');
    if (!pans.children.length) { addPan(); }
    recalc();
    dialog.showModal();
  });

  dialog.querySelector('[data-roof-add]').addEventListener('click', addPan);
  dialog.addEventListener('click', function (event) {
    var remove = event.target.closest('[data-roof-remove]');
    if (remove) {
      remove.closest('[data-roof-pan]').remove();
      recalc();
    }
  });
  dialog.addEventListener('input', recalc);
  dialog.addEventListener('change', recalc);

  dialog.querySelector('[data-roof-apply]').addEventListener('click', function () {
    if (!target || total <= 0) { dialog.close(); return; }
    var quantity = target.querySelector('[name$="[quantity]"]');
    quantity.value = fmt(total).replace(/\s/g, '').replace(/ /g, '');
    quantity.dispatchEvent(new Event('input', { bubbles: true }));
    var unit = target.querySelector('select[name$="[unit]"]');
    if (unit) {
      var m2 = Array.prototype.find.call(unit.options, function (o) { return o.value === 'm²' || o.value === 'm2'; });
      if (m2) { unit.value = m2.value; unit.dispatchEvent(new Event('change', { bubbles: true })); }
    }
    dialog.close();
  });
})();

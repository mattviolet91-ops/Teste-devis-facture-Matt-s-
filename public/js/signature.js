// Signature au doigt (ou à la souris) sur le devis en ligne.
(function () {
  'use strict';

  var form = document.querySelector('[data-signature-form]');
  if (!form) { return; }

  var canvas = form.querySelector('[data-signature-canvas]');
  var input = form.querySelector('[data-signature-input]');
  var ctx = canvas.getContext('2d');
  var drawing = false, drawn = false, last = null;

  function resize() {
    var ratio = Math.max(window.devicePixelRatio || 1, 1);
    var rect = canvas.getBoundingClientRect();
    canvas.width = Math.round(rect.width * ratio);
    canvas.height = Math.round(rect.height * ratio);
    ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
    ctx.lineWidth = 2.5;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.strokeStyle = '#1B2A38';
    drawn = false;
    input.value = '';
  }

  function pos(event) {
    var rect = canvas.getBoundingClientRect();
    return { x: event.clientX - rect.left, y: event.clientY - rect.top };
  }

  canvas.addEventListener('pointerdown', function (event) {
    event.preventDefault();
    canvas.setPointerCapture(event.pointerId);
    drawing = true;
    last = pos(event);
    ctx.beginPath();
    ctx.arc(last.x, last.y, 1, 0, Math.PI * 2);
    ctx.fill();
  });
  canvas.addEventListener('pointermove', function (event) {
    if (!drawing) { return; }
    var p = pos(event);
    ctx.beginPath();
    ctx.moveTo(last.x, last.y);
    ctx.lineTo(p.x, p.y);
    ctx.stroke();
    last = p;
    drawn = true;
  });
  ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (type) {
    canvas.addEventListener(type, function () {
      if (drawing && drawn) { input.value = canvas.toDataURL('image/png'); }
      drawing = false;
    });
  });

  form.querySelector('[data-signature-clear]').addEventListener('click', resize);

  form.addEventListener('submit', function (event) {
    if (!drawn) {
      event.preventDefault();
      window.alert('Signez dans le cadre avant de valider.');
      return;
    }
    input.value = canvas.toDataURL('image/png');
  });

  window.addEventListener('resize', function () { if (!drawn) { resize(); } });
  resize();
})();

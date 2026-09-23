// Rédaction d'un email : changement de modèle et ouverture dans la messagerie du téléphone.
(function () {
  'use strict';

  var form = document.querySelector('[data-email-form]');
  if (!form) { return; }

  var templates = JSON.parse(document.getElementById('email-templates').textContent);
  var picker = form.querySelector('[data-email-template]');
  var subject = form.querySelector('[name="subject"]');
  var body = form.querySelector('[name="body"]');
  var edited = false;

  form.addEventListener('input', function (event) {
    if (event.target === subject || event.target === body) { edited = true; }
  });

  picker.addEventListener('change', function () {
    var t = templates[picker.value];
    if (!t) { return; }
    if (edited && !window.confirm('Remplacer le message saisi par ce modèle ?')) { return; }
    subject.value = t.subject;
    body.value = t.body;
    edited = false;
    body.style.height = 'auto';
    body.style.height = (body.scrollHeight + 2) + 'px';
  });

  form.querySelector('[data-mailto]').addEventListener('click', function (event) {
    event.preventDefault();
    var to = form.querySelector('[name="to"]').value.trim();
    var cc = form.querySelector('[name="cc"]').value.trim();
    var params = ['subject=' + encodeURIComponent(subject.value), 'body=' + encodeURIComponent(body.value)];
    if (cc) { params.push('cc=' + encodeURIComponent(cc)); }
    window.location.href = 'mailto:' + encodeURIComponent(to).replace(/%40/g, '@').replace(/%2C/g, ',') + '?' + params.join('&');
  });
})();

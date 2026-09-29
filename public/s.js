/*
 * Statistiques du site internet (à poser une seule fois sur le site) :
 * <script defer src="https://ADRESSE-CLIENT/s.js"></script>
 * Compte les pages vues et les clics de contact (Appeler, Email, WhatsApp, boutons devis/contact,
 * formulaires envoyés). Aucun cookie, aucune donnée personnelle : pas de bandeau de consentement nécessaire.
 */
(function () {
  'use strict';
  var script = document.currentScript;
  if (!script || navigator.webdriver) { return; }
  var endpoint = new URL('/stats/collect', script.src).href;

  function send(type, label) {
    try {
      var data = JSON.stringify({
        t: type,
        u: location.href.split('#')[0].slice(0, 500),
        r: document.referrer ? document.referrer.slice(0, 500) : '',
        l: label ? String(label).replace(/\s+/g, ' ').trim().slice(0, 80) : '',
        w: window.innerWidth || 0
      });
      if (navigator.sendBeacon && navigator.sendBeacon(endpoint, new Blob([data], { type: 'text/plain' }))) { return; }
      fetch(endpoint, { method: 'POST', body: data, mode: 'no-cors', keepalive: true, headers: { 'Content-Type': 'text/plain' } });
    } catch (e) { /* jamais d'erreur visible sur le site */ }
  }

  send('pv');

  var CONTACT_WORDS = /(devis|contact|rappel|rendez-vous|appel|urgence|intervention)/i;
  document.addEventListener('click', function (event) {
    var link = event.target.closest ? event.target.closest('a, button') : null;
    if (!link) { return; }
    var href = (link.getAttribute('href') || '').trim();
    var text = (link.textContent || link.getAttribute('aria-label') || '').trim();
    if (/^tel:/i.test(href)) { send('tel', href.replace(/^tel:/i, '')); return; }
    if (/^mailto:/i.test(href)) { send('mail', 'Email'); return; }
    if (/(wa\.me|whatsapp\.com|api\.whatsapp)/i.test(href)) { send('whatsapp', 'WhatsApp'); return; }
    if (CONTACT_WORDS.test(text) || CONTACT_WORDS.test(href)) { send('cta', text || href); return; }
    if (/^https?:/i.test(href)) {
      try { if (new URL(href).host !== location.host) { send('out', new URL(href).host); } } catch (e) { /* ignoré */ }
    }
  }, true);

  document.addEventListener('submit', function (event) {
    var form = event.target;
    var name = form && (form.getAttribute('name') || form.getAttribute('aria-label') || form.id) || 'Formulaire';
    send('form', name);
  }, true);
})();

// Chargé dans <head> : applique le thème et les options d'affichage avant l'affichage (évite le flash).
(function () {
  try {
    var root = document.documentElement;
    var theme = localStorage.getItem('theme');
    if (theme === 'light' || theme === 'dark') {
      root.setAttribute('data-theme', theme);
    }
    // Options de ce téléphone : grands boutons, contraste « plein soleil ».
    if (localStorage.getItem('pref-big') === '1') { root.setAttribute('data-big', ''); }
    if (localStorage.getItem('pref-sun') === '1') { root.setAttribute('data-sun', ''); }
  } catch (e) { /* stockage indisponible : on suit le réglage du téléphone */ }
})();

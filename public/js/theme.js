// Chargé dans <head> : applique le thème choisi avant l'affichage (évite le flash).
(function () {
  try {
    var theme = localStorage.getItem('theme');
    if (theme === 'light' || theme === 'dark') {
      document.documentElement.setAttribute('data-theme', theme);
    }
  } catch (e) { /* stockage indisponible : on suit le réglage du téléphone */ }
})();

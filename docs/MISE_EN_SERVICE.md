# Mise en service (utilisation réelle)

Décisions du 23/09/2026 : l'application reste sur **https://test.matts-couverture.fr**, les clients
utilisent **https://devis.matts-couverture.fr** ; on repart à zéro pour les devis et factures en gardant
les clients. Rien n'est supprimé sur Wix, qui reste consultable comme archive.

Toutes les commandes se tapent dans le Terminal cPanel, une par une.

1. **Mettre à jour** l'application (commande habituelle, voir README).
2. **Vérifier la configuration** :
   `grep -E "^(APP_ENV|APP_DEBUG|APP_URL|CLIENT_URL|SESSION_SECURE_COOKIE)=" ~/test-gestion/.env`
   Attendu : `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://test.matts-couverture.fr`,
   `CLIENT_URL=https://devis.matts-couverture.fr`, `SESSION_SECURE_COOKIE=true`.
3. **Tâche automatique** (sauvegardes, relances, rappels) : `crontab -l` doit afficher la ligne
   `* * * * * cd ~/test-gestion && php artisan schedule:run …`.
4. **Recette** : faire la liste de docs/RECETTE.md.
5. **Remise à zéro des documents d'essai** (sauvegarde complète automatique avant) :
   - `cd ~/test-gestion && php artisan app:reset-documents` (affiche ce qui sera supprimé, ne supprime rien) ;
   - `php artisan app:reset-documents --confirmer` (supprime les devis, factures, avoirs, paiements et
     emails d'essai ; numérotation remise à DEV-AAAA-0001 / FAC-AAAA-0001 ; clients conservés).
6. **Télécharger une sauvegarde complète** (Réglages → Sauvegardes) et la garder chez soi,
   avec une copie du fichier `.env`.

# Sauvegardes et restauration

## Ce qui est sauvegardé

| Quand | Contenu | Conservation |
|---|---|---|
| Chaque nuit à 1 h 30 | Base de données (clients, devis, factures, paiements, réglages…) | 30 jours |
| Le 1er du mois | Base de données + tous les fichiers (photos, PDF envoyés, signatures, documents, attestations) | 12 mois |
| À la demande (Réglages → Sauvegardes) | Sauvegarde complète téléchargée | 3 dernières sur le serveur |

Les archives sont dans `storage/app/private/sauvegardes/` (jamais accessibles par le web).
Un email de succès ou d'échec est envoyé après chaque sauvegarde de nuit ; en cas d'échec, une
notification arrive aussi sur le téléphone. L'accueil rappelle chaque semaine de télécharger une copie.

Prérequis : la tâche cron du serveur (`* * * * * cd ~/… && php artisan schedule:run`).

## Restaurer (à faire avec de l'aide si possible)

1. Ne rien supprimer : faire d'abord une copie du dossier de l'application et de la base actuelle.
2. Décompresser l'archive : `base-de-donnees.sql` et, pour une sauvegarde complète, le dossier `fichiers/`.
3. Base de données :
   - créer une base vide (cPanel → Bases de données MySQL) et la renseigner dans `.env` ;
   - `php artisan migrate --force` (crée les tables) ;
   - importer `base-de-donnees.sql` (phpMyAdmin → Importer, ou `mysql … < base-de-donnees.sql`).
4. Fichiers : recopier le contenu de `fichiers/` dans `storage/app/private/`.
5. `php artisan config:cache && php artisan route:cache && php artisan view:cache`.

Le fichier `.env` (clé de chiffrement `APP_KEY`) n'est pas dans l'archive : sans lui, le mot de passe
Gmail enregistré devra être ressaisi. Conservez une copie de `.env` à part, en lieu sûr.

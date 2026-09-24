# Mise à jour automatique du serveur

Le serveur vérifie GitHub toutes les 10 minutes (`scripts/deploy.sh`, lancé par cron).
S'il y a une nouvelle version : récupération, dépendances, copie vers `~/test-gestion`,
migrations, caches, puis notification « Application mise à jour » sur le téléphone.

- Journal : `~/deploy.log` (500 dernières lignes).
- En cas d'échec : l'ancienne version reste en service (fichiers remis à l'identique),
  notification « échec », et la version ratée n'est pas retentée ; la suivante le sera.
- Accès GitHub : clé de déploiement en lecture seule (`~/.ssh/github_gestion`).

## Installation (une fois)

```
printf '%s\n' "* * * * * cd ~/test-gestion && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1" "*/10 * * * * /bin/bash $HOME/gestion-source/scripts/deploy.sh" | crontab - && crontab -l
```

## Désactiver

Supprimer la ligne `deploy.sh` dans cPanel → Tâches Cron.

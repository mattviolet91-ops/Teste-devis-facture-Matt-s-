# Gestion Matt's Couverture

Logiciel de devis et facturation de Matt's Couverture : clients, chantiers,
devis, factures, paiements, photos et documents. Conçu pour le téléphone
d'abord, installé sur l'hébergement o2switch (`gestion.matts-couverture.fr`),
sans toucher au site WordPress.

## Documentation

- [Cahier des charges](docs/CAHIER_DES_CHARGES.md)
- [Architecture technique](docs/ARCHITECTURE.md)
- [Base de données](docs/BASE_DE_DONNEES.md)

- [Installation de test sur o2switch](docs/INSTALLATION_TEST.md)

La procédure d'installation définitive sera rédigée à la phase 15. L'archive
à envoyer sur le serveur se fabrique avec `scripts/build-release.sh`.

## Avancement

| Phase | Contenu | État |
|---|---|---|
| 1–3 | Cahier des charges, architecture, base de données | ✅ |
| 4 | Connexion, interface mobile, réglages de l'entreprise | ✅ |
| 5 | Clients et chantiers, recherche, corbeille | ✅ |
| 6 | Bibliothèque de prestations et devis | à venir |
| 7 | Factures, acomptes, situations, avoirs | à venir |
| 8 | PDF | à venir |
| 9 | Photos, documents, assurance | à venir |
| 10 | Paiements, relances, tableau de bord, emails | à venir |
| 11 | Lien client, signature | à venir |
| 12–15 | Sécurité, sauvegardes, recette, installation | à venir |

## Développement

Prérequis : PHP 8.2+ (extensions gd, intl, pdo_sqlite / pdo_mysql) et Composer.

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan app:create-admin     # crée le compte administrateur
php artisan db:seed --class=DemoSeeder   # données fictives (facultatif)
php artisan serve
```

Tests et style :

```bash
php artisan test
vendor/bin/pint
```

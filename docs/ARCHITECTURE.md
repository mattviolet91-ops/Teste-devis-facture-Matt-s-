# Architecture technique

## Vue d'ensemble

```
matts-couverture.fr            → WordPress.com (inchangé, jamais modifié)

gestion.matts-couverture.fr ─┐
                             ├→ hébergement o2switch (même application)
devis.matts-couverture.fr  ──┘
   gestion. : espace de travail (connexion obligatoire)
   devis.   : liens clients sécurisés (/d/{jeton})
```

## Choix techniques

| Élément | Choix | Raison |
|---|---|---|
| Langage | PHP 8.2+ | Natif sur o2switch, aucun coût supplémentaire |
| Framework | Laravel 12 | Sécurité intégrée (authentification, CSRF, chiffrement), très répandu, maintenable |
| Base de données | MySQL / MariaDB (production), SQLite (tests) | Fournie par o2switch |
| Interface | Blade (rendu serveur) + CSS et JavaScript maison | Rapide sur mobile, **aucune compilation** (pas de Node.js sur le serveur), aucune dépendance JavaScript |
| PDF | mPDF (phase 8) | Fonctionne en hébergement mutualisé, gère en-têtes / pieds de page |
| Photos | Intervention Image + GD (phase 9) | Compression, miniatures |
| Réordonner les lignes | SortableJS ou code maison (phase 6) | Glisser-déposer tactile |
| Signature | signature_pad (phase 11) | Signature au doigt |
| Emails | SMTP Gmail (mot de passe d'application) | Gratuit, envoi depuis l'adresse habituelle |
| Tâches planifiées | Cron o2switch → `php artisan schedule:run` | Relances, alertes, sauvegardes, retards |
| Hors ligne | Service worker + IndexedDB (phase 9) | Photos et brouillons sans réseau |
| Adresses | Base Adresse Nationale (data.geopf.fr), appelée depuis le navigateur | Gratuit, service de l'État ; seule l'adresse tapée est envoyée |

Les polices sont copiées dans `public/fonts/` : aucun appel à Google ni à un
CDN. Le seul service externe est la Base Adresse Nationale (suggestions
d'adresses) ; sans réseau, la saisie reste manuelle.

## Organisation du code

```
app/
  Http/Controllers/       Contrôleurs par module (Auth, Dashboard, Settings, …)
  Http/Middleware/        En-têtes de sécurité, …
  Models/                 Modèles Eloquent
  Services/               Logique métier (Settings, numérotation, calculs, PDF…)
  Support/                Petits utilitaires (montants, formats)
database/migrations/      Schéma de la base
resources/views/          Gabarits Blade (layouts/, components/, modules)
public/css, public/js     Styles et scripts de l'application
storage/app/private/      Fichiers privés (photos, PDF, attestations) — jamais publics
tests/                    Tests automatiques
docs/                     Cahier des charges, architecture, installation
```

## Principes

- **Montants en centimes** (entiers) : aucune erreur d'arrondi.
- **Documents figés** : chaque version envoyée est enregistrée (PDF + empreinte
  SHA-256) et ne change plus.
- **Numérotation transactionnelle** : un compteur par type, verrouillé pendant
  l'attribution.
- **Fichiers privés** : servis uniquement par l'application, après contrôle
  d'accès (session) ou jeton client.
- **Journal d'activité** : toute action importante est tracée.
- **Deux hôtes, une application** : `APP_URL` (gestion) et `CLIENT_URL` (devis).

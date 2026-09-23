# Installation de test sur o2switch

Objectif : ouvrir l'application sur `https://test.matts-couverture.fr` avec
des clients fictifs, pour l'essayer sur téléphone. **Le site WordPress n'est
pas touché** : on ajoute seulement une adresse `test.` à côté.

Durée : environ 30 minutes. Il faut :
- l'accès à l'espace client o2switch (cPanel) ;
- l'accès à WordPress.com (c'est là que sont gérés les réglages DNS du domaine) ;
- l'archive `gestion-matts-couverture-xxxxxxx.zip` fournie.

---

## Étape 1 — Noter l'adresse IP du serveur o2switch

cPanel → colonne de droite **« Informations générales »** → ligne
**« Adresse IP partagée »** (4 nombres séparés par des points, par exemple
`109.234.xxx.xxx`). La noter.

## Étape 2 — Faire pointer `test.matts-couverture.fr` vers o2switch

Le domaine utilise les serveurs DNS de WordPress.com : c'est donc là que
l'adresse `test.` se déclare.

WordPress.com → **Mises à niveau → Domaines** → `matts-couverture.fr` →
**Enregistrements DNS** → **Ajouter un enregistrement** :

| Type | Nom | Valeur |
|---|---|---|
| A | `test` | l'adresse IP notée à l'étape 1 |

Les autres enregistrements (site, emails) ne doivent **pas** être modifiés.
La prise en compte peut prendre de quelques minutes à une heure.

## Étape 3 — Créer le sous-domaine sur o2switch

cPanel → **Domaines** → **Créer un nouveau domaine** :
- Domaine : `test.matts-couverture.fr`
- Décocher « partager la racine du document »
- Racine du document : `test-gestion/public`

## Étape 4 — Choisir PHP 8.3

cPanel → **Sélectionner une version de PHP** → **8.3** → vérifier que les
extensions `pdo_mysql`, `mbstring`, `intl`, `gd`, `zip`, `fileinfo` sont
cochées → Enregistrer.

## Étape 5 — Créer la base de données

cPanel → **Bases de données MySQL** :
1. Nouvelle base : `test_gestion` (o2switch ajoute un préfixe, par exemple
   `matt1234_test_gestion` : noter le nom complet).
2. Nouvel utilisateur : `test_gestion` + un mot de passe généré (le noter).
3. « Ajouter un utilisateur à la base » → cocher **Tous les privilèges**.

## Étape 6 — Envoyer les fichiers

cPanel → **Gestionnaire de fichiers** → dossier `test-gestion` (créé à
l'étape 3) → **Charger** l'archive `.zip` → clic droit sur l'archive →
**Extraire** → supprimer ensuite l'archive.

## Étape 7 — Configurer

Dans `test-gestion` : cocher « Afficher les fichiers cachés » (Paramètres),
copier `.env.o2switch.example` en **`.env`**, puis l'éditer et remplacer :
- `DB_DATABASE` : nom complet de la base (étape 5)
- `DB_USERNAME` : nom complet de l'utilisateur
- `DB_PASSWORD` : mot de passe de l'utilisateur

## Étape 8 — Initialiser (Terminal)

cPanel → **Terminal**, puis copier-coller ces lignes une par une :

```bash
cd ~/test-gestion
php -v                                   # doit afficher 8.3
php artisan key:generate --force
php artisan migrate --force
php artisan db:seed --class=DemoSeeder --force
php artisan app:create-admin
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

`app:create-admin` demande un nom, un email et un mot de passe (12 caractères
minimum, lettres et chiffres) : c'est le compte de connexion.

## Étape 9 — Activer HTTPS

cPanel → **Statut SSL/TLS** → cocher `test.matts-couverture.fr` →
**Exécuter AutoSSL** (certificat gratuit). Si l'étape 2 n'est pas encore
prise en compte, réessayer un peu plus tard.

## Étape 10 — Tester

Ouvrir `https://test.matts-couverture.fr` sur le téléphone, se connecter.
Pour l'installer comme une application : menu du navigateur → « Ajouter à
l'écran d'accueil ».

---

## Mettre à jour la version de test

Envoyer et extraire la nouvelle archive dans `test-gestion` (en gardant le
fichier `.env`), puis dans le Terminal :

```bash
cd ~/test-gestion
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

## Supprimer la version de test

Supprimer le sous-domaine (cPanel → Domaines), le dossier `test-gestion`, la
base et l'utilisateur MySQL, puis l'enregistrement DNS `test` sur
WordPress.com. Rien d'autre n'est concerné.

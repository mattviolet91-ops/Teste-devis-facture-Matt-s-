# Base de données

Tous les montants sont stockés **en centimes** (entier). Les taux de TVA sont
stockés en **centièmes de pour cent** (10 % = `1000`). Les tables sont créées
phase par phase ; la colonne « Phase » indique quand.

| Table | Rôle | Phase |
|---|---|---|
| `users` | Comptes (rôle `admin` ; autres rôles prévus) | 4 |
| `settings` | Réglages clé → valeur JSON (entreprise, marque, TVA, mentions…) | 4 |
| `vat_rates` | Taux de TVA configurables | 4 |
| `units` | Unités (m², ml, u, forfait, h…) | 4 |
| `number_sequences` | Compteurs DEV / FAC / AV (préfixe, prochain numéro) | 4 |
| `activity_log` | Journal : utilisateur, action, objet, avant/après, IP, date | 4 |
| `clients` | Fiche client (type, statut, coordonnées, provenance, notes) | 5 |
| `worksites` | Adresses de chantier d'un client (accès, toiture, notes) | 5 |
| `catalog_categories` | Catégories de prestations | 6 |
| `catalog_items` | Prestations (prix, unité, TVA, photo, ouvrage composé) | 6 |
| `catalog_bundle_items` | Composition des ouvrages composés | 6 |
| `quotes` | Devis (numéro, statut, dates, remise, totaux, conditions, remplacement, jeton client) | 6 |
| `document_lines` | Lignes des devis et factures (section, ligne, texte, sous-total) | 6 |
| `payment_schedules` | Échéancier d'un devis (40 % / 60 %…) | 6 |
| `invoices` | Factures (type, devis d'origine, facture corrigée, statut, échéance) | 7 |
| `snapshots` | Versions figées envoyées (PDF + SHA-256) | 8 |
| `photos` | Photos (catégorie, légende, annotations, rattachement, affichage PDF) | 9 |
| `attachments` | Autres fichiers (CGV, documents) | 9 |
| `insurance_certificates` | Attestations d'assurance et historique | 9 |
| `payments` | Paiements (date, montant, moyen, référence) | 10 |
| `reminders` | Relances (manuelles / automatiques) | 10 |
| `text_templates` | Textes prédéfinis et conditions de paiement | 6 |
| `email_templates` | Modèles d'emails | 10 |
| `email_log` | Emails envoyés | 10 |
| `view_log` | Consultations par le client | 11 |
| `acceptances` | Acceptations signées (nom, signature, IP, navigateur, date, empreinte) | 11 |

## Règles d'intégrité

- Les factures et avoirs ne sont **jamais** supprimables (ni physiquement, ni
  en corbeille).
- Clients et devis : suppression douce (`deleted_at`), purge après 30 jours.
- Un numéro de document est **unique** (index unique) et attribué dans une
  transaction qui verrouille la ligne du compteur.
- Les clés étrangères empêchent de supprimer un client qui possède des
  factures.

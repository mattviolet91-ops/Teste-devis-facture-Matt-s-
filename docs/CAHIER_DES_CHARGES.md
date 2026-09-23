# Cahier des charges — Gestion Matt's Couverture

Version validée le 23/09/2026.

Logiciel de devis et facturation propre à Matt's Couverture, destiné à
remplacer progressivement l'outil Wix. Utilisation quotidienne sur téléphone,
tablette et ordinateur.

## 1. Entreprise

| Élément | Valeur |
|---|---|
| Nom commercial | Matt's Couverture |
| Forme juridique | Entreprise individuelle (mention « EI » obligatoire) |
| Représentant | Matt Violet |
| SIRET | 981 708 167 00011 |
| Adresse | 8 chemin de la Plesse, 91140 Villebon-sur-Yvette |
| Téléphone / email | 07 67 92 68 36 — mv.entreprise91@gmail.com |
| Site | https://matts-couverture.fr |
| Slogan | Votre couvreur de confiance |
| Agréments | Dalep, Velux |
| Assurance décennale | QBE Europe SA/NV via +Simple — contrat n° 037 0010701-D1002575 — validité 01/01/2026 → 31/12/2026 — activité « Couverture, à l'exclusion de la pose de capteurs solaires » — France métropolitaine et DOM |
| Médiateur de la consommation | à renseigner (champ prévu) |

Une seule entreprise est gérée. Toutes ces informations sont modifiables dans
les réglages.

## 2. Décisions de fonctionnement

### Numérotation
- Devis : `DEV-AAAA-NNNN`, factures : `FAC-AAAA-NNNN`, avoirs : `AV-AAAA-NNNN`.
- Le compteur **ne repart jamais à zéro** : il continue d'une année à l'autre
  (DEV-2026-0120 → DEV-2027-0121).
- Numéro attribué **à l'envoi** (un brouillon n'a pas de numéro).
- Les factures ont leur propre suite continue (obligation légale) et affichent
  « Devis n° DEV-… ».
- Départ à DEV-2026-0001 (la numérotation Wix n'est pas reprise, validé le
  23/09/2026).
- Aucun doublon possible (compteur verrouillé en base).

### Devis
- Validité 30 jours par défaut, modifiable par devis.
- Modifier un devis envoyé crée un **nouveau numéro** ; l'ancien passe
  « Remplacé par … ».
- Sections, sous-totaux, masquage des prix d'une section, lignes optionnelles,
  variantes, duplication (y compris vers un autre client), refus avec motif,
  expiration, date / durée prévisionnelle des travaux.
- Informations de toiture sur le chantier (type de couverture, surface, pente,
  niveaux, accessibilité).
- Signature sur place (formulaire de rétractation de 14 jours joint) ou à
  distance via le lien client. Le délai de 7 jours avant encaissement sur un
  contrat hors établissement fait l'objet d'un **rappel non bloquant**.

### Factures
- Types : classique, acompte, situation (avancement), solde, avoir.
- Acompte par défaut : 40 % à la signature, 60 % à la fin des travaux
  (modifiable sur chaque document).
- Brouillon : modification libre. Facture envoyée : bouton « Modifier » qui
  crée automatiquement l'avoir et la facture corrigée (conformité légale).
  Photos, notes internes, paiements et relances restent modifiables.
- Statuts : brouillon, envoyée, partiellement payée, payée, en retard,
  annulée (par avoir).
- Mentions « clients professionnels » (pénalités, indemnité 40 €) : texte
  prédéfini à ajouter manuellement.

### TVA
- Régime par défaut dans les réglages : franchise en base (art. 293 B du CGI)
  ou assujetti. **Régime actuel : franchise** (mention présente sur les devis
  Wix). Le régime se choisit **sur chaque devis et chaque facture** (validé le
  23/09/2026) et il est figé à l'envoi.
- Taux configurables ; 10 % par défaut, 20 % et 5,5 % disponibles ; plusieurs
  taux par document ; TVA sur les encaissements.
- Mention d'attestation client ajoutée automatiquement sur les lignes à 10 % /
  5,5 % (désactivable).

### Unités
m², ml, unité, forfait, heure (liste modifiable).

### Remises
Par ligne ou globale, en % ou en montant.

### Paiements
Virement, chèque, espèces, CB, myPOS, « autre » (texte libre). IBAN
enregistré, affichage optionnel par document.

### Clients
Organisation **par client** ; un client peut avoir plusieurs adresses de
chantier. Statut prospect → client au premier devis accepté. Provenance
suivie. Types : particulier, entreprise, syndic, agence, collectivité, autre.
Code postal à 5 chiffres, suggestions d'adresses, alerte de doublon
(téléphone ou email), tri récents / A → Z. Un client qui a des factures ne
peut pas être mis à la corbeille (actif à partir de la phase 7).

### Bibliothèque
Prestations (nom, détail des étapes, prix, unité, TVA, catégorie). Pas de
prix d'achat / marge. Pas de distinction main-d'œuvre / fournitures.
Bibliothèque de départ construite à partir de 50 devis Wix réels (n° 0002287
à 0002338) : 39 prestations, prix habituels constatés. Étapes types
(échelles, sécurité, nettoyage, déchets…) ajoutables en un clic.
Ouvrages composés : non retenus pour l'instant (les devis Wix utilisent une
ligne par prestation avec le détail des étapes) — à rediscuter si besoin.

### Photos
2 à 8 par chantier en moyenne. Prise hors ligne possible, compression
automatique, annotation, catégories (avant, pendant, après, problème,
réparation, autre), légende, choix d'affichage dans les PDF (annexe en fin de
document).

### Lien client et signature
Lien sécurisé non devinable, sans expiration. Consultation, téléchargement du
PDF, acceptation (« Bon pour accord » + nom + signature au doigt + date, heure,
IP), refus ou demande de modification avec commentaire. Notification à
l'ouverture et à l'acceptation.

### Emails
Envoi depuis l'application via Gmail (mv.entreprise91@gmail.com, mot de passe
d'application) **ou** ouverture de la messagerie / WhatsApp / SMS avec texte
prérempli. Modèles : devis, facture, acompte, relance, paiement reçu. Copie
cachée systématique. Relances manuelles suivies + relances automatiques
optionnelles.

### Assurance
Attestation PDF + données, affichage sur devis/factures, pièce jointe
optionnelle, alertes 15 jours puis 5 jours avant l'expiration, historique.
Pas d'alerte « prestation hors garantie ».

### PDF
Un modèle unique, aux couleurs du site. Page de présentation de l'entreprise
optionnelle, CGV, annexe photos, formulaire de rétractation.

### Tableau de bord
En tête : montant à encaisser, devis en attente, CA facturé du mois. Puis
devis acceptés / refusés, factures payées / impayées, CA de l'année. Filtres
jour / semaine / mois / année / période. CA calculé **à la facturation**.

### Utilisateurs
Un seul administrateur. Structure de rôles prévue pour plus tard. Pas de
double authentification pour l'instant (activable plus tard).

### Sauvegardes
Chaque nuit sur le serveur (30 quotidiennes + 12 mensuelles), email de
succès et d'échec, bouton « Télécharger ma sauvegarde » avec rappel
hebdomadaire.

### Migration Wix
Aucune reprise de données : on repart de zéro, Wix reste consultable comme
archive. Rien n'est supprimé sur Wix.

### Apparence
Couleurs du site (accent `#3CBDE8`, primaire `#494949`, texte `#2C3E50`,
fond `#ECF0F1`), polices Montserrat / Figtree, mode clair et sombre,
application installable (PWA). Tout est personnalisable.

## 3. Hors périmètre V1 (architecture prête)

Aide IA au devis (dictée), SMS automatiques, paiement en ligne (myPOS),
planning, équipes, facturation électronique B2B (Factur-X), export comptable,
statistiques avancées, double authentification.

## 4. Règle de validation

Aucune action sur le site WordPress, le DNS, l'hébergement o2switch ou Wix
sans accord explicite préalable.

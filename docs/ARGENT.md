# Espace Argent

Gestion de l'argent perso et pro du gérant, dans l'application (menu « Argent »).

## Accès

- Réservé au gérant qui a créé le **code Argent** (4 à 8 chiffres) à la première ouverture.
  Un commercial ou un autre compte gérant reçoit une erreur 403 et ne voit pas le lien.
- Code demandé à chaque ouverture, puis après 15 minutes sans activité (réglable : 5, 15, 30, 60 min).
- 5 codes faux : espace bloqué 15 minutes, alerte sur le téléphone et ligne dans le journal.
- Code oublié : le mot de passe du compte permet d'en choisir un nouveau.
- Pages jamais gardées en cache (`Cache-Control: no-store`, exclues du service worker).
- Données dans la base de l'application : incluses dans la sauvegarde quotidienne, export CSV possible.

## Lien avec les devis

`app:argent-semaine` (lundi 7 h) : chaque paiement reçu devient une entrée et chaque frais une sortie
sur le compte choisi (Réglages Argent), sans doublon (`source_ref` = `payment:ID` / `expense:ID`).
Un paiement supprimé ou une facture annulée disparaît ; la catégorie et le libellé changés à la main
sont gardés. Puis bilan de la semaine passée (`money_weekly_reports`) et notification (sans montant
par défaut). Bouton « Mettre à jour » pour le faire à la demande.

`app:argent-jour` (6 h 10) : dépenses et revenus fixes arrivés à échéance.

## Calculs

- Montants en centimes, signés (+ entrée, − sortie). « Gagné » = entrées, « dépensé » = sorties ;
  les virements entre comptes ne comptent dans aucun des deux.
- Solde d'un compte = solde de départ + mouvements à partir de la date de ce solde.
- Vue Perso / Pro selon le compte du mouvement.
- Relevé bancaire : CSV (colonnes reconnues : date, libellé, montant ou débit / crédit) ou OFX.
  Lignes déjà importées écartées (empreinte, ou FITID de l'OFX), lignes ressemblant à un mouvement
  déjà noté (même montant, ±5 jours) décochées.

## Dépannage

- Changer de propriétaire (compte gérant supprimé, par exemple) : `php artisan tinker` puis
  `app(App\Services\Settings::class)->set(['argent.owner_id' => null, 'argent.code_hash' => null]);`
  — le prochain gérant qui ouvre l'espace choisit un nouveau code (les données restent).

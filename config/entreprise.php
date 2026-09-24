<?php

/*
| Valeurs par défaut des réglages de l'entreprise.
|
| Elles ne servent qu'au premier lancement : toute modification faite dans
| Réglages est enregistrée en base (table `settings`) et prend le dessus.
*/

return [

    'company' => [
        'trade_name' => "Matt's Couverture",
        // Jamais imprimé sur les documents : seul le nom commercial apparaît.
        'owner_name' => '',
        'legal_form' => 'EI',
        'slogan' => 'Votre couvreur de confiance',
        'address' => '8 chemin de la Plesse',
        'postal_code' => '91140',
        'city' => 'Villebon-sur-Yvette',
        'phone' => '07 67 92 68 36',
        'email' => 'mv.entreprise91@gmail.com',
        'website' => 'https://matts-couverture.fr',
        'siret' => '98170816700011',
        'ape_code' => '',
        'vat_number' => '',
        'agreements' => 'Agréé Dalep et Velux',
        'mediator_name' => '',
        'mediator_url' => '',
    ],

    'bank' => [
        'holder' => '',
        'iban' => '',
        'bic' => '',
        'show_by_default' => false,
        // Lien de paiement par carte (myPOS, SumUp…) affiché au client sur sa facture en ligne.
        'card_link' => '',
    ],

    'vat' => [
        // 'assujetti' ou 'franchise'
        'regime' => 'franchise',
        'franchise_mention' => 'TVA non applicable, art. 293 B du CGI',
        'reduced_rate_mention_enabled' => false,
    ],

    // Assurance décennale (attestation 2026). L'attestation PDF et les alertes
    // d'échéance arrivent à la phase 9.
    'insurance' => [
        'insurer' => 'QBE Europe SA/NV',
        'insurer_address' => 'Tour CBX, 1 Passerelle des Reflets, 92913 Paris La Défense Cedex',
        'broker' => '+Simple',
        'policy_number' => '037 0010701-D1002575',
        'valid_from' => '2026-01-01',
        'valid_until' => '2026-12-31',
        'activities' => 'Couverture, à l\'exclusion de la pose de capteurs solaires',
        'coverage_area' => 'France métropolitaine et DOM',
    ],

    // Contenu des PDF (Réglages → Documents PDF).
    'pdf' => [
        // Installation où sont apportés les déchets du chantier (nom, adresse, type).
        'waste_facility' => '',
        'waste_mention' => 'Les déchets du chantier (tuiles, ardoises, bois, isolant, zinc, emballages…) sont triés, '
            .'enlevés et évacués par nos soins vers une installation de collecte agréée. Le coût de leur gestion est '
            .'compris dans le prix des travaux.',
        'cgv_enabled' => true,
        'cgv' => <<<'CGV'
1. Devis. Le devis est gratuit. Il est valable pendant la durée indiquée. Le contrat est formé par la signature du devis par le client, précédée de la mention « Bon pour accord ».
2. Prix. Les prix sont fermes pendant la durée de validité du devis. Tout travail supplémentaire ou modification demandée par le client fera l'objet d'un avenant ou d'un nouveau devis accepté avant exécution.
3. Délais. Les dates d'intervention sont données à titre indicatif et peuvent être décalées en cas d'intempéries, de force majeure ou de retard d'approvisionnement ; le client en est informé.
4. Accès au chantier. Le client assure l'accès au chantier et le branchement à l'eau et à l'électricité si nécessaire. Il signale tout élément caché (réseaux, amiante, fragilité de la structure) dont il a connaissance.
5. Paiement. Les paiements sont effectués selon les conditions indiquées sur le devis ou la facture. Les travaux restent la propriété de l'entreprise jusqu'au paiement complet du prix.
6. Réception. Les travaux sont réceptionnés à leur achèvement, avec ou sans réserves. La réception fait courir les garanties légales (parfait achèvement, biennale, décennale).
7. Litiges. En cas de litige, le client est invité à contacter l'entreprise pour rechercher une solution amiable. Le client consommateur peut recourir gratuitement au médiateur de la consommation indiqué sur le document.
CGV,
        // Page de couverture stylisée (logo, client, assurance, coordonnées).
        'cover_quotes' => true,
        'cover_invoices' => false,
        // Texte de présentation facultatif, imprimé sur la couverture.
        'presentation_text' => '',
    ],

    // Envoi des emails via Gmail (Réglages → Emails). Le mot de passe
    // d'application est enregistré chiffré, jamais en clair.
    'mail' => [
        'username' => '',
        'password' => '',
        'from_name' => '',
        'bcc_self' => true,
        // Messages SMS / WhatsApp / copier (mêmes variables que les emails).
        'sms_quote' => "{salutation}\nVoici votre devis n° {numero} de {entreprise} pour {objet} ({montant}).\nVous pouvez le consulter et l'accepter en ligne, avec signature sur votre téléphone :\n{lien}\nBien cordialement,\n{entreprise} – {telephone}",
        // Relances (SMS / WhatsApp / copie) : avant l'échéance, puis en retard.
        'reminder_before' => "{salutation}\nPetit rappel : la facture n° {numero} de {entreprise}, d'un montant de {reste_a_payer}, arrive à échéance le {date_echeance}.\nVous pouvez la consulter ici :\n{lien}\nMerci d'avance,\n{entreprise} – {telephone}",
        'reminder_after' => "{salutation}\nSauf erreur de notre part, la facture n° {numero} d'un montant de {reste_a_payer} n'a pas encore été réglée (échéance : {date_echeance}, {retard}).\nVous la retrouverez ici :\n{lien}\nSi le règlement a déjà été fait, merci de ne pas tenir compte de ce message.\n{entreprise} – {telephone}",
        // Relance d'entretien ({prestation}, {anciennete}, {date_travaux}).
        'maintenance' => "{salutation}\nNous sommes intervenus chez vous il y a {anciennete} ({date_travaux}) pour : {prestation}.\nPour garder votre toiture en bon état, un nouvel entretien est conseillé. Souhaitez-vous que nous passions faire un point, sans engagement ?\nBien cordialement,\n{entreprise} – {telephone}",
        // Confirmation d'intervention ({date_intervention}).
        'intervention' => "{salutation}\nNous vous confirmons notre intervention {date_intervention} au {adresse_chantier} pour {objet}.\nEn cas d'empêchement, merci de nous prévenir.\nBien cordialement,\n{entreprise} – {telephone}",
        // Confirmation de rendez-vous ({date_rdv}, {objet_rdv}).
        'appointment' => "{salutation}\nNous vous confirmons notre rendez-vous {date_rdv} au {adresse_chantier} ({objet_rdv}).\nEn cas d'empêchement, merci de nous prévenir.\nBien cordialement,\n{entreprise} – {telephone}",
        // Demande d'avis Google ({lien_avis}).
        'review_subject' => 'Votre avis compte pour nous – {entreprise}',
        'review' => "{salutation}\nMerci encore pour votre confiance ! Si vous êtes satisfait de notre travail, pourriez-vous prendre une minute pour laisser un avis sur Google ? Cela nous aide beaucoup.\n{lien_avis}\nBien cordialement,\n{entreprise} – {telephone}",
        'sms_invoice' => "{salutation}\nVoici {document} n° {numero} de {entreprise} d'un montant de {montant}, {echeance}.\nConsultez-la et téléchargez-la ici :\n{lien}\nMerci pour votre confiance !\n{entreprise} – {telephone}",
    ],

    // Relances automatiques des factures impayées (désactivées par défaut).
    'reminders' => [
        // Notification sur le téléphone des factures à relancer.
        'notify_enabled' => true,
        'auto_enabled' => false,
        'first_after_days' => 3,
        'repeat_days' => 7,
        'max' => 3,
    ],

    // Paiement par carte avec myPOS Checkout (Réglages → Paiement en ligne).
    // Le pack de configuration est enregistré chiffré.
    'mypos' => [
        'enabled' => false,
        'test' => true,
        'package' => '',
    ],

    // Demande d'avis Google après un chantier payé (Réglages → Emails).
    'reviews' => [
        'enabled' => false,
        'enabled_at' => null,
        'google_url' => '',
        'delay_days' => 2,
    ],

    'backups' => [
        'last_download_at' => null,
    ],

    // Notifications sur le téléphone : clés VAPID créées automatiquement.
    'push' => [
        'public_key' => '',
        'private_key' => '',
    ],

    'branding' => [
        'color_accent' => '#3CBDE8',
        'color_primary' => '#494949',
        'color_text' => '#2C3E50',
        'color_background' => '#ECF0F1',
        'font_heading' => 'Montserrat',
        'font_body' => 'Figtree',
        // Images envoyées dans Réglages → Apparence. Sans image envoyée, on
        // utilise celles fournies avec l'application (public/images).
        'logo_path' => null,
        'icon_path' => null,
    ],

    'default_images' => [
        'logo' => 'images/logo.png',
        'icon' => 'images/marque.png',
    ],

    'documents' => [
        'quote_validity_days' => 30,
        // Délai de paiement des factures en jours (0 = à réception).
        'invoice_due_days' => 0,
        // Acompte proposé par défaut, en %.
        'deposit_percent' => 40,
    ],

    // Polices embarquées dans public/fonts (aucun appel à Google Fonts).
    'fonts' => [
        'Montserrat' => "'Montserrat', system-ui, sans-serif",
        'Figtree' => "'Figtree', system-ui, sans-serif",
        'Système' => "system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif",
    ],

    // Adresse publique des liens clients (sous-domaine devis.).
    'client_url' => env('CLIENT_URL', env('APP_URL', 'http://localhost')),

];

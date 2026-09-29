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
1. Entreprise et champ d'application. Les présentes conditions s'appliquent aux travaux réalisés auprès des particuliers par Matt's Couverture, entreprise individuelle (EI), siège : 8 chemin de la Plesse, 91140 Villebon-sur-Yvette, SIRET 981 708 167 00011, immatriculée au Répertoire des métiers de l'Essonne : couverture, zinguerie, charpente, fenêtres de toit, isolation, nettoyage et démoussage, recherche de fuite et réparations. Zone d'intervention : Essonne (91), Hauts-de-Seine (92), Yvelines (78), Val-de-Marne (94) et Seine-et-Marne (77) ; au-delà, sur devis avec frais de déplacement.
2. Visite et devis. Le déplacement, le diagnostic et le devis sont gratuits et sans engagement, sauf pour les interventions d'urgence (article 8). Le devis est valable 30 jours. Le contrat est formé par la signature du devis par le client, sur papier ou en ligne, précédée de la mention « Bon pour accord ».
3. Droit de rétractation. Lorsque le devis est signé au domicile du client ou à distance (en ligne, par email), le client dispose d'un délai de 14 jours à compter de la signature pour se rétracter, sans motif et sans frais, par toute déclaration claire (courrier, email). Les travaux ne commencent pas avant la fin de ce délai, sauf demande expresse et écrite du client ; s'il se rétracte ensuite, il paie la part des travaux déjà réalisée. Il n'y a pas de droit de rétractation pour une réparation urgente expressément demandée par le client à son domicile, dans la limite de ce qui est strictement nécessaire pour faire face à l'urgence. Les sommes versées sont remboursées dans les 14 jours suivant la rétractation.
4. Prix. Les prix sont indiqués en euros. Le régime de TVA applicable est précisé sur le devis. Les prix sont fermes pendant la durée de validité du devis.
5. Acompte et paiement. Un acompte peut être demandé ; son montant est indiqué sur le devis. Pour un devis signé au domicile du client, aucun paiement n'est encaissé avant 7 jours après la signature, sauf réparation urgente demandée par le client. Des paiements en cours de chantier peuvent être prévus au devis. Le solde est payable à la fin des travaux, dès réception de la facture. Moyens de paiement acceptés : virement, carte bancaire (sur place ou par lien de paiement en ligne), chèque à l'ordre de Matt's Couverture, espèces dans la limite légale de 1 000 €.
6. Retard de paiement. Après une mise en demeure restée sans effet, les sommes dues portent intérêts au taux légal et l'entreprise peut suspendre les travaux restants. Les matériaux fournis et non encore posés restent la propriété de l'entreprise jusqu'au paiement complet du prix.
7. Annulation. Après le délai de rétractation, l'acompte versé reste acquis à l'entreprise. Si des matériaux commandés spécialement pour le chantier coûtent plus que l'acompte, la différence reste due, sur justificatif.
8. Interventions d'urgence. Nous intervenons en urgence, y compris la nuit, le week-end et les jours fériés, dans la mesure de nos disponibilités. Les frais de déplacement et l'éventuelle majoration sont annoncés par téléphone avant le déplacement, puis confirmés au client par SMS ou email. La mise en sécurité (bâchage, mise hors d'eau provisoire) est facturée ; la réparation définitive fait l'objet d'un devis.
9. Délais. La date ou le délai d'exécution des travaux figure sur le devis. Il peut être prolongé en cas d'intempéries (pluie, vent fort, gel) rendant le travail en toiture dangereux ou nuisible à la qualité de l'ouvrage, de force majeure, de retard de livraison des matériaux ou de découverte d'un imprévu (article 11). Le client en est informé sans délai et une nouvelle date est fixée.
10. Obligations du client. Le client donne accès au chantier et, si besoin, à l'eau et à l'électricité. Il obtient les autorisations nécessaires (déclaration préalable de travaux, accord d'un voisin pour un échafaudage…), sauf si le devis prévoit autre chose. Il signale tout élément caché dont il a connaissance : réseaux, amiante, fragilité de la structure.
11. Imprévus et travaux supplémentaires. Si un problème caché apparaît pendant les travaux (charpente abîmée, support dégradé…), le client est prévenu, les travaux concernés sont suspendus, la toiture est mise hors d'eau et un devis complémentaire est établi. Aucun travail supplémentaire n'est réalisé sans l'accord écrit du client (signature, email ou SMS). En cas de suspicion d'amiante, les travaux sont arrêtés : le repérage et le retrait sont confiés, aux frais du client, à une entreprise certifiée, et les délais sont prolongés d'autant.
12. Matériaux fournis par le client. L'entreprise peut refuser les matériaux inadaptés ou non conformes. Pour les matériaux fournis par le client, seule la pose est garantie, pas les matériaux eux-mêmes ni leur livraison.
13. Déchets. Les déchets du chantier sont triés, enlevés et évacués par nos soins vers une installation agréée. Leur coût est compris dans le prix, sauf mention contraire sur le devis.
14. Réception des travaux. Les travaux sont réceptionnés à leur achèvement, en présence du client, avec ou sans réserves. Les réserves éventuelles sont levées dans un délai convenu ensemble. La réception fait courir les garanties.
15. Garanties. Les travaux bénéficient des garanties légales : garantie de parfait achèvement (1 an), garantie de bon fonctionnement des équipements (2 ans) et garantie décennale (10 ans), couverte par une assurance dont les coordonnées figurent sur le devis. Ne sont pas couverts : le défaut d'entretien, une utilisation anormale, l'intervention d'un tiers et les événements climatiques exceptionnels.
16. Photos. Des photos des travaux peuvent être publiées sur notre site et nos réseaux, sans adresse ni personne reconnaissable. Le client peut s'y opposer à tout moment par simple message.
17. Données personnelles. Les coordonnées du client servent uniquement aux devis, aux travaux et à la facturation. Elles sont conservées pendant la durée légale (10 ans pour les factures) et ne sont transmises qu'au comptable ou à l'assureur si nécessaire. Le client peut y accéder, les corriger ou demander leur suppression en écrivant à mv.entreprise91@gmail.com.
18. Litiges. Le contrat est soumis au droit français. En cas de désaccord, le client contacte d'abord l'entreprise pour rechercher une solution amiable. À défaut, le litige est porté devant le tribunal compétent.
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
        // Rappel envoyé au client 1 ou 2 jours avant ({date_rdv}, {objet_rdv}).
        'visit_reminder_subject' => 'Rappel : notre passage {date_rdv}',
        'visit_reminder' => "{salutation}\nPetit rappel : nous passerons {date_rdv} au {adresse_chantier} ({objet_rdv}).\nEn cas d'empêchement, merci de nous prévenir au {telephone}.\nBien cordialement,\n{entreprise}",
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
        // Relance automatique des devis sans réponse (jours après l'envoi ; 0 = pas de 2e relance).
        'quotes_auto' => false,
        'quotes_first_days' => 7,
        'quotes_second_days' => 15,
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

    // Formulaire du site WordPress : ses emails de notification sont lus dans Gmail.
    'site_form' => [
        'enabled' => false,
        'enabled_at' => null,
        'from' => '',
        'subject' => '',
        'last_check_at' => null,
        'last_error' => '',
    ],

    // Statistiques du site internet : lecture de WordPress.com (Jetpack Stats). Jeton enregistré chiffré.
    'site_stats' => [
        'wpcom_client_id' => '',
        'wpcom_client_secret' => '',
        'wpcom_token' => '',
        'wpcom_blog_id' => '',
        'wpcom_connected_at' => null,
        'wpcom_last_sync_at' => null,
        'wpcom_last_error' => '',
        'wpcom_top' => [],
    ],

    // Personnalisation : barre du bas (3 raccourcis) et blocs de la page d'accueil.
    'layout' => [
        'bottom_nav' => ['dashboard', 'clients', 'documents'],
        'home_blocks' => ['today', 'requests', 'kpis', 'planning', 'todo', 'activity', 'payments'],
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

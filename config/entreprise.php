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
        'owner_name' => 'Matt Violet',
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
    ],

    'vat' => [
        // 'assujetti' ou 'franchise'
        'regime' => 'assujetti',
        'franchise_mention' => 'TVA non applicable, art. 293 B du CGI',
        'reduced_rate_mention_enabled' => true,
    ],

    'branding' => [
        'color_accent' => '#3CBDE8',
        'color_primary' => '#494949',
        'color_text' => '#2C3E50',
        'color_background' => '#ECF0F1',
        'font_heading' => 'Montserrat',
        'font_body' => 'Figtree',
        'logo_path' => null,
    ],

    'documents' => [
        'quote_validity_days' => 30,
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

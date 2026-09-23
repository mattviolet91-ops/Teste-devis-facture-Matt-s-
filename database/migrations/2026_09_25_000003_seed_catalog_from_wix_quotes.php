<?php

use App\Support\Search;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| Bibliothèque de départ, construite à partir de 50 devis Wix réels
| (n° 0002287 à 0002338, mars → septembre 2026). Prix = prix habituels
| constatés ; tout est modifiable dans « Prestations ».
*/
return new class extends Migration
{
    private const SECURITE = "Mise en place d'une échelle à coulisse\nMise en place d'une échelle de toit\nMise en sécurité du chantier (harnais, stop-chute, corde)";

    private const FIN = "Nettoyage du chantier\nEnlèvement des déchets et mise en déchèterie agréée";

    public function up(): void
    {
        if (DB::table('catalog_items')->exists()) {
            return;
        }

        $now = now();
        $s = self::SECURITE;
        $f = self::FIN;

        $catalog = [
            'Nettoyage & traitement de toiture' => [
                ['Traitement de la toiture (Dalep 2100)', "$s\nVérification de la toiture\nGrattage manuel des mousses et lichens importants\nNettoyage des gouttières, des chéneaux ainsi que des Velux\nChangement des tuiles cassées ou fissurées (fournies par le client)\nApplication par pulvérisation d'un traitement nettoyant et hydrofuge Dalep 2100\n$f", 'm²', 800],
                ['Ajout d\'un hydrofuge Dalep D21 au traitement', "Ajout d'un hydrofuge Dalep D21 au mélange du traitement Dalep 2100", 'm²', 300],
                ['Nettoyage basse pression et hydrofuge incolore (Dalep D21)', "$s\nVérification de la toiture\nGrattage manuel des mousses et lichens importants\nApplication par pulvérisation d'un produit nettoyant « Dalep 2100 » et « Dalep DBoost »\nNettoyage de la toiture à basse pression\nNettoyage des gouttières, des chéneaux et Velux\nApplication par pulvérisation d'un hydrofuge incolore « Dalep D21 »\n$f", 'm²', 1250],
                ['Nettoyage basse pression et hydrofuge coloré', "$s\nVérification de la toiture\nGrattage manuel des mousses et lichens importants\nApplication par pulvérisation d'un produit nettoyant « Dalep 2100 » et « Dalep DBoost »\nNettoyage de la toiture à basse pression\nNettoyage des gouttières, des chéneaux et Velux\nApplication par pulvérisation d'un hydrofuge coloré « CNet hydro »\n$f", 'm²', 1600],
                ['Résine colorée sur toute la surface de la toiture', "$s\nProtection des éléments sensibles par bâche\nApplication par pulvérisation d'un fixateur pour résine hydrofuge spécial toiture (Zolpafix 100 ou équivalent)\nApplication en trois couches croisées d'une résine colorée sur toute la surface de la toiture ainsi que des accessoires (faîtages, rives, etc.) (Revtoit Acryl ou équivalent)\n$f", 'm²', 2500],
                ['Nettoyage des panneaux solaires', "Application d'un produit « Dalep Solar-Net »\nNettoyage manuel au mouilleur spécial panneau solaire\nSéchage et raclette spécial panneau solaire", 'forfait', 32000],
                ['Nettoyage et vérification des gouttières', "Mise en sécurité du chantier\nNettoyage et vérification manuelle des gouttières\nRetrait des feuilles, mousses et débris\nVérification des fixations et de l'écoulement des descentes\n$f", 'ml', 0],
                ['Pose de pare-feuilles aluminium', "Mise en place d'une échelle à coulisse\nNettoyage des chéneaux\nFourniture et pose de pare-feuilles en aluminium\nCollage des pare-feuilles à la colle polymère transparente Crystal", 'ml', 4400],
                ['Nettoyage et réfection des joints de véranda', "Mise en place d'une échelle à coulisse\nMise en sécurité du chantier\nApplication d'un produit nettoyant sur la toiture\nNettoyage à basse pression de la toiture de la véranda\nNettoyage manuel avec microfibre adaptée\nRinçage à l'eau déminéralisée\nMasquage des endroits sensibles\nRéfection des joints avec polymère (Crystal)\nNettoyage du chantier", 'forfait', 59000],
            ],
            'Réparations de couverture' => [
                ['Remplacement d\'une faîtière', "Dépose de l'ancienne faîtière\nFourniture et pose d'une faîtière à emboîtement\nDécoupe de la tuile faîtière\nFixation par collage", 'u', 15000],
                ['Changement des vis de faîtage', "Mise en place d'une échelle à coulisse\nMise en sécurité du chantier\nDépose des anciens clous de faîtage\nFourniture et pose de vis spéciales faîtage avec rondelle d'étanchéité\nMise en déchèterie des anciens clous", 'forfait', 14900],
                ['Réfection des cimentations de faîtage (Webercel-tuile)', "$s\nBrossage mécanique de toutes les cimentations du faîtage\nNettoyage des cimentations\nFourniture et application d'un mortier spécial toiture (Webercel-tuile)\nAjout d'un colorant (coloris de la tuile)\nNettoyage du chantier", 'ml', 3200],
                ['Réfection de cimentations de solin', "$s\nNettoyage mécanique des cimentations existantes\nPiquetage des cimentations existantes\nFourniture et mise en place de nouvelles cimentations à base de chaux (Webercel-tuile)\nNettoyage du chantier", 'forfait', 62000],
                ['Travaux divers : remaniement et tuiles défectueuses', "Remaniement des tuiles déplacées\nChangement des tuiles défectueuses (tuiles fournies par le client)\nMise en place de vis de rive aux endroits où les fixations sont manquantes", 'forfait', 26900],
                ['Agrandissement des noues', "Installation d'une échelle à coulisse\nInstallation d'une échelle de toit\nAgrandissement des tuiles des noues\nRemplacement des tuiles abîmées (fournies par le client)\nRamassage et débarras du chantier", 'forfait', 56000],
                ['Création d\'une sortie de ventilation (tuile à douille)', "Dépose des éléments existants\nMise en place d'un conduit adapté et installation d'une tuile à douille", 'forfait', 12900],
                ['Évacuation de VMC et bouchage de cheminée', "$s\nFourniture et installation d'une aération pour VMC\nApplication d'une étanchéité autour de la VMC\nBouchage de la cheminée au mortier\n$f", 'forfait', 67900],
                ['Installation du chantier', "Mise en place d'une échelle à coulisse\nMise en place d'une échelle de toit\nMise en sécurité du chantier", 'forfait', 2900],
            ],
            'Zinguerie & étanchéité' => [
                ['Réfection d\'un pied de cheminée en zinc plomb', "$s\nDépose de l'ancien solin en plomb\nDépose du zinc de cheminée arrière\nFaçonnage et pose d'un nouveau zinc de cheminée\nFourniture et pose de nouvelles bandes en zinc plomb 1,50 mm\nMise en place d'un joint polymère aux endroits nécessaires\n$f", 'forfait', 35000],
                ['Réfection d\'une bande solin en zinc plomb', "$s\nDépose de l'ancienne bande porte-solin\nNettoyage de la surface\nFourniture et pose d'une bande de plomb sur toute la longueur\nFourniture et pose de baguettes porte-solin à mastic\nApplication d'un joint d'étanchéité polymère aux endroits nécessaires\n$f", 'ml', 9000],
                ['Mise en place de rives à bavette en zinc', "$s\nFourniture et pose de rives à bavette en zinc naturel + plomb, fixation par chevilles\nCollage du plomb\nNettoyage du chantier\nEnlèvement des déchets", 'forfait', 51000],
                ['Reprise de l\'étanchéité d\'un chéneau', "Mise en sécurité de la zone d'intervention\nNettoyage de la zone à traiter\nPréparation du support\nFourniture et application d'un mastic d'étanchéité adapté\nLissage pour garantir une bonne évacuation des eaux\nVérification visuelle après intervention\nNettoyage de fin de chantier", 'forfait', 89000],
                ['Remplacement des couloirs d\'eau (VM Zinc)', "Dépose des couloirs d'eau\nDépose des tuiles adjacentes au couloir\nFourniture et pose de nouveaux couloirs d'eau en zinc naturel VM Zinc", 'u', 0],
            ],
            'Velux' => [
                ['Remplacement d\'un raccord Velux', "$s\nDépose des tuiles adjacentes au Velux\nDépose du raccord Velux\nRehausse du Velux si nécessaire\nFourniture et pose d'un nouveau raccord Velux adapté à la couverture\nRemise en place et découpe des tuiles autour du Velux\n$f", 'u', 29900],
                ['Réparation d\'un Velux (chevêtre et raccord)', "$s\nDépose des tuiles adjacentes au Velux\nDépose du raccord Velux\nNettoyage et vérification du chevêtre\nRehaussement du Velux\nFourniture et installation d'un nouveau raccord Velux\nRemise en place et découpe des tuiles adjacentes\n$f", 'u', 54900],
                ['Rehausse de Velux', "$s\nDépose des tuiles adjacentes aux Velux\nDépose du raccord Velux\nRehausse de chaque Velux entre 18 et 41 mm\nRemise en place des raccords Velux\n$f", 'u', 11000],
                ['Réfection du joint de capot Velux', "$s\nDépose du capot externe\nDépose de l'ancien joint mousse\nFourniture et pose d'un nouveau joint d'étanchéité spécial fenêtre\nRemise en place du capot externe\nFourniture et pose d'un joint polymère à divers endroits\n$f", 'u', 14500],
                ['Diagnostic d\'un volet roulant Velux', 'Diagnostic et réinitialisation du volet roulant ainsi que de la télécommande', 'u', 1900],
            ],
            'Réfection, charpente & isolation' => [
                ['Réfection complète de la toiture en tuiles', "Mise en place d'un échafaudage sous les pans de toiture\nMise en sécurité du chantier\nMise en place d'un monte-tuiles\nDépose de toutes les tuiles et de tous les liteaux\nNettoyage et vérification de la charpente\nFourniture et pose d'un écran de sous-toiture HPV sur toute la surface\nFourniture et pose de contre-liteaux 18x40 en bois traité, fixation par clous crantés\nFourniture et pose de liteaux 27x40 en bois traité\nVérification des chevêtres, rehausse des Velux si nécessaire\nFourniture et pose de tuiles neuves\nFourniture et pose des tuiles d'accessoires (chatière, tuile à douille, etc.)\nFourniture et pose d'un closoir ventilé sur la longueur du faîtage\nFourniture et pose de tuiles faîtières fixées par tire-fond avec rondelle étanche EPDM\n$f", 'm²', 18000],
                ['Réfection d\'une toiture en ardoise naturelle', "$s\nDépose des ardoises et des liteaux\nNettoyage et vérification de la charpente\nFourniture et pose d'un écran de sous-toiture HPV Soprema\nFourniture et pose de contre-liteaux et liteaux en bois traité\nFaçonnage et installation des bandes d'étanchéité en zinc\nFourniture et pose d'ardoises naturelles fixées par crochets et clous\n$f", 'm²', 28000],
                ['Couverture en bac acier', "Dépose des plaques existantes\nFourniture et pose de plaques bac acier RAL 7016\nPose et fixation par tire-fond avec cavaliers étanches\nFourniture et pose des rives et du faîtage\nFinitions", 'm²', 7200],
                ['Isolant mince HPV (supplément réfection)', "Isolant mince HPV Toiture Confort Soprema, épaisseur 80 mm, R = 3,26\nRemplacement des liteaux 18x40 par des liteaux renforcés 40x40", 'm²', 3600],
                ['Traitement de la charpente', "Nettoyage et balayage de la charpente\nFourniture et mise en place de chevilles d'injection avec billes anti-retour\nInjection à haute pression d'un traitement curatif et préventif (Xylophène)\nApplication par pulvérisation d'un traitement curatif et préventif", 'forfait', 110000],
                ['Isolation en ouate de cellulose (15 cm)', "Ajout d'une isolation en ouate de cellulose, 15 cm d'épaisseur", 'm²', 1620],
            ],
            'Façades & boiseries' => [
                ['Nettoyage de façade (Dalep 2100 + DBoost)', "Installation d'une échelle à coulisse\nMise en sécurité du chantier\nProtection des sols et des plantes par bâche\nNettoyage à basse pression\nApplication par pulvérisation du produit nettoyant Dalep 2100 avec l'accélérateur Dalep DBoost\nNettoyage du chantier", 'm²', 700],
                ['Mise en peinture d\'un pignon (revêtement D3)', "Installation d'une échelle à coulisse\nNettoyage à basse pression du pignon\nProtection des sols par bâches\nFourniture et application d'un primaire pour revêtement de façade\nFourniture et application en deux couches d'un revêtement semi-épais classe D3 avec protection anti-UV (couleur au choix du client)\n$f", 'm²', 4150],
                ['Réfection des boiseries de rive et sous-faces', "Installation d'une échelle à coulisse\nNettoyage à basse pression des boiseries\nProtection des sols par bâches\nFourniture et application d'un primaire de peinture acrylique\nFourniture et application en deux couches d'une peinture microporeuse anti-UV aspect satiné\n$f", 'u', 50000],
                ['Lasure d\'une joue de lucarne en bois', "Mise en place du chantier\nProtection par bâches des endroits sensibles\nPonçage en trois passes (60, 120, 180)\nNettoyage des joues de lucarne\nApplication en trois passes d'une lasure microporeuse anti-UV Unikalo (teinte au choix du client)\n$f", 'u', 25000],
                ['Réfection d\'une joue de chien-assis (bardage PVC)', "$s\nDépose de l'ancienne joue en bois\nFourniture et pose d'une nouvelle joue en bois traité\nFourniture et pose de profils de départ et d'arrêt\nFourniture et pose de clin PVC (couleur au choix)\n$f", 'u', 62500],
            ],
        ];

        $categoryPosition = 0;
        foreach ($catalog as $category => $items) {
            $categoryId = DB::table('catalog_categories')->insertGetId([
                'name' => $category, 'position' => ++$categoryPosition, 'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ($items as $position => [$name, $description, $unit, $price]) {
                DB::table('catalog_items')->insert([
                    'category_id' => $categoryId, 'name' => $name, 'description' => $description, 'unit' => $unit,
                    'unit_price' => $price, 'vat_rate' => null, 'is_active' => true, 'position' => $position + 1,
                    'search_index' => Search::index([$name, $description, $category]),
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        $templates = [
            ['payment_terms', 'Acompte 40 % / solde 60 %', 'Acompte de 40 % à la signature du devis, puis 60 % à la fin des travaux.', true],
            ['payment_terms', 'Paiement à la fin des travaux', 'Paiement de la totalité à la fin des travaux.', false],
            ['payment_terms', 'Paiement à réception', 'Paiement à réception de la facture.', false],
            ['note', 'Prix global et forfaitaire', 'Ces travaux sont effectués pour un prix global et forfaitaire.', true],
            ['note', 'Délai d\'intervention', 'Intervention possible sous 10 à 15 jours.', false],
            ['note', 'Conditions météo', 'Intervention prévue sous réserve des conditions météorologiques.', false],
            ['note', 'Durée estimée', 'Durée estimée : 15 à 21 jours ouvrés (selon conditions météo).', false],
            ['note', 'Sécurité travaux en hauteur', 'Intervention réalisée dans le respect des règles de sécurité et de la réglementation en vigueur pour les travaux en hauteur.', false],
            ['note', 'Produits professionnels', 'Intervention réalisée avec des produits professionnels garantissant un résultat durable dans le temps.', false],
            ['note', 'Effet progressif du traitement', 'Le traitement agit progressivement dans le temps selon l\'état du support, l\'exposition de la toiture et les conditions météorologiques. Le résultat visuel peut évoluer sur plusieurs semaines à plusieurs mois.', false],
            ['note', 'Travaux intérieurs', 'Travaux intérieurs à la charge du client.', false],
            ['note', 'Référence interne', 'Rappel de votre référence interne : ', false],
            ['note', 'Contact sur place', 'Contact sur place : ', false],
            ['note', 'Accès à la toiture', 'Prévoir l\'accès à la toiture le jour de l\'intervention.', false],
            ['step', 'Échelle à coulisse', 'Mise en place d\'une échelle à coulisse', false],
            ['step', 'Échelle de toit', 'Mise en place d\'une échelle de toit', false],
            ['step', 'Sécurité du chantier', 'Mise en sécurité du chantier (harnais, stop-chute, corde)', false],
            ['step', 'Échafaudage', 'Mise en place d\'un échafaudage', false],
            ['step', 'Vérification toiture', 'Vérification de la toiture', false],
            ['step', 'Protection par bâches', 'Protection des sols et des éléments sensibles par bâches', false],
            ['step', 'Tuiles fournies par le client', 'Changement des tuiles cassées ou fissurées (fournies par le client)', false],
            ['step', 'Nettoyage du chantier', 'Nettoyage du chantier', false],
            ['step', 'Déchets', 'Enlèvement des déchets et mise en déchèterie agréée', false],
        ];

        foreach ($templates as $position => [$type, $label, $body, $default]) {
            DB::table('text_templates')->insert([
                'type' => $type, 'label' => $label, 'body' => $body, 'is_default' => $default,
                'position' => $position + 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('text_templates')->delete();
        DB::table('catalog_items')->delete();
        DB::table('catalog_categories')->delete();
    }
};

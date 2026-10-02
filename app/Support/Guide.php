<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Contenu de la page « Guide » : chaque fonctionnalité expliquée pas à pas.
 * Une rubrique n'est montrée que si le compte peut ouvrir la page dont elle parle.
 */
final class Guide
{
    /**
     * @return list<array{id: string, title: string, icon: string, route: ?string, link: ?string, intro: string, steps: list<string>, tips: list<string>, image?: string, caption?: string}>
     */
    public static function sections(User $user): array
    {
        return array_values(array_filter(self::all(), fn (array $s) => ! $s['route'] || $user->canOpen($s['route'])));
    }

    /** Page de l'application → rubrique du guide (bouton « ? »). Le plus précis d'abord. */
    private const PAGES = [
        'quotes.express*' => 'devis-express',
        'settings.api*' => 'claude',
        'settings.account*' => 'compte',
        'settings.*' => 'reglages',
        'dashboard' => 'premiers-pas',
        'search' => 'premiers-pas',
        'clients.*' => 'clients',
        'worksites.*' => 'clients',
        'requests.*' => 'demandes',
        'quotes.*' => 'devis',
        'documents' => 'devis',
        'invoices.*' => 'factures',
        'expenses.*' => 'factures',
        'payments.*' => 'paiements',
        'reminders.*' => 'relances',
        'planning.*' => 'planning',
        'reports.*' => 'rapports',
        'photos.*' => 'photos',
        'maintenance.*' => 'entretiens',
        'reviews.*' => 'avis',
        'catalog.*' => 'prestations',
        'emails.*' => 'emails',
        'statistics' => 'statistiques',
        'site-stats.*' => 'statistiques',
        'trash.*' => 'corbeille',
        'archives.*' => 'corbeille',
    ];

    /** Captures d'écran du guide (aussi gardées sur le téléphone pour le mode hors connexion). */
    public static function images(User $user): array
    {
        return array_values(array_filter(array_map(fn (array $s) => isset($s['image']) ? asset('images/guide/'.$s['image']) : null, self::sections($user))));
    }

    /** Lien du bouton « ? » : la rubrique de la page ouverte, sinon le début du guide. */
    public static function urlFor(?string $routeName): string
    {
        foreach (self::PAGES as $pattern => $id) {
            if ($routeName && Str::is($pattern, $routeName)) {
                return route('guide').'#'.$id;
            }
        }

        return route('guide');
    }

    /**
     * Astuce du jour : une des astuces du guide, différente chaque jour.
     *
     * @return array{text: string, section: string, title: string}|null
     */
    public static function tipOfTheDay(User $user, ?\DateTimeInterface $day = null): ?array
    {
        $tips = [];
        foreach (self::sections($user) as $section) {
            foreach ($section['tips'] as $tip) {
                $tips[] = ['text' => $tip, 'section' => $section['id'], 'title' => $section['title']];
            }
        }
        if ($tips === []) {
            return null;
        }
        $day ??= now();

        return $tips[((int) $day->format('Y') * 366 + (int) $day->format('z')) % count($tips)];
    }

    /** @return list<array<string, mixed>> */
    private static function all(): array
    {
        return [
            [
                'id' => 'premiers-pas', 'image' => 'accueil.jpg', 'caption' => 'L\'accueil : la journée, les appels à passer et les demandes reçues.', 'title' => 'Premiers pas', 'icon' => 'home', 'route' => 'dashboard', 'link' => 'Ouvrir l\'accueil',
                'intro' => 'L\'accueil résume votre journée : rendez-vous du jour, demandes reçues, factures à encaisser et ce qu\'il reste à faire.',
                'steps' => [
                    'Barre du bas : vos pages principales. Le bouton rond « + Nouveau » crée un devis, une facture, un client, une photo, un paiement ou un rendez-vous.',
                    '« Plus » (en bas à droite) ouvre toutes les autres pages, les Réglages et la déconnexion.',
                    'La loupe recherche partout à la fois : nom, téléphone, ville, numéro de devis ou de facture.',
                    'Sur une fiche, « Retour » en haut à gauche ramène à la liste.',
                    'Dans les listes, glissez une ligne vers la gauche pour l\'action rapide (relancer, encaisser…).',
                ],
                'tips' => [
                    'Ajoutez l\'application à l\'écran d\'accueil du téléphone (Safari : Partager → « Sur l\'écran d\'accueil » ; Chrome : ⋮ → « Ajouter à l\'écran d\'accueil ») : elle s\'ouvre comme une vraie application.',
                    'Menu « Plus » → « Grands boutons » : tout est plus gros, pratique avec des gants.',
                    'Réglages → Mon affichage : choisissez les pages de la barre du bas et les blocs de l\'accueil.',
                ],
            ],
            [
                'id' => 'clients', 'image' => 'client.jpg', 'caption' => 'Une fiche client : appeler, SMS, email, devis ou rendez-vous en un geste.', 'title' => 'Clients et chantiers', 'icon' => 'users', 'route' => 'clients.index', 'link' => 'Ouvrir les clients',
                'intro' => 'La fiche client regroupe tout : coordonnées, chantiers, devis, factures, photos, rapports, entretiens et historique.',
                'steps' => [
                    '« + Nouveau » → Client : particulier ou professionnel, nom, téléphone, email, adresse.',
                    'Ajoutez un ou plusieurs chantiers (adresses de travaux) : utile quand le chantier n\'est pas à l\'adresse du client.',
                    'Depuis la fiche : Appeler, SMS, Email, Itinéraire (GPS), Nouveau devis, Rendez-vous, Rapport.',
                    'Notez « Comment nous a-t-il connus ? » (bouche-à-oreille, Google, site…) : il alimente les statistiques. Il se modifie aussi après le rendez-vous.',
                ],
                'tips' => [
                    'Importer des contacts (page Clients) : depuis Wix (Contacts → Plus d\'actions → Exporter) ou un tableau CSV ; un aperçu montre les nouveaux clients avant d\'importer.',
                    'Un client supprimé va dans la corbeille : vous pouvez le restaurer.',
                ],
            ],
            [
                'id' => 'demandes', 'title' => 'Demandes de devis du site', 'icon' => 'mail', 'route' => 'requests.index', 'link' => 'Ouvrir les demandes',
                'intro' => 'Les demandes envoyées par le formulaire de votre site arrivent ici, avec les photos du client.',
                'steps' => [
                    'Onglet « À traiter » : chaque nouvelle demande, avec le message et les photos.',
                    'Appeler ou SMS en un geste, puis « Rendez-vous » pour fixer la visite ou « Faire le devis » directement : le client est déjà rempli.',
                    'Une fois traitée, la demande passe dans « Traitées ».',
                ],
                'tips' => [
                    '« Lien du formulaire » : le lien à mettre sur votre site, vos réseaux ou une carte de visite (QR code).',
                    'Réglages → Formulaire du site : l\'application lit dans Gmail les emails envoyés par le formulaire de votre site et les range ici automatiquement.',
                ],
            ],
            [
                'id' => 'devis', 'image' => 'devis.jpg', 'caption' => 'L\'éditeur de devis : chaque prestation avec sa quantité, son unité et son prix.', 'title' => 'Faire un devis', 'icon' => 'file', 'route' => 'quotes.index', 'link' => 'Ouvrir les devis',
                'intro' => 'Un devis reste un brouillon modifiable tant qu\'il n\'est pas envoyé. À l\'envoi, il reçoit son numéro.',
                'steps' => [
                    'Devis → « Nouveau devis » : choisissez le client et le chantier, puis l\'objet.',
                    'Ajoutez les lignes : « Bibliothèque » reprend une prestation toute prête (libellé, description, prix, TVA) ; « Ligne libre » pour le reste ; « Section » et « Texte » pour organiser.',
                    '« Surface de toiture » : entrez les pans (longueur × largeur, pente) ; l\'application calcule les m², puis « Utiliser cette quantité ».',
                    'Ajoutez des photos du chantier à faire apparaître dans le PDF.',
                    'Vérifiez avec « PDF », puis « Envoyer par email » (le PDF et un lien sont joints) ou envoyez le lien par SMS / WhatsApp.',
                ],
                'tips' => [
                    'Le client ouvre le lien, lit le devis et peut l\'accepter en signant sur l\'écran, demander une modification ou le refuser. Vous êtes prévenu.',
                    '« Faire signer sur place » : le client signe directement sur votre téléphone.',
                    'Devis déjà envoyé à changer ? « Modifier » prépare une version modifiable ; à l\'envoi, elle garde le numéro avec « -V2 » et remplace l\'ancienne (l\'historique est gardé). Un devis déjà facturé ne se modifie plus.',
                    '« Dupliquer » reprend un devis pour un autre client ; « Relancer » envoie un rappel au client qui n\'a pas répondu.',
                    'Les notes internes ne sont jamais montrées au client.',
                ],
            ],
            [
                'id' => 'devis-express', 'image' => 'devis-express.jpg', 'caption' => 'L\'aperçu du Devis express : client, lignes et total à vérifier.', 'title' => 'Devis express (en une phrase)', 'icon' => 'file', 'route' => 'quotes.express', 'link' => 'Ouvrir Devis express',
                'intro' => 'Écrivez ou dictez le devis en une phrase : l\'application prépare le brouillon. Rien n\'est envoyé au client.',
                'steps' => [
                    'Devis → « Devis express » (ou le lien « Plus rapide » sur Nouveau devis).',
                    'Commencez par le client, puis chaque prestation avec sa quantité et son prix. Exemple : « Mme Martin, démoussage 120 m² à 12 €, 3 faîtières à 150 €, évacuation forfait 150 € ».',
                    'Séparez les prestations par une virgule, un point, « et », un tiret ou un retour à la ligne.',
                    '« Voir l\'aperçu » : vérifiez le client, chaque ligne et le total. Les lignes en rouge sont à corriger.',
                    '« Créer le brouillon » : le devis s\'ouvre, vous pouvez le compléter puis l\'envoyer.',
                ],
                'tips' => [
                    'Le micro du clavier permet de dicter la phrase.',
                    'Écrivez le nom exact d\'une prestation de votre bibliothèque pour reprendre sa description et sa TVA ; sans prix, son prix est repris.',
                    'L\'application ne devine jamais : client introuvable, plusieurs clients possibles ou prix manquant sont signalés.',
                    'Écrivez les prix HT ; « TTC » est signalé.',
                ],
            ],
            [
                'id' => 'factures', 'image' => 'facture.jpg', 'caption' => 'Une facture : envoyer, PDF, relancer, et « Encaisser » en bas.', 'title' => 'Factures', 'icon' => 'receipt', 'route' => 'invoices.index', 'link' => 'Ouvrir les factures',
                'intro' => 'Facturez un devis accepté en un clic, ou créez une facture libre. Le numéro est attribué à l\'envoi.',
                'steps' => [
                    'Sur un devis accepté : « Facturer » → facture complète, d\'acompte (un pourcentage), de situation ou de solde. Les acomptes déjà facturés sont déduits.',
                    'Vérifiez avec « PDF », puis « Envoyer par email » ou « Marquer comme envoyée » si vous la remettez en main propre.',
                    'Le client reçoit un lien pour voir et télécharger sa facture, et la payer par carte si le paiement en ligne est activé.',
                    '« Encaisser » enregistre un règlement (virement, chèque, espèces, carte, myPOS) ; la facture passe en « Payée » une fois soldée.',
                ],
                'tips' => [
                    'Une facture envoyée ne se modifie plus (obligation légale) : « Modifier » émet un avoir qui l\'annule et prépare une copie corrigée avec un nouveau numéro.',
                    'Bloc « Frais » : notez vos dépenses du chantier (matériaux, location…) avec le justificatif ; l\'application calcule ce qu\'il vous reste. Le client ne le voit jamais.',
                    'Après paiement : « Envoyer un remerciement » et « Demander un avis Google ».',
                ],
            ],
            [
                'id' => 'paiements', 'title' => 'Paiements', 'icon' => 'wallet', 'route' => 'payments.index', 'link' => 'Ouvrir les paiements',
                'intro' => 'Tout ce qui a été encaissé sur la période, par moyen de paiement, et les factures restant à encaisser.',
                'steps' => [
                    '« Encaisser un paiement » : choisissez la facture, le montant, la date et le moyen de paiement.',
                    'Un paiement partiel laisse la facture « partiellement payée » avec le reste dû.',
                    'Les paiements par carte en ligne (myPOS) sont enregistrés automatiquement.',
                ],
                'tips' => ['Réglages → Paiement en ligne : activez le paiement par carte sur les factures en ligne.'],
            ],
            [
                'id' => 'relances', 'image' => 'relances.jpg', 'caption' => 'Les factures à relancer, avec le retard.', 'title' => 'Relances de factures', 'icon' => 'send', 'route' => 'reminders.index', 'link' => 'Ouvrir les relances',
                'intro' => 'Les factures en retard, ou qui arrivent à échéance dans les 3 jours.',
                'steps' => [
                    'Ouvrez une facture de la liste : le message est déjà rédigé (rappel avant échéance ou relance).',
                    'Envoyez-le par email (avec la facture), SMS ou WhatsApp, ou copiez-le.',
                    'La relance est notée dans l\'historique de la facture.',
                ],
                'tips' => ['Réglages → Textes types : modifiez le texte des messages.'],
            ],
            [
                'id' => 'planning', 'image' => 'planning.jpg', 'caption' => 'Le planning de la semaine.', 'title' => 'Planning et rendez-vous', 'icon' => 'calendar', 'route' => 'planning.index', 'link' => 'Ouvrir le planning',
                'intro' => 'Rendez-vous et chantiers à la semaine ou au mois, avec la météo.',
                'steps' => [
                    '« Ajouter un rendez-vous » (visite, métré) ou un chantier, avec le client, l\'adresse, la date et l\'heure.',
                    '« Devis acceptés à planifier » : les chantiers signés qui n\'ont pas encore de date.',
                    '« Prévenir le client » : message prêt à envoyer par SMS ou WhatsApp pour confirmer la date.',
                    '« Ajouter à mon agenda » : le rendez-vous va dans l\'agenda du téléphone.',
                    'Une fois terminé : « Fait », puis « Faire le devis » (après une visite) ou « Faire le rapport d\'intervention » (après des travaux).',
                ],
                'tips' => ['La météo des prochains jours s\'affiche pour décider d\'aller sur le toit ou non.'],
            ],
            [
                'id' => 'rapports', 'title' => 'Rapports d\'intervention', 'icon' => 'tool', 'route' => 'reports.create', 'link' => null,
                'intro' => 'Un compte rendu professionnel en PDF, avec photos, à remettre au client après une intervention.',
                'steps' => [
                    'Depuis le planning ou la fiche client : « Rapport ».',
                    'Remplissez le constat, les travaux réalisés et les préconisations ; ajoutez les photos avant / après.',
                    '« Voir le PDF » pour vérifier, puis « Envoyer le rapport » par email (PDF joint), SMS ou WhatsApp (lien).',
                ],
                'tips' => ['Le rapport reste sur la fiche du client : utile pour un dossier d\'assurance ou un prochain devis.'],
            ],
            [
                'id' => 'photos', 'title' => 'Photos', 'icon' => 'camera', 'route' => 'photos.index', 'link' => 'Ouvrir les photos',
                'intro' => 'Les photos sont rangées par chantier.',
                'steps' => [
                    '« + Nouveau » → Photo, ou « Prendre ou choisir des photos » sur un chantier.',
                    'Touchez une photo puis « Annoter la photo » pour entourer ou fléchir un défaut.',
                    'Les photos peuvent être ajoutées dans le PDF du devis ou du rapport.',
                ],
                'tips' => [],
            ],
            [
                'id' => 'entretiens', 'title' => 'Entretiens à proposer', 'icon' => 'tool', 'route' => 'maintenance.index', 'link' => 'Ouvrir les entretiens',
                'intro' => 'Les clients à recontacter pour un nouvel entretien (démoussage, nettoyage de gouttières…), calculés d\'après les prestations déjà facturées.',
                'steps' => [
                    'Onglet « À relancer » : les clients dont l\'entretien arrive.',
                    'Le message est prêt : Appeler, SMS, WhatsApp, Email ou Copier.',
                    '« Faire un devis » pour proposer l\'entretien.',
                ],
                'tips' => ['Dans la bibliothèque, choisissez pour chaque prestation la « Relance d\'entretien » (ex. démoussage : après 3 ans).'],
            ],
            [
                'id' => 'avis', 'title' => 'Avis Google', 'icon' => 'check', 'route' => 'reviews.index', 'link' => 'Ouvrir les avis',
                'intro' => 'Après chaque chantier payé, un email demande un avis Google au client.',
                'steps' => [
                    '« À demander » : les clients sans email, à qui demander par SMS ou WhatsApp.',
                    '« Demandes envoyées » : le suivi de ce qui a été demandé.',
                ],
                'tips' => ['Réglages → Emails : le lien vers votre fiche Google et le texte de la demande.'],
            ],
            [
                'id' => 'prestations', 'title' => 'Bibliothèque de prestations', 'icon' => 'book', 'route' => 'catalog.index', 'link' => 'Ouvrir la bibliothèque',
                'intro' => 'Vos prestations toutes prêtes (libellé, description, unité, prix, TVA), reprises dans les devis en un clic.',
                'steps' => [
                    '« Nouvelle prestation » : nom clair (ex. « Démoussage de toiture »), description pour le client, unité (m², ml, u, h, forfait), prix HT et TVA.',
                    'Rangez-les par catégorie pour les retrouver vite.',
                ],
                'tips' => ['Modifier une prestation ne change pas les devis déjà faits.'],
            ],
            [
                'id' => 'emails', 'title' => 'Emails', 'icon' => 'mail', 'route' => 'emails.index', 'link' => 'Voir les emails envoyés',
                'intro' => 'Tous les emails envoyés depuis l\'application, avec leurs pièces jointes.',
                'steps' => [
                    'Pour écrire, ouvrez un devis, une facture ou une fiche client puis « Envoyer par email » : le message est prérempli.',
                    '« Ouvrir dans ma messagerie » si vous préférez envoyer depuis votre propre boîte mail.',
                ],
                'tips' => ['Réglages → Emails : l\'adresse d\'envoi et les textes des emails.'],
            ],
            [
                'id' => 'statistiques', 'image' => 'statistiques.jpg', 'caption' => 'D\'où viennent vos clients.', 'title' => 'Statistiques', 'icon' => 'chart', 'route' => 'statistics', 'link' => 'Ouvrir les statistiques',
                'intro' => 'Chiffre d\'affaires, devis acceptés, et d\'où viennent vos clients (et ce qu\'ils vous rapportent).',
                'steps' => [
                    'Choisissez la période en haut de la page.',
                    '« Comment vos clients vous ont trouvés » : le graphe de la provenance de vos clients.',
                    '« Site internet » : visites de votre site, pages les plus vues, provenance des visiteurs et clics sur Appeler, Email, WhatsApp ou Demander un devis.',
                ],
                'tips' => ['Le compteur du site n\'utilise pas de cookie et n\'enregistre pas d\'adresse IP.'],
            ],
            [
                'id' => 'hors-ligne', 'title' => 'Sans réseau (hors connexion)', 'icon' => 'shield', 'route' => null, 'link' => null,
                'intro' => 'Sur un toit sans réseau, les pages déjà ouvertes restent consultables et les formulaires remplis sont gardés sur le téléphone.',
                'steps' => [
                    'Une barre en haut indique quand vous êtes hors connexion.',
                    'Les fiches, devis et factures ouverts récemment s\'affichent quand même.',
                    'Ce que vous enregistrez sans réseau est mis « en attente » ; « Voir » montre la liste.',
                    'Dès que le réseau revient, les envois en attente partent tout seuls.',
                ],
                'tips' => ['Un envoi refusé (champ manquant…) est signalé « à corriger » dans cette liste.'],
            ],
            [
                'id' => 'corbeille', 'title' => 'Corbeille et archives Wix', 'icon' => 'trash', 'route' => 'trash.index', 'link' => 'Ouvrir la corbeille',
                'intro' => 'Ce qui est supprimé (clients, chantiers, devis, brouillons de factures) va d\'abord à la corbeille.',
                'steps' => [
                    'Corbeille : « Restaurer » remet l\'élément à sa place. Il est supprimé définitivement après le délai indiqué.',
                    'Archives Wix : vos anciens devis et factures Wix, gardés avec leur numéro d\'origine. Ils ne comptent ni dans la numérotation ni dans le chiffre d\'affaires.',
                ],
                'tips' => ['Une facture envoyée ne se supprime jamais : elle s\'annule par un avoir.'],
            ],
            [
                'id' => 'reglages', 'title' => 'Réglages', 'icon' => 'settings', 'route' => 'settings.company', 'link' => 'Ouvrir les réglages',
                'intro' => 'Tout ce qui apparaît sur vos documents et le fonctionnement de l\'application.',
                'steps' => [
                    'Entreprise : nom, adresse, SIRET, téléphone, coordonnées bancaires.',
                    'Apparence : logo et couleurs. Mon affichage : barre du bas et blocs de l\'accueil.',
                    'TVA & unités, Numérotation, Documents PDF (validité des devis, mentions, conditions générales), Assurance décennale.',
                    'Emails et Textes types : la messagerie d\'envoi et les messages envoyés aux clients (devis, factures, relances, avis).',
                    'Paiement en ligne (myPOS) et Formulaire du site (demandes reçues par Gmail).',
                    'Comptes : ajoutez un commercial ; il ne voit ni les factures, ni les paiements, ni les réglages.',
                    'Sauvegardes : une sauvegarde est faite chaque nuit ; téléchargez-en régulièrement une copie chez vous.',
                    'Journal : qui a fait quoi et quand.',
                ],
                'tips' => [],
            ],
            [
                'id' => 'claude', 'title' => 'Faire des devis avec Claude', 'icon' => 'send', 'route' => 'settings.api', 'link' => 'Ouvrir Accès Claude',
                'intro' => 'Avec une clé d\'accès, Claude peut préparer des devis brouillons dans l\'application quand vous lui écrivez « devis pour Mme Martin : démoussage 120 m² à 12 €… ».',
                'steps' => [
                    'Réglages → Accès Claude → créez une clé. Elle ne s\'affiche qu\'une fois : enregistrez-la directement dans les réglages de Claude, jamais dans une conversation.',
                    'Demandez ensuite à Claude un devis en une phrase : il vous montre l\'aperçu puis crée le brouillon.',
                    'Ouvrez le brouillon dans l\'application, vérifiez-le et envoyez-le vous-même.',
                ],
                'tips' => ['Claude ne peut que préparer des brouillons : il n\'envoie rien au client. Une clé se révoque en un clic.'],
            ],
            [
                'id' => 'compte', 'title' => 'Mon compte et sécurité', 'icon' => 'shield', 'route' => 'settings.account', 'link' => 'Ouvrir mon compte',
                'intro' => 'Votre mot de passe et vos notifications.',
                'steps' => [
                    'Mon compte : changez votre mot de passe et activez les notifications sur ce téléphone (nouvelle demande, devis accepté…).',
                    '« Mot de passe oublié » sur la page de connexion pour en recevoir un nouveau par email.',
                    'Déconnectez-vous si vous prêtez votre téléphone.',
                ],
                'tips' => [],
            ],
        ];
    }
}

<?php

namespace App\Support;

use App\Services\Settings;

/** Pages proposées dans la barre du bas et blocs de la page d'accueil (personnalisables). */
final class Navigation
{
    /** clé => [libellé, icône, route, motifs de route « active »] */
    public const ITEMS = [
        'dashboard' => ['Accueil', 'home', 'dashboard', ['dashboard']],
        'clients' => ['Clients', 'users', 'clients.index', ['clients.*', 'worksites.*']],
        // Devis et factures réunis : un sélecteur en haut de la page permet de passer de l'un à l'autre.
        'documents' => ['Documents', 'file', 'documents', ['documents', 'quotes.*', 'invoices.*', 'requests.*']],
        'devis' => ['Devis', 'file', 'quotes.index', ['quotes.*']],
        'factures' => ['Factures', 'receipt', 'invoices.index', ['invoices.*']],
        'planning' => ['Planning', 'calendar', 'planning.index', ['planning.*']],
        'demandes' => ['Demandes', 'mail', 'requests.index', ['requests.*']],
        'paiements' => ['Paiements', 'wallet', 'payments.index', ['payments.*']],
        'relances' => ['Relances', 'send', 'reminders.index', ['reminders.*']],
        'photos' => ['Photos', 'camera', 'photos.index', ['photos.*']],
        'entretiens' => ['Entretiens', 'tool', 'maintenance.index', ['maintenance.*']],
        'statistiques' => ['Stats', 'chart', 'statistics', ['statistics']],
        'prestations' => ['Prestations', 'book', 'catalog.index', ['catalog.*']],
        'emails' => ['Emails', 'mail', 'emails.index', ['emails.*']],
        'avis' => ['Avis', 'check', 'reviews.index', ['reviews.*']],
        'recherche' => ['Recherche', 'search', 'search', ['search']],
    ];

    public const DEFAULT_BOTTOM = ['dashboard', 'clients', 'documents'];

    /** Blocs de la page d'accueil : clé => libellé. */
    public const HOME_BLOCKS = [
        'kpis' => 'Chiffres clés (à encaisser, devis en attente, encaissé)',
        'requests' => 'Demandes de devis reçues (site internet)',
        'activity' => 'Activité de la période',
        'todo' => 'À faire (factures en retard, devis sans réponse)',
        'planning' => 'Prochains rendez-vous et chantiers',
        'shortcuts' => 'Raccourcis (nouveau devis, rendez-vous…)',
        'payments' => 'Derniers paiements',
    ];

    public const DEFAULT_HOME = ['requests', 'kpis', 'planning', 'todo', 'activity', 'payments'];

    /** Blocs de l'accueil visibles par un commercial (pas de montants encaissés ni de CA). */
    public const COMMERCIAL_HOME = ['requests', 'planning', 'shortcuts', 'todo'];

    /** @return list<string> */
    public static function bottom(): array
    {
        $keys = array_values(array_filter((array) app(Settings::class)->get('layout.bottom_nav', self::DEFAULT_BOTTOM), fn ($k) => isset(self::ITEMS[$k])));
        $keys = count($keys) === 3 ? $keys : self::DEFAULT_BOTTOM;

        // Compte commercial : les raccourcis qu'il ne peut pas ouvrir sont remplacés.
        $user = auth()->user();
        if ($user && ! $user->isAdmin()) {
            $keys = array_values(array_filter($keys, fn ($k) => $user->canOpen(self::ITEMS[$k][2])));
            foreach (['dashboard', 'clients', 'planning', 'documents', 'demandes'] as $fallback) {
                if (count($keys) < 3 && ! in_array($fallback, $keys, true)) {
                    $keys[] = $fallback;
                }
            }
        }

        return $keys;
    }

    /** @return list<string> */
    public static function home(): array
    {
        $keys = array_values(array_unique(array_filter((array) app(Settings::class)->get('layout.home_blocks', self::DEFAULT_HOME), fn ($k) => isset(self::HOME_BLOCKS[$k]))));
        $keys = $keys ?: self::DEFAULT_HOME;

        $user = auth()->user();
        if ($user && ! $user->isAdmin()) {
            $keys = array_values(array_intersect($keys, self::COMMERCIAL_HOME)) ?: self::COMMERCIAL_HOME;
        }

        return $keys;
    }

    public static function isActive(string $key): bool
    {
        return isset(self::ITEMS[$key]) && request()->routeIs(...self::ITEMS[$key][3]);
    }
}

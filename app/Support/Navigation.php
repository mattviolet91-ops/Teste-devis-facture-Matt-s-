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

    public const DEFAULT_BOTTOM = ['dashboard', 'clients', 'devis'];

    /** Blocs de la page d'accueil : clé => libellé. */
    public const HOME_BLOCKS = [
        'kpis' => 'Chiffres clés (à encaisser, devis en attente, encaissé)',
        'activity' => 'Activité de la période',
        'todo' => 'À faire (factures en retard, devis sans réponse)',
        'planning' => 'Prochains rendez-vous et chantiers',
        'shortcuts' => 'Raccourcis (nouveau devis, rendez-vous…)',
        'payments' => 'Derniers paiements',
    ];

    public const DEFAULT_HOME = ['kpis', 'planning', 'todo', 'activity', 'payments'];

    /** @return list<string> */
    public static function bottom(): array
    {
        $keys = array_values(array_filter((array) app(Settings::class)->get('layout.bottom_nav', self::DEFAULT_BOTTOM), fn ($k) => isset(self::ITEMS[$k])));

        return count($keys) === 3 ? $keys : self::DEFAULT_BOTTOM;
    }

    /** @return list<string> */
    public static function home(): array
    {
        $keys = array_values(array_unique(array_filter((array) app(Settings::class)->get('layout.home_blocks', self::DEFAULT_HOME), fn ($k) => isset(self::HOME_BLOCKS[$k]))));

        return $keys ?: self::DEFAULT_HOME;
    }

    public static function isActive(string $key): bool
    {
        return isset(self::ITEMS[$key]) && request()->routeIs(...self::ITEMS[$key][3]);
    }
}

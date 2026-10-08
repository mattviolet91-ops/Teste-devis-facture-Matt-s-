<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;

/**
 * Bouton « Retour » en haut des pages : l'application installée sur le téléphone
 * n'a pas de flèche retour. Les pages principales (listes, accueil, réglages)
 * n'en ont pas ; les autres renvoient vers leur page parente. Le script revient
 * plutôt à la page précédente quand c'est une page de l'application (et pas un formulaire).
 */
final class BackLink
{
    /** Pages principales : pas de bouton retour. */
    private const TOP = [
        'dashboard', 'clients.index', 'quotes.index', 'invoices.index', 'requests.index', 'planning.index', 'payments.index', 'expenses.index',
        'reminders.index', 'photos.index', 'maintenance.index', 'statistics', 'catalog.index', 'emails.index', 'reviews.index',
        'search', 'trash.index', 'archives.index', 'documents',
        // Signature sur place : écran tendu au client, avec son propre lien de retour.
        'quotes.on-site',
        // Espace Argent : onglets et écrans du verrou.
        'money.dashboard', 'money.transactions.index', 'money.accounts.index', 'money.categories.index', 'money.goals.index',
        'money.recurrings.index', 'money.reports.index', 'money.settings', 'money.setup', 'money.unlock', 'money.forgot',
    ];

    public static function for(Request $request): ?string
    {
        $route = $request->route();
        $name = $route instanceof Route ? (string) $route->getName() : '';
        if ($name === '' || in_array($name, self::TOP, true) || str_starts_with($name, 'settings.')) {
            return null;
        }

        $param = fn (string $key) => $route->parameter($key);
        $id = fn ($model) => is_object($model) ? $model->getKey() : $model;

        return match (true) {
            in_array($name, ['clients.show', 'clients.create', 'clients.import', 'clients.import.preview'], true) => route('clients.index'),
            $name === 'clients.edit' => route('clients.show', $id($param('client'))),
            $name === 'worksites.create' => route('clients.show', $id($param('client'))),
            $name === 'worksites.edit', $name === 'photos.worksite' => self::worksiteClient($param('worksite')),
            in_array($name, ['quotes.show', 'quotes.create', 'quotes.express', 'quotes.express.preview', 'quotes.express.store'], true) => route('quotes.index'),
            in_array($name, ['quotes.edit', 'quotes.signature'], true) => route('quotes.show', $id($param('quote'))),
            in_array($name, ['invoices.show', 'invoices.create'], true) => route('invoices.index'),
            in_array($name, ['expenses.project', 'expenses.create', 'expenses.general'], true) => route('expenses.index'),
            in_array($name, ['invoices.edit', 'reminders.show'], true) => route('invoices.show', $id($param('invoice'))),
            in_array($name, ['planning.show', 'planning.create'], true) => route('planning.index'),
            $name === 'planning.edit' => route('planning.show', $id($param('intervention'))),
            $name === 'reports.show' => self::reportClient($param('report')),
            $name === 'reports.edit' => route('reports.show', $id($param('report'))),
            $name === 'requests.show' => route('requests.index'),
            $name === 'maintenance.show' => route('maintenance.index'),
            $name === 'reviews.show' => route('reviews.index'),
            $name === 'emails.show' => route('emails.index'),
            $name === 'archives.show' => route('archives.index'),
            in_array($name, ['catalog.create', 'catalog.edit'], true) => route('catalog.index'),
            $name === 'money.accounts.show' => route('money.accounts.index'),
            $name === 'money.reports.show' => route('money.reports.index'),
            in_array($name, ['money.transactions.edit', 'money.import.create', 'money.import.show'], true) => route('money.transactions.index'),
            str_starts_with($name, 'money.') => route('money.dashboard'),
            default => route('dashboard'),
        };
    }

    private static function worksiteClient(mixed $worksite): string
    {
        return is_object($worksite) && $worksite->client_id ? route('clients.show', $worksite->client_id) : route('clients.index');
    }

    private static function reportClient(mixed $report): string
    {
        return is_object($report) && $report->client_id ? route('clients.show', $report->client_id) : route('clients.index');
    }
}

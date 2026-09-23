<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Quote;
use App\Models\Worksite;
use App\Services\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Corbeille : les éléments supprimés restent récupérables 30 jours, puis la
 * commande planifiée app:purge-trash les efface définitivement.
 */
class TrashController extends Controller
{
    public const RETENTION_DAYS = 30;

    public function index(): View
    {
        return view('trash.index', [
            'clients' => Client::onlyTrashed()->latest('deleted_at')->get(),
            // Chantiers supprimés seuls (ceux d'un client supprimé reviennent avec lui).
            'worksites' => Worksite::onlyTrashed()->with('client')->whereHas('client')->latest('deleted_at')->get(),
            'quotes' => Quote::onlyTrashed()->with('client')->latest('deleted_at')->get(),
            'retention' => self::RETENTION_DAYS,
        ]);
    }

    public function restoreClient(int $id): RedirectResponse
    {
        $client = Client::onlyTrashed()->findOrFail($id);
        $client->restore();
        ActivityLogger::log('client.restored', "Client restauré : {$client->displayName()}", $client);

        return redirect()->route('clients.show', $client)->with('status', 'Client restauré.');
    }

    public function restoreQuote(int $id): RedirectResponse
    {
        $quote = Quote::onlyTrashed()->findOrFail($id);
        abort_unless($quote->client()->whereNull('deleted_at')->exists(), 409, 'Restaurez d\'abord le client.');
        $quote->restore();
        ActivityLogger::log('quote.restored', 'Brouillon de devis restauré', $quote);

        return redirect()->route('quotes.show', $quote)->with('status', 'Brouillon restauré.');
    }

    public function restoreWorksite(int $id): RedirectResponse
    {
        $worksite = Worksite::onlyTrashed()->whereHas('client')->findOrFail($id);
        $worksite->restore();
        ActivityLogger::log('worksite.restored', "Chantier restauré : {$worksite->fullAddress()}", $worksite);

        return redirect()->route('clients.show', $worksite->client_id)->with('status', 'Chantier restauré.');
    }
}

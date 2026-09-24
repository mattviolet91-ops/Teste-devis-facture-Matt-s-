<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Quote;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

/** Mode hors connexion : page de secours, jeton pour les envois différés, pages à garder sur le téléphone. */
class OfflineController extends Controller
{
    public function page(): View
    {
        return view('offline');
    }

    /** Jeton de sécurité à jour, pour envoyer les formulaires remplis sans réseau. */
    public function token(): JsonResponse
    {
        return response()->json(['token' => csrf_token()])->header('Cache-Control', 'no-store');
    }

    /** Pages à télécharger pour les consulter sans réseau (sur un toit, en cave…). */
    public function pages(): JsonResponse
    {
        $urls = [
            route('dashboard'), route('clients.index'), route('quotes.index'), route('invoices.index'),
            route('planning.index'), route('planning.index', ['date' => today()->addWeek()->toDateString()]),
            route('quotes.create'), route('invoices.create'), route('clients.create'), route('planning.create'),
            route('catalog.index'), route('maintenance.index'), route('reminders.index'), route('payments.index'),
        ];

        $clients = Client::query()->latest('updated_at')->pluck('id');
        foreach ($clients as $id) {
            $urls[] = route('clients.show', $id);
        }
        foreach (Quote::query()->latest('updated_at')->limit(60)->pluck('id') as $id) {
            $urls[] = route('quotes.show', $id);
        }
        foreach (Invoice::query()->latest('updated_at')->limit(60)->pluck('id') as $id) {
            $urls[] = route('invoices.show', $id);
        }
        for ($page = 2; $page <= (int) ceil($clients->count() / 25); $page++) {
            $urls[] = route('clients.index', ['page' => $page]);
        }

        return response()->json(['urls' => array_values(array_unique($urls))])->header('Cache-Control', 'no-store');
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\QuoteRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Demandes de devis reçues du site internet. */
class QuoteRequestController extends Controller
{
    public function index(Request $request): View
    {
        $request->session()->put('documents_tab', 'requests');

        return view('requests.index', [
            'new' => QuoteRequest::query()->where('status', 'new')->whereHas('client')->with(['client', 'worksite'])->latest('id')->get(),
            'handled' => QuoteRequest::query()->where('status', 'handled')->whereHas('client')->with('client')->latest('updated_at')->limit(30)->get(),
            'formUrl' => rtrim((string) config('entreprise.client_url'), '/').route('portal.request', [], false),
        ]);
    }

    public function show(QuoteRequest $quoteRequest): View
    {
        abort_unless($quoteRequest->client, 404);

        return view('requests.show', [
            'request' => $quoteRequest->load(['client.photos', 'worksite']),
        ]);
    }

    public function toggle(QuoteRequest $quoteRequest): RedirectResponse
    {
        $quoteRequest->forceFill(['status' => $quoteRequest->status === 'new' ? 'handled' : 'new'])->save();

        return redirect()->route('requests.index')->with('status', $quoteRequest->status === 'handled' ? 'Demande marquée comme traitée.' : 'Demande remise à traiter.');
    }
}

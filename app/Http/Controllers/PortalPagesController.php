<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\MyposGateway;
use App\Support\Search;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Pages publiques de l'adresse client (ex. devis.domaine.fr) : accueil, conditions
 * générales de vente, remboursement et annulation, mentions légales, paiement en ligne.
 * Exigées par les réseaux de cartes pour le paiement par carte (myPOS).
 */
class PortalPagesController extends Controller
{
    public function home(MyposGateway $mypos): View
    {
        return view('portal.pages.home', ['cardPayments' => $mypos->isEnabled()]);
    }

    public function cgv(): View
    {
        return view('portal.pages.text', ['title' => 'Conditions générales de vente', 'setting' => 'pdf.cgv']);
    }

    public function refunds(): View
    {
        return view('portal.pages.text', ['title' => 'Remboursement et annulation', 'setting' => 'pdf.refund_policy']);
    }

    public function legal(): View
    {
        return view('portal.pages.legal');
    }

    public function payment(MyposGateway $mypos): View
    {
        return view('portal.pages.payment', ['cardPayments' => $mypos->isEnabled()]);
    }

    /**
     * « Régler une facture » : numéro de facture + email (ou code postal) du client,
     * puis la page de la facture avec son bouton de paiement. Message neutre en cas d'échec.
     */
    public function findInvoice(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'number' => ['required', 'string', 'max:40'],
            'check' => ['required', 'string', 'max:160'],
        ], [], ['number' => 'numéro de facture', 'check' => 'email ou code postal']);

        $invoice = Invoice::query()
            ->with('client')
            ->where('number', strtoupper(trim($data['number'])))
            ->whereNotNull('public_token')
            ->where('kind', '!=', 'credit')
            ->first();

        $check = Search::normalize(str_replace(' ', '', $data['check']));
        $client = $invoice?->client;
        $matches = $client && $check !== '' && (
            ($client->email && Search::normalize($client->email) === $check)
            || ($client->postal_code && str_replace(' ', '', $client->postal_code) === $check)
            || ($invoice->worksite?->postal_code && str_replace(' ', '', $invoice->worksite->postal_code) === $check)
        );

        if (! $matches) {
            return back()->withInput()->withErrors(['number' => 'Facture introuvable : vérifiez le numéro (ex. FAC-2026-0012) et l\'email ou le code postal indiqués sur la facture.']);
        }

        return redirect()->route('portal.invoice', $invoice->public_token);
    }
}

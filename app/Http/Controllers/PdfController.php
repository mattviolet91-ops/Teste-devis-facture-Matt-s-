<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Quote;
use App\Services\ActivityLogger;
use App\Services\PdfService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Affichage ou téléchargement du PDF d'un devis, d'une facture ou d'un avoir. */
class PdfController extends Controller
{
    public function __construct(private readonly PdfService $pdf) {}

    public function quote(Request $request, Quote $quote): Response
    {
        return $this->respond($request, $quote);
    }

    public function invoice(Request $request, Invoice $invoice): Response
    {
        return $this->respond($request, $invoice);
    }

    /** Nouvelle version du PDF d'une facture envoyée (avec les règlements reçus). */
    public function refreshInvoice(Invoice $invoice): RedirectResponse
    {
        abort_if($invoice->isDraft(), 404);
        $snapshot = $this->pdf->regenerate($invoice);
        ActivityLogger::log('invoice.pdf_refreshed', "PDF de la facture {$invoice->number} mis à jour", $invoice);

        return back()->with('status', $snapshot
            ? 'PDF mis à jour : il affiche les règlements reçus. Le PDF d\'origine reste disponible.'
            : 'Le PDF n\'a pas pu être mis à jour. Réessayez.');
    }

    private function respond(Request $request, Quote|Invoice $document): Response
    {
        $filename = $this->pdf->filename($document);
        $disposition = $request->boolean('telecharger') ? 'attachment' : 'inline';

        return response($this->pdf->content($document, $request->query('version') === 'origine'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

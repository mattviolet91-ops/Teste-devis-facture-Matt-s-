<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Quote;
use App\Services\PdfService;
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

    private function respond(Request $request, Quote|Invoice $document): Response
    {
        $filename = $this->pdf->filename($document);
        $disposition = $request->boolean('telecharger') ? 'attachment' : 'inline';

        return response($this->pdf->content($document), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Quote;
use App\Services\ActivityLogger;
use App\Services\ScanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Documents rangés sur une fiche client (PDF, images, tableurs…). */
class AttachmentController extends Controller
{
    public function store(Request $request, Client $client): RedirectResponse
    {
        $request->validate([
            'files' => ['required', 'array', 'max:10'],
            'files.*' => ['file', 'max:15360', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx,odt,ods,txt'],
        ], [
            'files.*.mimes' => 'Format non accepté (PDF, images, Word, Excel ou texte).',
            'files.*.max' => 'Fichier trop lourd (15 Mo maximum).',
        ]);

        foreach ($request->file('files') as $file) {
            $name = Str::limit($file->getClientOriginalName(), 150, '');
            $path = $file->store('documents/clients/'.$client->id, 'local');

            $attachment = new Attachment(['name' => $name]);
            $attachment->client()->associate($client);
            $attachment->forceFill([
                'path' => $path,
                'mime' => $file->getMimeType() ?: 'application/octet-stream',
                'size' => $file->getSize(),
                'created_by' => auth()->id(),
            ])->save();
        }

        ActivityLogger::log('attachment.added', count($request->file('files')).' document(s) ajouté(s)', $client);

        return back()->with('status', 'Document(s) ajouté(s).');
    }

    /** Documents d'un devis : scan (photos → un PDF) ou fichiers. */
    public function storeForQuote(Request $request, Quote $quote, ScanService $scans): RedirectResponse
    {
        return $this->storeOn($request, $scans, $quote->client, quote: $quote);
    }

    /** Documents d'une facture : scan (photos → un PDF) ou fichiers. */
    public function storeForInvoice(Request $request, Invoice $invoice, ScanService $scans): RedirectResponse
    {
        return $this->storeOn($request, $scans, $invoice->client, invoice: $invoice);
    }

    private function storeOn(Request $request, ScanService $scans, ?Client $client, ?Quote $quote = null, ?Invoice $invoice = null): RedirectResponse
    {
        abort_unless($client, 404);
        $document = $quote ?? $invoice;
        $label = $quote ? 'devis '.($quote->number ?? '(brouillon)') : mb_strtolower($invoice->kindLabel()).' '.($invoice->number ?? '(brouillon)');
        $back = ($quote ? route('quotes.show', $quote) : route('invoices.show', $invoice)).'#documents';

        if ($request->input('mode') === 'scan') {
            $data = $request->validate([
                'scan_name' => ['nullable', 'string', 'max:120'],
                'pages' => ['required', 'array', 'min:1', 'max:20'],
                'pages.*' => ['file', 'max:15360', 'mimes:jpg,jpeg,png,webp'],
            ], [
                'pages.required' => 'Prenez au moins une photo du document.',
                'pages.*.mimes' => 'Le scan accepte des photos (JPEG, PNG). Pour un PDF ou un autre fichier, utilisez « Ajouter un fichier ».',
                'pages.*.max' => 'Photo trop lourde (15 Mo maximum).',
            ]);
            $title = trim((string) ($data['scan_name'] ?? '')) ?: 'Scan du '.now()->format('d/m/Y à H\hi');
            try {
                $pdf = $scans->toPdf($request->file('pages'), $title);
            } catch (\InvalidArgumentException) {
                return redirect()->to($back)->withErrors(['pages' => 'Une des photos n\'a pas pu être lue. Reprenez-la.']);
            }
            $path = 'documents/clients/'.$client->id.'/'.Str::random(40).'.pdf';
            Storage::disk('local')->put($path, $pdf);
            $this->record($client, $quote, $invoice, Str::limit(Str::finish($title, '.pdf'), 150, ''), $path, 'application/pdf', strlen($pdf));
            ActivityLogger::log('attachment.added', "Document scanné ({$title}, ".count($request->file('pages')).' page(s)) ajouté au '.$label, $document);

            return redirect()->to($back)->with('status', 'Document scanné et ajouté.');
        }

        $request->validate([
            'files' => ['required', 'array', 'max:10'],
            'files.*' => ['file', 'max:15360', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx,odt,ods,txt'],
        ], [
            'files.*.mimes' => 'Format non accepté (PDF, images, Word, Excel ou texte).',
            'files.*.max' => 'Fichier trop lourd (15 Mo maximum).',
        ]);
        foreach ($request->file('files') as $file) {
            $path = $file->store('documents/clients/'.$client->id, 'local');
            $this->record($client, $quote, $invoice, Str::limit($file->getClientOriginalName(), 150, ''), $path, $file->getMimeType() ?: 'application/octet-stream', (int) $file->getSize());
        }
        ActivityLogger::log('attachment.added', count($request->file('files')).' document(s) ajouté(s) au '.$label, $document);

        return redirect()->to($back)->with('status', 'Document(s) ajouté(s).');
    }

    private function record(Client $client, ?Quote $quote, ?Invoice $invoice, string $name, string $path, string $mime, int $size): void
    {
        $attachment = new Attachment(['name' => $name]);
        $attachment->client()->associate($client);
        $attachment->forceFill([
            'quote_id' => $quote?->id,
            'invoice_id' => $invoice?->id,
            'path' => $path,
            'mime' => $mime,
            'size' => $size,
            'created_by' => auth()->id(),
        ])->save();
    }

    public function show(Request $request, Attachment $attachment): Response
    {
        abort_unless(Storage::disk('local')->exists($attachment->path), 404);
        // Documents d'une facture : réservés au gérant, comme les factures.
        abort_if($attachment->invoice_id && ! $request->user()?->isAdmin(), 403);
        $disposition = $request->boolean('telecharger') ? 'attachment' : 'inline';

        return Storage::disk('local')->response($attachment->path, $attachment->name, [
            'Content-Type' => $attachment->mime,
            'X-Content-Type-Options' => 'nosniff',
            // Un document envoyé par un tiers ne doit jamais s'exécuter dans l'application.
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
        ], $disposition);
    }

    public function destroy(Attachment $attachment): RedirectResponse
    {
        Storage::disk('local')->delete($attachment->path);
        $attachment->delete();
        ActivityLogger::log('attachment.deleted', "Document supprimé : {$attachment->name}", $attachment->client);

        return back()->with('status', 'Document supprimé.');
    }
}
